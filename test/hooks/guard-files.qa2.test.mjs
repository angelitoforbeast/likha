import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { checkFileAccess } from '../../.claude/hooks/guard-files.mjs';
import { decide } from '../../.claude/hooks/lib/hook-io.mjs';
import { runHook } from '../support/run-hook.mjs';

const TOOLS = ['Read', 'Edit', 'Write', 'MultiEdit', 'Grep'];

describe('env matcher: no false positives (QA round 2)', () => {
  const lookalikes = [
    '.environment',
    'config/.environment',
    'src/.envoy/x',
    'src/.envoy/.envoyrc',
    'dotenv.ts',
    'src/dotenv.ts',
    'my.env.ts',
    'src/my.env/x.ts',
    'env.d.ts',
    'environment.env',
    '.envrc',
    '.ENVRC',
    'docs/env-guide.md',
    'src/.envelope/data.json',
  ];
  for (const tool of TOOLS) {
    for (const file of lookalikes) {
      it(`allows ${tool} of ${file}`, () => {
        assert.equal(checkFileAccess(tool, file).decision, 'allow');
      });
    }
  }
});

describe('env matcher: .env.example in every case (QA round 2, ruling 1)', () => {
  for (const file of [
    '.Env.Example',
    '.ENV.EXAMPLE',
    './.env.Example',
    '/Users/x/mira/.Env.example',
    'C:\\mira\\.Env.Example',
    'a/../.env.example',
  ]) {
    it(`allows Edit of ${file}`, () => {
      assert.equal(checkFileAccess('Edit', file).decision, 'allow');
    });
  }
});

describe('env matcher: bypass attempts (QA round 2, rulings 1-3)', () => {
  for (const [file, ruling] of [
    ['.eNv', 1],
    ['.ENV.LOCAL', 1],
    ['C:\\mira\\.ENV', 1],
    ['.env-local', 2],
    ['.env_old', 2],
    ['.env~', 2],
    ['.env.', 2],
    ['.env0', 2],
    ['.env ', 2],
    ['.env#', 2],
    ['.env.example.bak', 2],
    ['.env.examples', 2],
    ['.env.example.ts', 2],
    ['.env.example~', 2],
    ['.ENV.EXAMPLE.local', 2],
    ['.env.d/x', 3],
    ['.ENV.D/x', 3],
    ['a/.env/b/c.txt', 3],
    ['/abs/.env-backup/secrets.json', 3],
    ['C:\\mira\\.env.d\\x', 3],
    ['src/../.env', 3],
    ['./x/../.env.local', 3],
    ['.env.example/../.env', 3],
    ['.env.example/.env', 3],
  ]) {
    it(`blocks Read of ${file} (ruling ${ruling})`, () => {
      assert.equal(checkFileAccess('Read', file).decision, 'block');
    });
  }

  it('allows a path that merely passes through .env.example as a directory name', () => {
    assert.equal(
      checkFileAccess('Read', '.env.example/notes.md').decision,
      'allow',
    );
  });
});

describe('Grep glob field (QA round 2, ruling 4)', () => {
  const grep = (glob, path) =>
    runHook('guard-files.mjs', {
      tool_name: 'Grep',
      tool_input: { pattern: 'KEY', glob, ...(path ? { path } : {}) },
    });

  for (const glob of [
    '.env',
    '.env*',
    '.ENV*',
    '**/.env',
    '**/.env.*',
    '.env.{local,production}',
    'config/**/.env-*',
    '.env.d/**',
    'C:\\mira\\.env',
  ]) {
    it(`blocks glob ${glob} with path "."`, async () => {
      const result = await grep(glob, '.');
      assert.equal(result.code, 2);
      assert.equal(result.stdout, '');
    });
  }

  for (const glob of [
    '*.ts',
    '.env.example',
    '**/.Env.Example',
    '**/.envrc',
    '.environment',
    '**/dotenv.ts',
    '*.{ts,mjs}',
  ]) {
    it(`allows glob ${glob}`, async () => {
      const result = await grep(glob, '.');
      assert.deepEqual(result, { code: 0, stdout: '', stderr: '' });
    });
  }

  it('still blocks a secret path when the glob is harmless', async () => {
    const result = await grep('*.ts', 'config/.env.d');
    assert.equal(result.code, 2);
  });

  it('never exits 1 on a non-string glob', async () => {
    const result = await grep(['.env'], '.');
    assert.ok([0, 2].includes(result.code));
  });
});

describe('decide: invalid decisions (QA round 2, ruling 5)', () => {
  const invalid = [
    [],
    {},
    { decision: 'Block', reason: 'x' },
    { decision: 'allow ', reason: 'x' },
    { decision: 'block', reason: null },
    { decision: 'ask' },
    { decision: ['block'], reason: 'x' },
    Promise.resolve({ decision: 'allow' }),
  ];
  for (const value of invalid) {
    it(`blocks when the evaluator returns ${JSON.stringify(value)}`, () => {
      const evaluate = () => value;
      assert.equal(decide(evaluate, {}).decision, 'block');
    });
  }

  it('accepts an allow that carries extra fields', () => {
    const evaluate = () => ({
      decision: 'allow',
      reason: 'fine',
    });
    assert.equal(decide(evaluate, {}).decision, 'allow');
  });
});

describe('valid JSON with no path stays allow (QA round 2, ruling 6)', () => {
  for (const input of [
    '{}',
    '{"tool_name":"Read","tool_input":{}}',
    '{"tool_name":"Grep","tool_input":{"pattern":"x"}}',
  ]) {
    it(`exits 0 silently for ${input}`, async () => {
      const result = await runHook('guard-files.mjs', input);
      assert.deepEqual(result, { code: 0, stdout: '', stderr: '' });
    });
  }
});
