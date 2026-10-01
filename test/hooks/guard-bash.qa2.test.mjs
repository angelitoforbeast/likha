import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { checkCommand } from '../../.claude/hooks/guard-bash.mjs';
import { runHook } from '../support/run-hook.mjs';

const blocks = (command) => {
  assert.equal(checkCommand(command).decision, 'block');
};
const allows = (command) => {
  assert.deepEqual(checkCommand(command), { decision: 'allow' });
};

describe('ruling 4: a lone & separates commands (QA round 2)', () => {
  for (const command of [
    'sleep 1 & git push --force',
    'npm run dev & git push origin main',
    'git push origin main&',
    'git push origin main &',
    'git push origin main &>/dev/null',
    'git push origin develop 2>&1',
    'npm test & rm -rf src',
    'npm test&rm -rf src',
    'npm test &cat .env',
    'npm run dev & git commit -n -m x',
  ]) {
    it(`blocks ${JSON.stringify(command)}`, () => blocks(command));
  }

  for (const command of [
    'npm test 2>&1 | tail -5',
    'npx vitest run &> out.txt',
    'npx vitest run &>> out.txt',
    'npm run build >&2',
    'npm test 2>&1 >/dev/null',
    'npm test |& tee log',
    'cat <&3',
    'curl "https://example.test/?a=1&b=2"',
    'git commit -m "feat: a & b"',
    'npm run dev & npm test',
    'npm run dev &',
    'rm -rf dist &',
    'rm -rf node_modules & npm ci',
    'rm -rf dist || true',
  ]) {
    it(`allows ${JSON.stringify(command)}`, () => allows(command));
  }
});

describe('ruling 5: git rm is excluded from the recursive-rm rule (QA round 2)', () => {
  for (const command of [
    'git rm -r --cached src',
    'git rm -rf src',
    'git -C ../x rm -r src',
    'git -c core.quotepath=off rm -r src',
  ]) {
    it(`allows ${JSON.stringify(command)}`, () => allows(command));
  }

  for (const command of [
    'git rm -r --cached src && rm -rf lib',
    'git rm -r --cached src; rm -rf lib',
    'git rm -r x & rm -rf lib',
    'git rm -r --cached src rm -rf lib',
    'git rm -r src $(rm -rf lib)',
    'rm -rf lib && git rm -r lib',
    'echo git rm; rm -rf src',
    'git status && rm -rf src',
    'git log rm -rf src',
  ]) {
    it(`still blocks a plain rm beside it: ${JSON.stringify(command)}`, () =>
      blocks(command));
  }
});

describe('ruling 3: rm is detected by base name (QA round 2)', () => {
  for (const command of [
    '/bin/rm -rf src',
    '\\rm -rf src',
    '/usr/bin/rm -r src',
    './rm -rf src',
    'sudo /bin/rm -rf dist',
    'xargs /bin/rm -rf',
    'bash -c "/bin/rm -rf src"',
  ]) {
    it(`blocks ${JSON.stringify(command)}`, () => blocks(command));
  }

  for (const command of [
    '/bin/rm -rf node_modules',
    '\\rm -rf dist',
    '/bin/rm -rf dist coverage',
  ]) {
    it(`keeps the allowlist for ${JSON.stringify(command)}`, () => allows(command));
  }

  for (const command of [
    'npm run rm-cache',
    './scripts/rm.sh -r src',
    'npm rm lodash',
    'docker rm -f web',
    'grep -rn rm src',
    'grep -r "rm" src',
    'man rm',
    'which rm',
    'ls -R form',
    'ls -r src/rm',
    'cat bin/farm -r x',
  ]) {
    it(`does not treat ${JSON.stringify(command)} as rm`, () => allows(command));
  }
});

describe('ruling 1: git commit -n and short flags containing n (QA round 2)', () => {
  for (const command of [
    'git commit -n -m x',
    'git commit -nm x',
    'git commit -anm x',
    'git commit -m x -n',
    'git -C ../x commit -n',
    'bash -c "git commit -n -m x"',
    'npm test && git commit -n -m x',
  ]) {
    it(`blocks ${JSON.stringify(command)}`, () => blocks(command));
  }

  for (const command of [
    'git commit -m "feat: x"',
    'git commit --amend --no-edit',
    'git commit --no-edit',
    'git commit -am "feat: x"',
    'git commit -s -m "x"',
    'git commit -m "feat: x" -S',
    'git commit -v',
    'git commit --fixup HEAD',
    'git log -n 5',
    'git log -n5 --oneline',
    'git show -n 1',
    'git rev-list -n 1 HEAD',
    'git revert -n HEAD',
    'git cherry-pick -n abc123',
    'git merge --no-commit develop',
    'git commit -m "feat: x" && git log -n 5',
  ]) {
    it(`allows ${JSON.stringify(command)}`, () => allows(command));
  }
});

describe('F1 fix: --no-verify with any trailing punctuation (QA round 2)', () => {
  for (const command of [
    'git commit -m x --no-verify)',
    "git commit -m x --no-verify'",
    'git commit -m x "--no-verify"',
    'git commit --no-verify=yes',
    'git push --no-verify origin feat/x',
  ]) {
    it(`blocks ${JSON.stringify(command)}`, () => blocks(command));
  }
});

describe('ruling 2: git push --all / --mirror (QA round 2)', () => {
  for (const command of [
    'git push --all',
    'git push origin --all',
    'git push --mirror',
    'git push -u --all origin',
    'git push --all --dry-run',
    'git push "--all"',
    'git -C ../x push --mirror',
    'npm test && git push --all',
  ]) {
    it(`blocks ${JSON.stringify(command)}`, () => blocks(command));
  }

  for (const command of [
    'git push --tags',
    'git push --follow-tags origin feat/x',
    'git push --atomic origin feat/x',
    'git push -u origin feat/all',
  ]) {
    it(`allows ${JSON.stringify(command)}`, () => {
      assert.equal(checkCommand(command).decision, 'allow');
    });
  }
});

describe('guard-bash.mjs wiring for the new rules (QA round 2)', () => {
  for (const [label, command, reason] of [
    [
      'background push',
      'npm run dev & git push origin main',
      /main or develop/,
    ],
    ['git commit -n', 'git commit -n -m x', /no-verify/],
    ['push --all', 'git push --all', /--all/],
    ['/bin/rm', '/bin/rm -rf src', /rm/],
  ]) {
    it(`blocks ${label} with exit 2 and its reason`, async () => {
      const result = await runHook('guard-bash.mjs', {
        tool_name: 'Bash',
        tool_input: { command },
      });
      assert.equal(result.code, 2);
      assert.match(result.stderr, reason);
    });
  }

  it('allows a redirected test run silently', async () => {
    const result = await runHook('guard-bash.mjs', {
      tool_name: 'Bash',
      tool_input: { command: 'npm test 2>&1 | tail -5' },
    });
    assert.deepEqual(result, { code: 0, stdout: '', stderr: '' });
  });
});
