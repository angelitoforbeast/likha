import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import {
  mkdir,
  mkdtemp,
  readFile,
  realpath,
  rm,
  symlink,
  writeFile,
} from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { formatFile } from '../../.claude/hooks/format-on-edit.mjs';
import { runHook } from '../support/run-hook.mjs';

// The kit carries no stack: prettier is the project's own devDependency. Cases that need real
// formatting skip with a reason when it isn't installed; the hook itself then reports 'skipped'.
const hasPrettier = await import('prettier').then(() => true, () => false);
const needsPrettier = hasPrettier ? {} : { skip: 'prettier is not installed in this project' };

let project;

beforeEach(async () => {
  project = await mkdtemp(path.join(tmpdir(), 'mira-format-'));
  await writeFile(
    path.join(project, '.prettierrc'),
    '{ "singleQuote": true }\n',
  );
});

afterEach(async () => {
  await rm(project, { recursive: true, force: true });
});

async function write(name, content) {
  const file = path.join(project, name);
  await writeFile(file, content);
  return file;
}

describe('formatFile', () => {
  for (const [name, input, expected] of [
    ['a.ts', 'const x = "y"\n', "const x = 'y';\n"],
    ['a.mjs', 'const x = "y"\n', "const x = 'y';\n"],
    ['a.js', 'const x = "y"\n', "const x = 'y';\n"],
    ['a.json', '{"a":1}\n', '{ "a": 1 }\n'],
    ['.foo.json', '{"a":1}\n', '{ "a": 1 }\n'],
    ['a.yml', 'a:   1\n', 'a: 1\n'],
    ['a.yaml', 'a:   1\n', 'a: 1\n'],
    ['a.md', '# Title\n* item\n', '# Title\n\n- item\n'],
  ]) {
    it(`formats ${name} using the project config`, needsPrettier, async () => {
      const file = await write(name, input);
      assert.equal(await formatFile(file, project), 'formatted');
      assert.equal(await readFile(file, 'utf8'), expected);
    });
  }

  it('reports unchanged for an already formatted file', needsPrettier, async () => {
    const file = await write('a.ts', "const x = 'y';\n");
    assert.equal(await formatFile(file, project), 'unchanged');
  });

  it('skips file types it does not format', async () => {
    const file = await write('a.txt', 'x   y\n');
    assert.equal(await formatFile(file, project), 'skipped');
    assert.equal(await readFile(file, 'utf8'), 'x   y\n');
  });

  it('skips files listed in .prettierignore', needsPrettier, async () => {
    await write('.prettierignore', 'handoff/\n');
    await mkdir(path.join(project, 'handoff'));
    const file = await write('handoff/HANDOFF.md', '* item\n');
    assert.equal(await formatFile(file, project), 'skipped');
    assert.equal(await readFile(file, 'utf8'), '* item\n');
  });

  it('skips files outside the project directory', async () => {
    const outside = await mkdtemp(path.join(tmpdir(), 'mira-outside-'));
    const file = path.join(outside, 'a.ts');
    await writeFile(file, 'const x = "y"\n');
    assert.equal(await formatFile(file, project), 'skipped');
    assert.equal(await readFile(file, 'utf8'), 'const x = "y"\n');
    await rm(outside, { recursive: true, force: true });
  });

  it('resolves a relative path against the project directory', needsPrettier, async () => {
    await write('a.ts', 'const x = "y"\n');
    assert.equal(await formatFile('a.ts', project), 'formatted');
  });

  it('skips an in-project symlink whose target is outside the project', needsPrettier, async () => {
    const outside = await mkdtemp(path.join(tmpdir(), 'mira-outside-'));
    const target = path.join(outside, 't.ts');
    await writeFile(target, 'const x = "y"\n');
    await symlink(target, path.join(project, 'link.ts'));
    assert.equal(
      await formatFile(path.join(project, 'link.ts'), project),
      'skipped',
    );
    assert.equal(await readFile(target, 'utf8'), 'const x = "y"\n');
    await rm(outside, { recursive: true, force: true });
  });

  it('formats an in-project symlink whose target is inside the project', needsPrettier, async () => {
    const target = await write('t.ts', 'const x = "y"\n');
    await symlink(target, path.join(project, 'link.ts'));
    assert.equal(
      await formatFile(path.join(project, 'link.ts'), project),
      'formatted',
    );
    assert.equal(await readFile(target, 'utf8'), "const x = 'y';\n");
  });

  it('formats a file when the project dir is reached through a symlink', needsPrettier, async () => {
    const linkParent = await mkdtemp(path.join(tmpdir(), 'mira-link-'));
    const linkedProject = path.join(linkParent, 'proj');
    await symlink(project, linkedProject);
    const file = await write('a.ts', 'const x = "y"\n');
    assert.equal(
      await formatFile(await realpath(file), linkedProject),
      'formatted',
    );
    assert.equal(await readFile(file, 'utf8'), "const x = 'y';\n");
    await rm(linkParent, { recursive: true, force: true });
  });

  it('skips a missing file instead of throwing', async () => {
    assert.equal(
      await formatFile(path.join(project, 'gone.ts'), project),
      'skipped',
    );
  });

  it('matches the extension case-insensitively', needsPrettier, async () => {
    const file = await write('A.TS', 'const x = "y"\n');
    assert.equal(await formatFile(file, project), 'formatted');
    assert.equal(await readFile(file, 'utf8'), "const x = 'y';\n");
  });

  it('skips files listed in .gitignore, as the Prettier CLI does', needsPrettier, async () => {
    await write('.gitignore', 'scratch/\n');
    await mkdir(path.join(project, 'scratch'));
    const file = await write('scratch/a.ts', 'const x = "y"\n');
    assert.equal(await formatFile(file, project), 'skipped');
    assert.equal(await readFile(file, 'utf8'), 'const x = "y"\n');
  });

  it('still honours .prettierignore when there is no .gitignore', needsPrettier, async () => {
    await write('.prettierignore', 'handoff/\n');
    await mkdir(path.join(project, 'handoff'));
    const ignored = await write('handoff/a.ts', 'const x = "y"\n');
    const kept = await write('b.ts', 'const x = "y"\n');
    assert.equal(await formatFile(ignored, project), 'skipped');
    assert.equal(await formatFile(kept, project), 'formatted');
  });
});

describe('format-on-edit.mjs as a hook', () => {
  it('formats the edited file and exits 0', needsPrettier, async () => {
    const file = await write('a.ts', 'const x = "y"\n');
    const result = await runHook(
      'format-on-edit.mjs',
      { tool_name: 'Edit', tool_input: { file_path: file } },
      { CLAUDE_PROJECT_DIR: project },
    );
    assert.equal(result.code, 0);
    assert.equal(result.stdout, '');
    assert.equal(await readFile(file, 'utf8'), "const x = 'y';\n");
  });

  it('exits 0 and leaves a file with a syntax error unchanged', async () => {
    const file = await write('a.ts', 'const = ;\n');
    const result = await runHook(
      'format-on-edit.mjs',
      { tool_name: 'Edit', tool_input: { file_path: file } },
      { CLAUDE_PROJECT_DIR: project },
    );
    assert.equal(result.code, 0);
    assert.equal(await readFile(file, 'utf8'), 'const = ;\n');
  });

  it('falls back to the input cwd when CLAUDE_PROJECT_DIR is empty', needsPrettier, async () => {
    const file = await write('a.ts', 'const x = "y"\n');
    const result = await runHook(
      'format-on-edit.mjs',
      { cwd: project, tool_name: 'Write', tool_input: { file_path: 'a.ts' } },
      { CLAUDE_PROJECT_DIR: '' },
    );
    assert.equal(result.code, 0);
    assert.equal(await readFile(file, 'utf8'), "const x = 'y';\n");
  });

  it('exits 0 on input that is not JSON', async () => {
    const result = await runHook('format-on-edit.mjs', 'nope', {
      CLAUDE_PROJECT_DIR: project,
    });
    assert.equal(result.code, 0);
  });
});

function gitCommitAll(dir) {
  const git = (...a) => spawnSync('git', a, { cwd: dir, encoding: 'utf8' });
  git('init', '-q');
  git('config', 'user.email', 't@t');
  git('config', 'user.name', 't');
  git('add', '.');
  git('commit', '-qm', 'base');
}
const legacy = () =>
  writeFile(
    path.join(project, '.dev-kit.json'),
    JSON.stringify({ version: '0.6.0', mode: 'legacy', files: {} }),
  );

describe('formatFile in legacy mode', () => {
  it('leaves a file alone whose committed version was not prettier-clean', needsPrettier, async () => {
    await legacy();
    const file = await write('old.ts', 'const a = "x"\n');
    gitCommitAll(project);
    await writeFile(file, 'const a = "x"\nconst b = "y"\n');
    assert.equal(await formatFile(file, project), 'skipped-legacy');
    assert.equal(await readFile(file, 'utf8'), 'const a = "x"\nconst b = "y"\n');
  });

  it('judges the committed version when the project is a subfolder of the git repo', needsPrettier, async () => {
    const sub = path.join(project, 'sub');
    await mkdir(sub);
    await writeFile(path.join(sub, '.prettierrc'), '{ "singleQuote": true }\n');
    await writeFile(path.join(sub, '.dev-kit.json'), JSON.stringify({ version: '0.6.0', mode: 'legacy', files: {} }));
    const file = path.join(sub, 'old.ts');
    await writeFile(file, 'const a = "x"\n');
    gitCommitAll(project);
    await writeFile(file, 'const a = "x"\nconst b = "y"\n');
    assert.equal(await formatFile(file, sub), 'skipped-legacy');
    assert.equal(await readFile(file, 'utf8'), 'const a = "x"\nconst b = "y"\n');
  });

  it('leaves a file alone whose committed version does not parse', needsPrettier, async () => {
    await legacy();
    const file = await write('broken.ts', 'const a = (\n');
    gitCommitAll(project);
    await writeFile(file, 'const a = 1\n');
    assert.equal(await formatFile(file, project), 'skipped-legacy');
  });

  it('formats a file whose committed version was already clean', needsPrettier, async () => {
    await legacy();
    const file = await write('clean.ts', "const a = 'x';\n");
    gitCommitAll(project);
    await writeFile(file, "const a = 'x';\nconst b = \"y\"\n");
    assert.equal(await formatFile(file, project), 'formatted');
  });

  it('formats a new file that git has never seen', needsPrettier, async () => {
    await legacy();
    await write('keep.ts', "const k = 'k';\n");
    gitCommitAll(project);
    const file = await write('new.ts', 'const n = "n"\n');
    assert.equal(await formatFile(file, project), 'formatted');
  });

  it('formats when the project is not a git repository', needsPrettier, async () => {
    await legacy();
    const file = await write('a.ts', 'const a = "x"\n');
    assert.equal(await formatFile(file, project), 'formatted');
  });

  it('standard mode formats as before', needsPrettier, async () => {
    const file = await write('old.ts', 'const a = "x"\n');
    gitCommitAll(project);
    assert.equal(await formatFile(file, project), 'formatted');
  });
});
