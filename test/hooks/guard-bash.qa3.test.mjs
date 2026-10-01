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

describe('command names match in any case and by path (QA round 3)', () => {
  for (const command of [
    'FIND . -delete',
    'Find src -delete',
    '/usr/bin/find . -name x -delete',
    'SUDO rm -rf dist',
    '/usr/bin/sudo rm -rf dist',
    'Sudo /bin/rm -rf node_modules',
    '/opt/homebrew/bin/gh pr merge 3',
    'GH pr merge',
    '\\gh pr merge',
    'gh -R a/b pr merge 3',
    'gh --repo a/b pr merge',
    'Git push -f',
    'GIT push origin main',
    '/usr/bin/git push --force',
    '\\git reset --hard',
    '/opt/homebrew/bin/git clean -fd',
    'GIT commit -n -m x',
    'Git push --all',
    'RM -rf src',
    'Rm -r src',
    '/BIN/RM -rf src',
    '\\RM -rf src',
  ]) {
    it(`blocks ${JSON.stringify(command)}`, () => blocks(command));
  }
});

describe('git global options are skipped before the subcommand (QA round 3)', () => {
  for (const command of [
    'git -C push push --force',
    'git -C reset reset --hard',
    'git -C rm rm -r src',
    'git --git-dir=x push --force',
    'git --git-dir x push --force',
    'git --work-tree=. reset --hard',
    'git --work-tree . reset --hard',
    'git --namespace ns push origin main',
    'git -c push.default=x push -f',
    'git -C a -C b push origin develop',
    'git --bare push --force',
    'git --no-pager push -f',
    'git -P reset --hard',
    'git --unknown-opt push --force',
    'git --exec-path=/x push --force',
  ]) {
    it(`blocks ${JSON.stringify(command)}`, () => blocks(command));
  }

  for (const command of [
    'git -C push status',
    'git -C reset log',
    'git -C push log',
    'git -C x status',
    'git --git-dir=.git log',
    'git -C ../mira status',
    'git -C',
    'git -C x rm -r src',
    'git --git-dir x rm -r src',
    'git --work-tree x rm -r --cached src',
  ]) {
    it(`allows ${JSON.stringify(command)}`, () => allows(command));
  }
});

describe('redirects are not rm targets (QA round 3)', () => {
  for (const command of [
    'rm -rf dist 2> /dev/null',
    'rm -rf dist 2>/dev/null',
    'rm -rf node_modules &> /dev/null',
    'rm -rf node_modules >> log.txt 2>&1',
    'rm -rf coverage > /dev/null 2>&1',
    'rm -rf dist 1>&2',
    'rm -rf dist < /dev/null',
    'rm -rf node_modules 2>/dev/null || true',
  ]) {
    it(`allows ${JSON.stringify(command)}`, () => allows(command));
  }

  for (const command of [
    'rm -rf src > /tmp/x',
    'rm -rf > /tmp/x src',
    'rm -rf 2>/dev/null',
    'rm -rf 2>/dev/null src',
    'rm -rf node_modules 2>/dev/null src',
    'rm -rf node_modules > x src',
    'rm -rf src 2>&1 | tail',
    'sudo rm -rf dist 2>/dev/null',
  ]) {
    it(`still blocks ${JSON.stringify(command)}`, () => blocks(command));
  }
});

describe('everyday commands still pass (QA round 3)', () => {
  for (const command of [
    'npm test 2>&1 | tail -5',
    'npx vitest run &> out.txt',
    'git status',
    'git log --oneline -5',
    'git diff develop...HEAD',
    'gh pr create --base develop --title "feat: x" --body-file b.md',
    'gh pr view 12',
    'gh pr list',
    'gh pr checks',
    'git commit -m "feat: x"',
    'git commit --amend --no-edit',
    'git log -n 5',
    'npm run lint:fix',
    'npx vitest run test/hooks',
    'rm -f tmp.txt',
    'ls -la',
    'cat src/git',
    'ls git',
    'cd git && ls',
    'npm install simple-git',
    'which find',
    'find . -name "*.ts"',
    'find . -type d -name node_modules -prune',
    'sudo -v',
    'echo sudo',
    'npm run find-deletes',
    'git worktree add ../x -b feat/x develop',
    'git switch -c feat/y',
    'mkdir -p dist && cp a dist/',
  ]) {
    it(`allows ${JSON.stringify(command)}`, () => allows(command));
  }

  for (const command of ['git push -u origin feat/x']) {
    it(`allows ${JSON.stringify(command)}`, () => {
      assert.equal(checkCommand(command).decision, 'allow');
    });
  }
  it('blocks gh pr merge 1 --squash (no merges by workers)', () => {
    assert.equal(checkCommand('gh pr merge 1 --squash').decision, 'block');
  });
});

describe('guard-bash.mjs wiring for normalised names (QA round 3)', () => {
  for (const [label, command, reason] of [
    ['a gh path', '/opt/homebrew/bin/gh pr merge 3', /gh pr merge/],
    ['upper-case find', 'FIND . -delete', /find -delete/],
    ['git --git-dir', 'git --git-dir x push --force', /force/],
  ]) {
    it(`blocks ${label} with exit 2`, async () => {
      const result = await runHook('guard-bash.mjs', {
        tool_name: 'Bash',
        tool_input: { command },
      });
      assert.equal(result.code, 2);
      assert.match(result.stderr, reason);
    });
  }

  it('allows a redirected cleanup silently', async () => {
    const result = await runHook('guard-bash.mjs', {
      tool_name: 'Bash',
      tool_input: { command: 'rm -rf dist coverage 2>/dev/null' },
    });
    assert.deepEqual(result, { code: 0, stdout: '', stderr: '' });
  });
});
