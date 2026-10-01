// QA cases for format-on-edit, ported from the memory service's Vitest QA suites. Cases the base
// suite (format-on-edit.test.mjs) already proves are not repeated here.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import {
  lstat,
  mkdir,
  mkdtemp,
  readFile,
  rm,
  stat,
  symlink,
  utimes,
  writeFile,
} from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { formatFile } from '../../.claude/hooks/format-on-edit.mjs';
import { runHook } from '../support/run-hook.mjs';

// The kit carries no stack: prettier is the project's own devDependency. Cases that need real
// formatting skip with a reason when it isn't installed.
const hasPrettier = await import('prettier').then(
  () => true,
  () => false,
);
const needsPrettier = hasPrettier
  ? {}
  : { skip: 'prettier is not installed in this project' };

const UNFORMATTED_TS = 'const x = "y"\n';
const FORMATTED_TS = "const x = 'y';\n";

let project;
let outside;

beforeEach(async () => {
  project = await mkdtemp(path.join(tmpdir(), 'mira-format-qa-'));
  outside = await mkdtemp(path.join(tmpdir(), 'mira-format-qa-outside-'));
  await writeFile(
    path.join(project, '.prettierrc'),
    '{ "singleQuote": true }\n',
  );
});

afterEach(async () => {
  await rm(project, { recursive: true, force: true });
  await rm(outside, { recursive: true, force: true });
});

/**
 * @param {string} dir
 * @param {string} name
 * @param {string} content
 * @returns {Promise<string>}
 */
async function write(dir, name, content) {
  const file = path.join(dir, name);
  await mkdir(path.dirname(file), { recursive: true });
  await writeFile(file, content);
  return file;
}

/**
 * Runs the hook and asserts the PostToolUse contract: always exit 0, never any stdout.
 * @param {string | object} input
 * @param {NodeJS.ProcessEnv} [env]
 */
async function hook(input, env = { CLAUDE_PROJECT_DIR: project }) {
  const result = await runHook('format-on-edit.mjs', input, env);
  assert.equal(result.code, 0);
  assert.equal(result.stdout, '');
  return result;
}

/**
 * @param {unknown} filePath
 * @returns {object}
 */
function edit(filePath) {
  return { tool_name: 'Edit', tool_input: { file_path: filePath } };
}

describe('formatFile: containment', () => {
  for (const name of ['..draft.ts', '..cache/a.ts']) {
    it(
      `formats ${name} (a name starting with ".." is still inside)`,
      needsPrettier,
      async () => {
        const file = await write(project, name, UNFORMATTED_TS);
        assert.equal(await formatFile(file, project), 'formatted');
        assert.equal(await readFile(file, 'utf8'), FORMATTED_TS);
      },
    );
  }

  it(
    'formats a ".." path that resolves back inside, with a trailing-slash project dir',
    needsPrettier,
    async () => {
      const file = await write(project, 'a.ts', UNFORMATTED_TS);
      const roundabout = path.join(
        project,
        'sub',
        '..',
        '..',
        path.basename(project),
        'a.ts',
      );
      assert.equal(await formatFile(roundabout, `${project}/`), 'formatted');
      assert.equal(await readFile(file, 'utf8'), FORMATTED_TS);
    },
  );

  it('skips a sibling directory that shares the project dir as a name prefix', async () => {
    const sibling = `${project}-sibling`;
    const file = await write(sibling, 'a.ts', UNFORMATTED_TS);
    try {
      assert.equal(await formatFile(file, project), 'skipped');
      assert.equal(await readFile(file, 'utf8'), UNFORMATTED_TS);
    } finally {
      await rm(sibling, { recursive: true, force: true });
    }
  });

  it('skips a relative path that climbs out of the project', async () => {
    const file = await write(outside, 'a.ts', UNFORMATTED_TS);
    assert.equal(
      await formatFile(path.relative(project, file), project),
      'skipped',
    );
    assert.equal(await readFile(file, 'utf8'), UNFORMATTED_TS);
  });

  it('skips files under node_modules', needsPrettier, async () => {
    const file = await write(project, 'node_modules/x/i.js', UNFORMATTED_TS);
    assert.equal(await formatFile(file, project), 'skipped');
    assert.equal(await readFile(file, 'utf8'), UNFORMATTED_TS);
  });
});

describe('formatFile: symlinks', () => {
  it('skips a chain in -> in -> out and leaves the target unchanged', async () => {
    const target = await write(outside, 't.ts', UNFORMATTED_TS);
    await symlink(target, path.join(project, 'hop2.ts'));
    await symlink(path.join(project, 'hop2.ts'), path.join(project, 'hop1.ts'));
    assert.equal(
      await formatFile(path.join(project, 'hop1.ts'), project),
      'skipped',
    );
    assert.equal(await readFile(target, 'utf8'), UNFORMATTED_TS);
  });

  it(
    'formats through a relative chain that stays inside and keeps the link',
    needsPrettier,
    async () => {
      const real = await write(project, 'in/a.ts', UNFORMATTED_TS);
      await symlink('in/a.ts', path.join(project, 'hop2.ts'));
      await symlink('hop2.ts', path.join(project, 'hop1.ts'));
      assert.equal(await formatFile('hop1.ts', project), 'formatted');
      assert.equal(await readFile(real, 'utf8'), FORMATTED_TS);
      assert.ok((await lstat(path.join(project, 'hop1.ts'))).isSymbolicLink());
    },
  );

  it('skips a file under an in-project symlinked directory that points outside', async () => {
    const target = await write(outside, 'a.ts', UNFORMATTED_TS);
    await symlink(outside, path.join(project, 'ext'));
    assert.equal(
      await formatFile(path.join(project, 'ext', 'a.ts'), project),
      'skipped',
    );
    assert.equal(await readFile(target, 'utf8'), UNFORMATTED_TS);
  });

  it('skips a symlink loop', async () => {
    await symlink(path.join(project, 'l2.ts'), path.join(project, 'l1.ts'));
    await symlink(path.join(project, 'l1.ts'), path.join(project, 'l2.ts'));
    assert.equal(
      await formatFile(path.join(project, 'l1.ts'), project),
      'skipped',
    );
  });

  it(
    'does not format an ignored real file reached through a non-ignored symlink',
    needsPrettier,
    async () => {
      await write(project, '.prettierignore', 'handoff/\n');
      const real = await write(project, 'handoff/001/HANDOFF.md', '* item\n');
      await symlink(real, path.join(project, 'alias.md'));
      assert.equal(
        await formatFile(path.join(project, 'alias.md'), project),
        'skipped',
      );
      assert.equal(await readFile(real, 'utf8'), '* item\n');
    },
  );
});

describe('formatFile: file contents', () => {
  it(
    'reports unchanged for an empty file and leaves it empty',
    needsPrettier,
    async () => {
      const file = await write(project, 'e.ts', '');
      assert.equal(await formatFile(file, project), 'unchanged');
      assert.equal(await readFile(file, 'utf8'), '');
    },
  );

  it(
    'normalises CRLF line endings to LF, as prettier --check expects',
    needsPrettier,
    async () => {
      const file = await write(
        project,
        'c.ts',
        "const x = 'y';\r\nconst z = 1;\r\n",
      );
      assert.equal(await formatFile(file, project), 'formatted');
      assert.equal(
        await readFile(file, 'utf8'),
        "const x = 'y';\nconst z = 1;\n",
      );
    },
  );

  it('keeps a BOM and is idempotent on a BOM file', needsPrettier, async () => {
    const file = await write(project, 'b.ts', `\uFEFF${UNFORMATTED_TS}`);
    assert.equal(await formatFile(file, project), 'formatted');
    assert.equal(await readFile(file, 'utf8'), `\uFEFF${FORMATTED_TS}`);
    assert.equal(await formatFile(file, project), 'unchanged');
  });
});

/** Files `prettier --list-different .` reports when run from `dir`, as CI does. */
function cliListDifferent(dir) {
  const bin = path.join(
    path.dirname(fileURLToPath(import.meta.resolve('prettier'))),
    'bin/prettier.cjs',
  );
  try {
    execFileSync(process.execPath, [bin, '--list-different', '.'], {
      cwd: dir,
      encoding: 'utf8',
      stdio: 'pipe',
    });
    return [];
  } catch (error) {
    return String(error.stdout ?? '')
      .split('\n')
      .filter(Boolean)
      .sort();
  }
}

describe('formatFile: ignore rules match the Prettier CLI', () => {
  for (const [label, files, expected] of [
    [
      'a .gitignore negation',
      {
        '.gitignore': '*.ts\n!keep.ts\n',
        'keep.ts': UNFORMATTED_TS,
        'other.ts': UNFORMATTED_TS,
      },
      ['keep.ts'],
    ],
    [
      'a .prettierignore that tries to un-ignore a .gitignore entry',
      {
        '.gitignore': 'gen/\n',
        '.prettierignore': '!gen/keep.ts\n',
        'gen/keep.ts': UNFORMATTED_TS,
        'gen/x.ts': UNFORMATTED_TS,
      },
      undefined,
    ],
    [
      'a nested .gitignore (only the root one is read)',
      {
        'sub/.gitignore': 'a.ts\n',
        'sub/a.ts': UNFORMATTED_TS,
        'sub/b.ts': UNFORMATTED_TS,
      },
      ['sub/a.ts', 'sub/b.ts'],
    ],
    [
      'a nested .prettierignore',
      { 'sub/.prettierignore': 'a.ts\n', 'sub/a.ts': UNFORMATTED_TS },
      undefined,
    ],
    [
      'an anchored .gitignore pattern',
      { '.gitignore': '/*.md\n', 'A.md': '* x\n', 'd/B.md': '* x\n' },
      undefined,
    ],
    [
      'a differently cased .PrettierIgnore',
      {
        '.PrettierIgnore': 'a.ts\n',
        'a.ts': UNFORMATTED_TS,
        'b.ts': UNFORMATTED_TS,
      },
      undefined,
    ],
    [
      'mixed-case extensions',
      { 'A.Md': '* x\n', 'B.YAML': 'a:   1\n', 'C.Ts': UNFORMATTED_TS },
      ['A.Md', 'B.YAML', 'C.Ts'],
    ],
  ]) {
    it(
      `formats exactly what the CLI flags with ${label}`,
      needsPrettier,
      async () => {
        for (const [name, content] of Object.entries(files)) {
          await write(project, name, content);
        }
        const flagged = cliListDifferent(project);
        if (expected) assert.deepEqual(flagged, expected);
        const formatted = [];
        for (const name of Object.keys(files).filter(
          (n) => !/ignore$/i.test(n),
        )) {
          if (
            (await formatFile(path.join(project, name), project)) ===
            'formatted'
          ) {
            formatted.push(name);
          }
        }
        assert.deepEqual(formatted.sort(), flagged);
      },
    );
  }
});

describe('format-on-edit.mjs as a hook: never blocks, never prints to stdout', () => {
  for (const [label, input] of [
    ['empty stdin', ''],
    ['JSON null', 'null'],
    ['a JSON array', '[1,2]'],
    ['a JSON string', '"a.ts"'],
    ['missing tool_input', { tool_name: 'Edit' }],
    ['missing file_path', { tool_name: 'Edit', tool_input: {} }],
    ['numeric file_path', edit(42)],
    ['array file_path', edit(['a.ts'])],
    ['object file_path', edit({ path: 'a.ts' })],
    ['null file_path', edit(null)],
    ['empty file_path', edit('')],
  ]) {
    it(`exits 0 on ${label}`, async () => {
      await hook(input);
    });
  }

  it('exits 0 silently and creates nothing for a missing file or a broken symlink', async () => {
    const target = path.join(project, 'nowhere.ts');
    await symlink(target, path.join(project, 'broken.ts'));
    for (const name of ['gone.ts', 'broken.ts']) {
      const result = await hook(edit(path.join(project, name)));
      assert.equal(result.stderr, '');
    }
    await assert.rejects(lstat(target));
    await assert.rejects(lstat(path.join(project, 'gone.ts')));
  });

  it('exits 0 when file_path is a directory, or a symlink to one, named like a formattable file', async () => {
    await mkdir(path.join(project, 'dir.ts'));
    await symlink(path.join(project, 'dir.ts'), path.join(project, 'link.ts'));
    await hook(edit(path.join(project, 'dir.ts')));
    await hook(edit(path.join(project, 'link.ts')));
    assert.ok((await stat(path.join(project, 'dir.ts'))).isDirectory());
    assert.ok((await lstat(path.join(project, 'link.ts'))).isSymbolicLink());
  });

  for (const [name, content] of [
    ['a.json', '{"a": }\n'],
    ['a.yml', 'a: [1, 2\n'],
    ['a.mjs', 'export default {\n'],
  ]) {
    it(`exits 0 and leaves ${name} with a syntax error byte-for-byte unchanged`, async () => {
      const file = await write(project, name, content);
      await hook(edit(file));
      assert.deepEqual(await readFile(file), Buffer.from(content));
    });
  }

  it(
    'does not rewrite an already formatted file (mtime unchanged)',
    needsPrettier,
    async () => {
      const file = await write(project, 'a.ts', FORMATTED_TS);
      const past = new Date('2020-01-01T00:00:00Z');
      await utimes(file, past, past);
      await hook(edit(file));
      assert.equal((await stat(file)).mtimeMs, past.getTime());
    },
  );

  it(
    'formats with a trailing-slash CLAUDE_PROJECT_DIR',
    needsPrettier,
    async () => {
      const file = await write(project, 'a.ts', UNFORMATTED_TS);
      await hook(edit(file), { CLAUDE_PROJECT_DIR: `${project}/` });
      assert.equal(await readFile(file, 'utf8'), FORMATTED_TS);
    },
  );

  it('prefers a non-empty CLAUDE_PROJECT_DIR over the input cwd', async () => {
    const file = await write(outside, 'a.ts', UNFORMATTED_TS);
    await hook(
      { cwd: outside, tool_input: { file_path: file } },
      { CLAUDE_PROJECT_DIR: project },
    );
    assert.equal(await readFile(file, 'utf8'), UNFORMATTED_TS);
  });

  for (const [label, input] of [
    ['an absolute file_path', (file) => edit(file)],
    [
      'a relative file_path',
      () => ({ cwd: project, tool_input: { file_path: 'a.ts' } }),
    ],
  ]) {
    it(`exits 0 and changes nothing when CLAUDE_PROJECT_DIR does not exist, with ${label}`, async () => {
      const file = await write(project, 'a.ts', UNFORMATTED_TS);
      await hook(input(file), {
        CLAUDE_PROJECT_DIR: path.join(project, 'nope'),
      });
      assert.equal(await readFile(file, 'utf8'), UNFORMATTED_TS);
    });
  }
});
