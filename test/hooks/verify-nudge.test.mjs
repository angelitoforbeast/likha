import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { describe, it } from 'node:test';
import { changedSource, nudge, ranTests } from '../../.claude/hooks/verify-nudge.mjs';
import { runHook } from '../support/run-hook.mjs';

describe('changedSource', () => {
  it('keeps changed source files, drops docs, tests and agent memory', () => {
    const status = [
      ' M src/api/users.ts',
      '?? src/new.ts',
      ' M README.md',
      ' M docs/specs/x.md',
      ' M src/api/users.test.ts',
      ' M test/hooks/guard-bash.test.mjs',
      ' M .claude/agent-memory/backend-developer/notes.md',
      'R  old.ts -> lib/renamed.ts',
    ].join('\n');
    assert.deepEqual(changedSource(status), ['src/api/users.ts', 'src/new.ts', 'lib/renamed.ts']);
  });
});

describe('ranTests', () => {
  const line = (command) =>
    JSON.stringify({ message: { content: [{ type: 'tool_use', name: 'Bash', input: { command } }] } });

  for (const command of ['pnpm test', 'npm run test -- users', 'pnpm vitest run src/a.test.ts', "node --test 'test/hooks/*.test.mjs'", 'pnpm turbo run test', 'php artisan test', 'pytest -q']) {
    it(`sees a test run in: ${command}`, () => {
      assert.equal(ranTests(line('git status') + '\n' + line(command)), true);
    });
  }

  it('ignores commands that only mention tests, and non-Bash tools', () => {
    const read = JSON.stringify({ message: { content: [{ type: 'tool_use', name: 'Read', input: { file_path: 'a.test.ts' } }] } });
    assert.equal(ranTests([line('git add src/a.test.ts'), line('cat test/x'), read, 'not json'].join('\n')), false);
  });
});

describe('nudge', () => {
  const base = { event: 'SubagentStop', stopHookActive: false, changed: ['src/a.ts'], testRan: false };

  it('asks for a test run when source changed and no test ran', () => {
    const out = nudge(base);
    assert.equal(out.hookSpecificOutput.hookEventName, 'SubagentStop');
    assert.match(out.hookSpecificOutput.additionalContext, /src\/a\.ts/);
    assert.match(out.hookSpecificOutput.additionalContext, /## Stack/);
  });

  it('stays quiet when tests ran, nothing changed, or it already nudged once', () => {
    assert.equal(nudge({ ...base, testRan: true }), null);
    assert.equal(nudge({ ...base, changed: [] }), null);
    assert.equal(nudge({ ...base, stopHookActive: true }), null);
  });

  it('lists at most five files', () => {
    const changed = ['a', 'b', 'c', 'd', 'e', 'f', 'g'].map((f) => `src/${f}.ts`);
    const text = nudge({ ...base, changed }).hookSpecificOutput.additionalContext;
    assert.match(text, /src\/e\.ts/);
    assert.doesNotMatch(text, /src\/f\.ts/);
    assert.match(text, /and 2 more/);
  });
});

describe('verify-nudge hook', () => {
  const repo = () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'vn-'));
    execFileSync('git', ['init', '-q'], { cwd: dir });
    fs.writeFileSync(path.join(dir, 'app.ts'), 'x');
    return dir;
  };

  it('nudges a subagent that changed source without running tests', async () => {
    const dir = repo();
    const transcript = path.join(dir, 't.jsonl');
    fs.writeFileSync(transcript, '');
    const r = await runHook('verify-nudge.mjs', {
      hook_event_name: 'SubagentStop', stop_hook_active: false, cwd: dir, agent_transcript_path: transcript,
    });
    assert.equal(r.code, 0);
    assert.match(JSON.parse(r.stdout).hookSpecificOutput.additionalContext, /app\.ts/);
  });

  it('never fails the stop: bad input, missing transcript, not a git repo', async () => {
    for (const input of ['not json', { hook_event_name: 'Stop', cwd: os.tmpdir() }]) {
      const r = await runHook('verify-nudge.mjs', input);
      assert.equal(r.code, 0);
      assert.equal(r.stdout, '');
    }
  });
});
