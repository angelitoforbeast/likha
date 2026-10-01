// PostToolUse hook: formats the edited file with Prettier. Prettier only; eslint --fix restructures
// code and is slow, so it runs via `npm run lint:fix` before commits instead (spec §7).
// Never blocks: any error is reported on stderr and the hook exits 0.
// Prettier is the project's own devDependency (the kit carries no stack); without it the hook
// skips silently, so a project on another stack loses nothing but formatting.
import { spawnSync } from 'node:child_process';
import { readFile, realpath, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { isMain, pick, readHookInput } from './lib/hook-io.mjs';

/** @returns {Promise<typeof import('prettier') | null>} */
async function loadPrettier() {
  try {
    return await import('prettier');
  } catch {
    return null;
  }
}

const FORMATTABLE = new Set([
  '.ts',
  '.js',
  '.mjs',
  '.json',
  '.md',
  '.yml',
  '.yaml',
]);

/**
 * @param {string} file
 * @param {string} dir
 * @returns {boolean}
 */
function isInside(file, dir) {
  const relative = path.relative(dir, file);
  if (path.isAbsolute(relative)) return false;
  return relative !== '..' && !relative.startsWith(`..${path.sep}`);
}

/**
 * Legacy mode is recorded by the installer in <root>/.dev-kit.json; missing or broken means standard.
 * @param {string} root
 * @returns {Promise<boolean>}
 */
async function isLegacy(root) {
  try {
    const state = JSON.parse(await readFile(path.join(root, '.dev-kit.json'), 'utf8'));
    return state?.mode === 'legacy';
  } catch {
    return false;
  }
}

/**
 * The version of the file at HEAD, or null when git can't give one (not a repo, git missing,
 * new or untracked file). `./` resolves against cwd, so a project inside a larger repo works.
 * @param {string} root
 * @param {string} absolute
 * @returns {string | null}
 */
function committedSource(root, absolute) {
  const relative = path.relative(root, absolute).split(path.sep).join('/');
  const result = spawnSync('git', ['show', `HEAD:./${relative}`], {
    cwd: root,
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
  });
  return result.error || result.status !== 0 ? null : result.stdout;
}

/**
 * A committed version that doesn't parse counts as not clean.
 * @param {typeof import('prettier')} prettier
 * @param {string} committed
 * @param {object} options
 * @param {string} absolute
 * @returns {Promise<boolean>}
 */
async function isClean(prettier, committed, options, absolute) {
  try {
    return await prettier.check(committed, { ...options, filepath: absolute });
  } catch {
    return false;
  }
}

/**
 * @param {string} filePath absolute, or relative to projectDir
 * @param {string} projectDir
 * @returns {Promise<'formatted' | 'unchanged' | 'skipped' | 'skipped-legacy'>}
 */
export async function formatFile(filePath, projectDir) {
  // Containment is judged on real paths, so a symlink cannot smuggle in a file outside the project.
  const root = await realpath(projectDir);
  let absolute;
  try {
    absolute = await realpath(path.resolve(projectDir, filePath));
  } catch {
    return 'skipped';
  }
  if (!isInside(absolute, root)) return 'skipped';
  if (!FORMATTABLE.has(path.extname(absolute).toLowerCase())) return 'skipped';
  const prettier = await loadPrettier();
  if (!prettier) return 'skipped';

  // Same ignore sources as the Prettier CLI, so the hook skips exactly what CI skips.
  const info = await prettier.getFileInfo(absolute, {
    ignorePath: [
      path.join(root, '.gitignore'),
      path.join(root, '.prettierignore'),
    ],
  });
  if (info.ignored) return 'skipped';

  const source = await readFile(absolute, 'utf8');
  const options = (await prettier.resolveConfig(absolute)) ?? {};
  // Legacy projects: never reformat a file whose committed version wasn't already prettier-clean.
  if (await isLegacy(root)) {
    const committed = committedSource(root, absolute);
    if (committed !== null && !(await isClean(prettier, committed, options, absolute))) {
      return 'skipped-legacy';
    }
  }
  const formatted = await prettier.format(source, {
    ...options,
    filepath: absolute,
  });
  if (formatted === source) return 'unchanged';
  await writeFile(absolute, formatted);
  return 'formatted';
}

if (isMain(import.meta.url)) {
  try {
    const input = await readHookInput();
    const filePath = pick(input, 'tool_input', 'file_path');
    const projectDir =
      process.env.CLAUDE_PROJECT_DIR || pick(input, 'cwd') || process.cwd();
    if (filePath !== undefined) await formatFile(filePath, projectDir);
  } catch (error) {
    process.stderr.write(
      `format-on-edit: ${error instanceof Error ? error.message : String(error)}\n`,
    );
  }
  process.exit(0);
}
