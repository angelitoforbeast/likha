import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { checkFileAccess } from '../../.claude/hooks/guard-files.mjs';
import { runHook } from '../support/run-hook.mjs';

describe('checkFileAccess', () => {
  for (const [tool, file] of [
    ['Read', '.env'],
    ['Read', '/Users/x/mira/.env'],
    ['Read', 'config/.env.local'],
    ['Edit', '.env.production'],
    ['Write', '.env.test'],
    ['Grep', '.env.development'],
  ]) {
    it(`blocks ${tool} of secret env file ${file}`, () => {
      assert.equal(checkFileAccess(tool, file).decision, 'block');
    });
  }

  for (const [tool, file] of [
    ['Read', '.env.example'],
    ['Edit', '/Users/x/mira/.env.example'],
    ['Read', '.envrc'],
    ['Read', 'src/env.ts'],
  ]) {
    it(`allows ${tool} of ${file}`, () => {
      assert.equal(checkFileAccess(tool, file).decision, 'allow');
    });
  }

  const existing = { exists: () => true };
  const missing = { exists: () => false };

  for (const tool of ['Edit', 'Write', 'MultiEdit']) {
    it(`blocks ${tool} of an existing HANDOFF.md`, () => {
      assert.equal(
        checkFileAccess(tool, 'handoff/001-memory-service/HANDOFF.md', existing).decision,
        'block',
      );
    });
  }

  // dev-kit 0.8.0 (suggestion ce585eb5): the worker saves Mira's handoff text in its first
  // commit, so creating a HANDOFF.md that doesn't exist yet is allowed; changing one never is.
  it('allows Write creating a HANDOFF.md that does not exist yet', () => {
    assert.equal(checkFileAccess('Write', 'handoff/002-x/HANDOFF.md', missing).decision, 'allow');
  });

  for (const tool of ['Edit', 'MultiEdit']) {
    it(`blocks ${tool} of a HANDOFF.md even when it is missing`, () => {
      assert.equal(checkFileAccess(tool, 'handoff/002-x/HANDOFF.md', missing).decision, 'block');
    });
  }

  it('allows reading a HANDOFF.md', () => {
    assert.equal(
      checkFileAccess('Read', '/Users/x/mira/handoff/001-x/HANDOFF.md').decision,
      'allow',
    );
  });

  it('allows editing a RESULT.md', () => {
    assert.equal(
      checkFileAccess('Edit', 'handoff/001-x/RESULT.md').decision,
      'allow',
    );
  });

  it('allows editing package.json (Arnel, 2026-09-27: no prompts)', () => {
    assert.deepEqual(checkFileAccess('Edit', '/Users/x/mira/package.json'), {
      decision: 'allow',
    });
  });

  it('allows reading package.json', () => {
    assert.equal(checkFileAccess('Read', 'package.json').decision, 'allow');
  });

  it('gives a reason that points to .env.example when blocking env files', () => {
    const result = checkFileAccess('Read', '.env');
    assert.equal(result.decision, 'block');
    assert.ok(result.reason.includes('.env.example'));
  });
});

describe('guard-files.mjs as a hook', () => {
  it('exits 2 with the reason on stderr when blocking', async () => {
    const result = await runHook('guard-files.mjs', {
      tool_name: 'Read',
      tool_input: { file_path: '.env' },
    });
    assert.equal(result.code, 2);
    assert.ok(result.stderr.includes('Blocked by project guard'));
  });

  it('prints an ask decision as JSON and exits 0', async () => {
    const result = await runHook('guard-files.mjs', {
      tool_name: 'Edit',
      tool_input: { file_path: '.claude/settings.json' },
    });
    assert.equal(result.code, 0);
    const parsed = JSON.parse(result.stdout);
    assert.equal(parsed.hookSpecificOutput.hookEventName, 'PreToolUse');
    assert.equal(parsed.hookSpecificOutput.permissionDecision, 'ask');
  });

  it('exits 0 silently when allowing', async () => {
    const result = await runHook('guard-files.mjs', {
      tool_name: 'Read',
      tool_input: { file_path: 'README.md' },
    });
    assert.deepEqual(result, { code: 0, stdout: '', stderr: '' });
  });

  it('checks the Grep path field', async () => {
    const result = await runHook('guard-files.mjs', {
      tool_name: 'Grep',
      tool_input: { pattern: 'KEY', path: '.env' },
    });
    assert.equal(result.code, 2);
  });

  it('fails closed on input that is not JSON', async () => {
    const result = await runHook('guard-files.mjs', 'not json');
    assert.equal(result.code, 2);
    assert.ok(result.stderr.includes('not valid JSON'));
  });
});

describe('checkFileAccess env-name matching', () => {
  for (const file of ['.ENV', '.Env.Local', 'config/.ENV.production']) {
    it(`blocks case variant ${file}`, () => {
      assert.equal(checkFileAccess('Read', file).decision, 'block');
    });
  }

  for (const file of ['.env-local', '.env_old', '.env~', '.env.', '.env2']) {
    it(`blocks .env followed by a non-letter: ${file}`, () => {
      assert.equal(checkFileAccess('Read', file).decision, 'block');
    });
  }

  for (const file of ['.environment', '.envrc', '.ENV.EXAMPLE', 'config/.Env.Example']) {
    it(`allows ${file}`, () => {
      assert.equal(checkFileAccess('Read', file).decision, 'allow');
    });
  }

  for (const file of ['config/.env.d/secret', '.env/foo', '/Users/x/mira/.env.local/x']) {
    it(`blocks a file inside a secret env directory: ${file}`, () => {
      assert.equal(checkFileAccess('Read', file).decision, 'block');
    });
  }
});

describe('checkFileAccess case-insensitive path checks', () => {
  for (const file of ['handoff/001/handoff.md', 'HANDOFF/001/HANDOFF.MD']) {
    it(`blocks editing ${file}`, () => {
      assert.equal(checkFileAccess('Edit', file).decision, 'block');
    });
  }

  it('allows editing Package.JSON', () => {
    assert.equal(checkFileAccess('Write', 'Package.JSON').decision, 'allow');
  });
});

describe('checkFileAccess guards its own guards', () => {
  const guarded = [
    '.claude/settings.json',
    '.claude/settings.local.json',
    '/Users/x/mira/.claude/settings.json',
    '.claude/hooks/guard-bash.mjs',
    '.claude/hooks/lib/hook-io.mjs',
    '/Users/x/mira/.claude/hooks/format-on-edit.mjs',
    '.CLAUDE/Hooks/guard-files.mjs',
    '.Claude/Settings.JSON',
    '.claude\\hooks\\guard-bash.mjs',
    'src/../.claude/hooks/new.mjs',
    './.claude/settings.local.json',
  ];

  for (const [tool, file] of ['Edit', 'Write', 'MultiEdit'].flatMap((tool) =>
    guarded.map((file) => [tool, file]),
  )) {
    it(`${tool} of ${file} asks`, () => {
      const result = checkFileAccess(tool, file);
      assert.deepEqual(Object.keys(result).sort(), ['decision', 'reason']);
      assert.equal(result.decision, 'ask');
      assert.equal(typeof result.reason, 'string');
      assert.ok(result.reason.includes('guard'));
    });
  }

  for (const file of guarded) {
    it(`Read of ${file} is allowed`, () => {
      assert.deepEqual(checkFileAccess('Read', file), { decision: 'allow' });
    });
  }

  for (const file of [
    '.claude/agents/developer.md',
    '.claude/rules/tests.md',
    '.claude/settings.json.bak',
    'test/hooks/guard-bash.test.ts',
    'docs/hooks/readme.md',
  ]) {
    it(`allows editing ${file}`, () => {
      assert.deepEqual(checkFileAccess('Edit', file), { decision: 'allow' });
    });
  }
});

describe('guard-files.mjs Grep glob', () => {
  for (const glob of ['.env', '**/.env*', 'config/.env.local', '.ENV']) {
    it(`blocks a Grep whose glob targets secret env files: ${glob}`, async () => {
      const result = await runHook('guard-files.mjs', {
        tool_name: 'Grep',
        tool_input: { pattern: 'KEY', path: '.', glob },
      });
      assert.equal(result.code, 2);
    });
  }

  it('blocks a Grep with a secret glob and no path', async () => {
    const result = await runHook('guard-files.mjs', {
      tool_name: 'Grep',
      tool_input: { pattern: 'KEY', glob: '.env' },
    });
    assert.equal(result.code, 2);
  });

  for (const glob of ['*.ts', '.env.example', '**/.envrc', 'src/**/*.mjs']) {
    it(`allows a Grep with glob ${glob}`, async () => {
      const result = await runHook('guard-files.mjs', {
        tool_name: 'Grep',
        tool_input: { pattern: 'KEY', path: '.', glob },
      });
      assert.deepEqual(result, { code: 0, stdout: '', stderr: '' });
    });
  }
});
