import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { after, describe, it } from 'node:test';
import { checkFileAccess } from '../../.claude/hooks/guard-files.mjs';
import { runHook } from '../support/run-hook.mjs';

const ALL_FILE_TOOLS = ['Read', 'Edit', 'Write', 'MultiEdit', 'Grep'];
const WRITE_TOOLS = ['Edit', 'Write', 'MultiEdit'];

const SECRET_ENV_PATHS = [
  '.env',
  './.env',
  '../.env',
  '.env/',
  '/Users/x/mira/.env',
  'a/b/c/.env.local',
  '.env.development',
  '.env.production',
  '.env.test',
  '.env.local.bak',
  '.env.example.bak',
  '.env.staging',
  'C:\\Users\\x\\mira\\.env',
  'config\\.env.production',
];

const ENV_EXAMPLE_PATHS = [
  '.env.example',
  './.env.example',
  '../.env.example',
  '/Users/x/mira/.env.example',
  'nested/dir/.env.example',
  'C:\\Users\\x\\mira\\.env.example',
];

const NOT_ENV_PATHS = [
  '.envrc',
  '/Users/x/mira/.envrc',
  'nested/.envrc',
  'x.env',
  'env',
  'src/env.ts',
  'src/env-helpers/readme.md',
  'docs/environment.md',
];

describe('checkFileAccess: secret env files (QA)', () => {
  for (const tool of ALL_FILE_TOOLS) {
    for (const file of SECRET_ENV_PATHS) {
      it(`blocks ${tool} of ${file}`, () => {
        assert.equal(checkFileAccess(tool, file, { exists: () => true }).decision, 'block');
      });
    }
  }

  it('blocks env files regardless of tool name', () => {
    assert.equal(checkFileAccess('NotebookEdit', '.env').decision, 'block');
    assert.equal(
      checkFileAccess('SomeFutureTool', '/abs/.env.local').decision,
      'block',
    );
  });

  it('names .env.example in the block reason for every form', () => {
    for (const file of SECRET_ENV_PATHS) {
      const result = checkFileAccess('Read', file);
      assert.equal(result.decision, 'block');
      assert.ok(result.reason.includes('.env.example'));
    }
  });
});

describe('checkFileAccess: .env.example and look-alikes (QA)', () => {
  for (const tool of ALL_FILE_TOOLS) {
    for (const file of ENV_EXAMPLE_PATHS) {
      it(`allows ${tool} of ${file}`, () => {
        assert.equal(checkFileAccess(tool, file).decision, 'allow');
      });
    }

    for (const file of NOT_ENV_PATHS) {
      it(`allows ${tool} of non-env file ${file}`, () => {
        assert.equal(checkFileAccess(tool, file).decision, 'allow');
      });
    }
  }
});

describe('checkFileAccess: handoff/*/HANDOFF.md (QA)', () => {
  const handoffForms = [
    'handoff/001-x/HANDOFF.md',
    '/Users/x/mira/handoff/001-x/HANDOFF.md',
    'C:\\Users\\x\\mira\\handoff\\001-x\\HANDOFF.md',
    'handoff\\001-x\\HANDOFF.md',
  ];

  for (const tool of WRITE_TOOLS) {
    for (const file of handoffForms) {
      it(`blocks ${tool} of ${file}`, () => {
        assert.equal(checkFileAccess(tool, file, { exists: () => true }).decision, 'block');
      });
    }
  }

  // Same file on disk as handoff/001-x/HANDOFF.md, spelled with redundant segments.
  // Spec §7: guard-files blocks Edit/Write of handoff/*/HANDOFF.md.
  for (const file of [
    'handoff/001-x/./HANDOFF.md',
    '/Users/x/mira/handoff/001-x/./HANDOFF.md',
    'handoff/001-x/../001-x/HANDOFF.md',
    'handoff//001-x/HANDOFF.md',
    '/Users/x/mira/handoff/./001-x/HANDOFF.md',
  ]) {
    it(`blocks Edit of a HANDOFF.md spelled with dot segments: ${file}`, () => {
      assert.equal(checkFileAccess('Edit', file).decision, 'block');
    });
  }

  for (const tool of ['Read', 'Grep']) {
    it(`allows ${tool} of a HANDOFF.md`, () => {
      assert.equal(
        checkFileAccess(tool, 'handoff/001-x/HANDOFF.md').decision,
        'allow',
      );
    });
  }

  for (const file of [
    'HANDOFF.md',
    'docs/HANDOFF.md',
    'handoff/README.md',
    'handoff/001-x/RESULT.md',
    'handoff/001-x/HANDOFF.md.bak',
  ]) {
    it(`allows Edit of ${file}, which is not a handoff spec`, () => {
      assert.equal(checkFileAccess('Edit', file).decision, 'allow');
    });
  }
});

describe('checkFileAccess: package.json (QA)', () => {
  for (const tool of WRITE_TOOLS) {
    it(`allows ${tool} of package.json`, () => {
      assert.equal(checkFileAccess(tool, './package.json').decision, 'allow');
    });
  }

  it('allows a Windows-style package.json path', () => {
    assert.equal(
      checkFileAccess('Write', 'C:\\Users\\x\\mira\\package.json').decision,
      'allow',
    );
  });

  it('gives a non-empty reason when asking (guard config edit)', () => {
    const result = checkFileAccess('Edit', '.claude/settings.json');
    assert.equal(result.decision, 'ask');
    assert.ok(result.reason.length > 0);
  });

  for (const tool of ['Read', 'Grep']) {
    it(`allows ${tool} of package.json`, () => {
      assert.equal(
        checkFileAccess(tool, '/Users/x/mira/package.json').decision,
        'allow',
      );
    });
  }

  for (const file of ['package-lock.json', 'package.json.bak', 'src/package.ts']) {
    it(`does not ask for ${file}`, () => {
      assert.equal(checkFileAccess('Edit', file).decision, 'allow');
    });
  }
});

describe('guard-files.mjs as a hook: protocol (QA)', () => {
  it('keeps stdout empty when blocking, so the reason goes to stderr only', async () => {
    const result = await runHook('guard-files.mjs', {
      tool_name: 'Edit',
      tool_input: { file_path: '/Users/x/mira/.env.local' },
    });
    assert.equal(result.code, 2);
    assert.equal(result.stdout, '');
    assert.ok(result.stderr.includes('.env.example'));
  });

  it('blocks MultiEdit of a HANDOFF.md via stdin', async () => {
    const result = await runHook('guard-files.mjs', {
      tool_name: 'MultiEdit',
      tool_input: { file_path: 'handoff/001-x/HANDOFF.md', edits: [] },
    });
    assert.equal(result.code, 2);
    assert.equal(result.stdout, '');
  });

  it('blocks a Windows-style env path via stdin', async () => {
    const result = await runHook('guard-files.mjs', {
      tool_name: 'Read',
      tool_input: { file_path: 'C:\\repo\\.env' },
    });
    assert.equal(result.code, 2);
  });

  it('allows .env.example via stdin with no output', async () => {
    const result = await runHook('guard-files.mjs', {
      tool_name: 'Edit',
      tool_input: { file_path: '/Users/x/mira/.env.example' },
    });
    assert.deepEqual(result, { code: 0, stdout: '', stderr: '' });
  });

  it('emits exactly one JSON document with a reason when asking', async () => {
    const result = await runHook('guard-files.mjs', {
      tool_name: 'Write',
      tool_input: { file_path: '.claude/settings.json', content: '{}' },
    });
    assert.equal(result.code, 0);
    const parsed = JSON.parse(result.stdout);
    assert.equal(
      typeof parsed.hookSpecificOutput.permissionDecisionReason,
      'string',
    );
  });

  it('blocks an env path buried in a very large input', async () => {
    const result = await runHook('guard-files.mjs', {
      tool_name: 'Read',
      tool_input: { file_path: `${'a/'.repeat(200_000)}.env` },
      padding: 'x'.repeat(2_000_000),
    });
    assert.equal(result.code, 2);
  });
});

describe('guard-files.mjs as a hook: fails closed, never exit 1 (QA)', () => {
  for (const [label, input] of [
    ['empty stdin', ''],
    ['whitespace only', '   \n'],
    ['truncated JSON', '{"tool_name":"Read","tool_input":{"file_path":".env"'],
    ['trailing garbage', '{"tool_name":"Read"} x'],
    ['single-quoted JSON', "{'tool_name':'Read'}"],
  ]) {
    it(`blocks with exit 2 on ${label}`, async () => {
      const result = await runHook('guard-files.mjs', input);
      assert.equal(result.code, 2);
      assert.equal(result.stdout, '');
    });
  }

  for (const [label, input] of [
    ['null', 'null'],
    ['an array', '[]'],
    ['a string', '"str"'],
    ['a number', '42'],
    ['an empty object', '{}'],
    ['a missing tool_input', '{"tool_name":"Read"}'],
    ['a null tool_input', '{"tool_name":"Read","tool_input":null}'],
    [
      'a non-string file_path',
      '{"tool_name":"Read","tool_input":{"file_path":[".env"]}}',
    ],
    [
      'a numeric tool_name',
      '{"tool_name":7,"tool_input":{"file_path":"README.md"}}',
    ],
  ]) {
    it(`never exits 1 on valid JSON with ${label}`, async () => {
      const result = await runHook('guard-files.mjs', input);
      assert.ok([0, 2].includes(result.code));
    });
  }
});

describe('guard-files.mjs launched through a symlinked path (QA)', () => {
  // Claude Code runs hooks via $CLAUDE_PROJECT_DIR, which may be a symlink (e.g. /tmp on macOS).
  // Brief interface: isMain(metaUrl) is true when the module is the process entry point.
  const dir = mkdtempSync(path.join(tmpdir(), 'mira-qa-'));
  const link = path.join(dir, 'hooks');
  symlinkSync(path.resolve('.claude/hooks'), link);

  after(() => {
    rmSync(dir, { recursive: true, force: true });
  });

  it('still blocks a .env read', async () => {
    const result = await runHook(path.join(link, 'guard-files.mjs'), {
      tool_name: 'Read',
      tool_input: { file_path: '.env' },
    });
    assert.equal(result.code, 2);
  });
});
