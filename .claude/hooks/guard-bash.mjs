// PreToolUse guard for Bash. Spec: docs/superpowers/specs/2026-09-26-project-foundation-design.md §7.
//
// HEURISTIC, DEFENSE-IN-DEPTH ONLY. It pattern-matches command text, so it has false negatives
// (`git push` with no args pushing a tracked protected branch, aliases, scripts, `eval`, variables)
// and false positives (a commit message that mentions a blocked command). The hard gates are
// the permission rules in .claude/settings.json (`deny` on git push --force). Do not "fix" this file into a false sense of enforcement.
//
// Beyond the spec's letter: `git commit -n` (short for --no-verify) and `git push --all` /
// `--mirror` (both push main and develop) are blocked too.
//
// Any other push is allowed without a prompt (Arnel, 2026-09-27: workers push their own branches
// and open PRs). No `gh pr merge` at all: Mira merges after her code review (Arnel, 2026-09-28);
// Arnel merges releases into main.
// Settings rules are prefix-only, so `git -C . push`, `/usr/bin/git push` and `env git push` would
// slip past them; this hook normalises them. Block rules win.
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { isMain, pick, runGuard } from './lib/hook-io.mjs';
import { isSecretEnvName } from './lib/secret-env.mjs';

/** @typedef {import('./lib/hook-io.mjs').Decision} Decision */

const RM_SAFE_TARGETS = new Set(['node_modules', 'dist', 'coverage']);
const PROTECTED_REF = /^\+?(?:[^:\s]*:)?(?:refs\/heads\/)?(?:main|develop)$/;
// Git flow, chosen per project at `/dev-kit:init --flow` and stored in `.dev-kit.json`:
// `branches` (main + develop + feature PRs; pushes to main/develop blocked) or `simple` (one
// branch, the worker pushes to main; no PR). Anything else, or no state file, means `branches`.
const FLOWS = new Set(['branches', 'simple']);

/**
 * @param {string} projectDir
 * @returns {'branches' | 'simple'}
 */
export function readFlow(projectDir) {
  try {
    const state = JSON.parse(
      readFileSync(path.join(projectDir, '.dev-kit.json'), 'utf8'),
    );
    const flow = pick(state, 'flow');
    return flow === 'simple' ? 'simple' : 'branches';
  } catch {
    return 'branches';
  }
}
// Anything that can't be part of a file name: path and assignment separators (`/`, `=`, `:`) plus
// shell punctuation such as `@`, `<`, `>`, `,`, so `--env-file=.env` and `curl -d @.env` are seen.
const NAME_BOUNDARY = /[^\w.-]+/;
// git global options that take a value as the next token (unless written `--opt=value`).
const GIT_VALUE_OPTIONS = new Set([
  '-C',
  '-c',
  '--git-dir',
  '--work-tree',
  '--namespace',
]);
// A redirect such as `>`, `2>>`, `&>` or `<`. Group 1 is an attached target (`2>/dev/null`, `2>&1`).
const REDIRECT = /^(?:\d+|&)?(?:>>?|<)(.*)$/;

/**
 * The command a token names: `\rm`, `/bin/RM` and `rm` are all `rm` (the backslash skips aliases,
 * and macOS volumes are usually case-insensitive).
 * @param {string} token
 * @returns {string}
 */
function commandName(token) {
  return (token.replace(/^\\/, '').split('/').pop() ?? '').toLowerCase();
}

/**
 * Strips quotes, `$(`, `(`, `)` and backticks around a token, so `bash -c "git push --force"` and
 * `$(git reset --hard)` tokenize like the bare command.
 * @param {string} token
 * @returns {string}
 */
function cleanToken(token) {
  return token.replace(/^(?:\$\(|[`'"(])+/, '').replace(/[`'")]+$/, '');
}

/**
 * Splits a shell command on &&, ||, ;, |, a lone & and newlines. An `&` inside a redirect
 * (`2>&1`, `&>`) is not a separator.
 * @param {string} command
 * @returns {string[][]} the cleaned tokens of each segment
 */
function segments(command) {
  return command
    .split(/&&|\|\||;|\||(?<![<>])&(?!>)|\n/)
    .map((segment) =>
      segment.trim().split(/\s+/).map(cleanToken).filter(Boolean),
    );
}

/**
 * Index of the subcommand in `git [global options] <subcommand>`, or -1.
 * @param {string[]} tokens
 * @param {number} gitIndex
 * @returns {number}
 */
function gitSubcommandIndex(tokens, gitIndex) {
  let index = gitIndex + 1;
  while (tokens[index]?.startsWith('-')) {
    index += GIT_VALUE_OPTIONS.has(tokens[index] ?? '') ? 2 : 1;
  }
  return index < tokens.length ? index : -1;
}

/**
 * Indexes of the subcommand of every `git` in the segment.
 * @param {string[]} tokens
 * @returns {number[]}
 */
function gitSubcommandIndexes(tokens) {
  return tokens.flatMap((token, index) =>
    commandName(token) === 'git' ? [gitSubcommandIndex(tokens, index)] : [],
  );
}

/**
 * Tokens after `git … <subcommand>` in a segment, or null if the segment isn't that git subcommand.
 * @param {string[]} tokens
 * @param {string} subcommand
 * @returns {string[] | null}
 */
function gitArgs(tokens, subcommand) {
  const subIndex = gitSubcommandIndexes(tokens).find(
    (index) => tokens[index] === subcommand,
  );
  return subIndex === undefined ? null : tokens.slice(subIndex + 1);
}

/**
 * @param {string} flag
 * @param {string} letter
 * @returns {boolean}
 */
function hasShortFlag(flag, letter) {
  return /^-[a-zA-Z]+$/.test(flag) && flag.includes(letter);
}

/**
 * Drops redirects and their targets (`2>/dev/null`, `&> log`, `2>&1`), which aren't arguments.
 * @param {string[]} args
 * @returns {string[]}
 */
function withoutRedirects(args) {
  /** @type {string[]} */
  const kept = [];
  for (let index = 0; index < args.length; index += 1) {
    const redirect = REDIRECT.exec(args[index] ?? '');
    if (redirect === null) kept.push(args[index] ?? '');
    else if (redirect[1] === '') index += 1;
  }
  return kept;
}

/**
 * Every `rm` in the segment (so `sh -c 'rm …'` and `xargs rm …` count), except `git rm`.
 * @param {string[]} tokens
 */
function isUnsafeRecursiveRm(tokens) {
  const gitSubcommands = gitSubcommandIndexes(tokens);
  return tokens.some(
    (token, index) =>
      commandName(token) === 'rm' &&
      !gitSubcommands.includes(index) &&
      isUnsafeRmAt(tokens, index),
  );
}

/**
 * @param {string[]} tokens
 * @param {number} rmIndex
 */
function isUnsafeRmAt(tokens, rmIndex) {
  const args = withoutRedirects(tokens.slice(rmIndex + 1));
  const recursive = args.some(
    (arg) =>
      arg === '--recursive' || hasShortFlag(arg, 'r') || hasShortFlag(arg, 'R'),
  );
  if (!recursive) return false;
  const targets = args.filter((arg) => !arg.startsWith('-'));
  return (
    tokens.slice(0, rmIndex).some((token) => commandName(token) === 'sudo') ||
    targets.length === 0 ||
    !targets.every((target) => RM_SAFE_TARGETS.has(target.replace(/\/$/, '')))
  );
}

/**
 * True if any name inside any token is a real env file (same rule as guard-files).
 * @param {string[]} tokens
 */
function referencesSecretEnv(tokens) {
  return tokens.some((token) =>
    token.split(NAME_BOUNDARY).some(isSecretEnvName),
  );
}

/** @type {Array<{ reason: string, matches: (tokens: string[]) => boolean }>} */
const SEGMENT_RULES = [
  {
    reason:
      'recursive rm is only allowed on bare node_modules, dist or coverage. Ask Arnel to remove anything else.',
    matches: isUnsafeRecursiveRm,
  },
  {
    reason: 'find -delete removes files irreversibly.',
    matches: (tokens) =>
      tokens.some((token) => commandName(token) === 'find') &&
      tokens.includes('-delete'),
  },
  {
    reason:
      'gh pr merge is not allowed here: Mira merges after her code review (Arnel, 2026-09-28). Open the PR and message Mira.',
    matches: (tokens) =>
      tokens.some(
        (token, index) =>
          commandName(token) === 'gh' &&
          tokens
            .slice(index + 1)
            .some((arg, argIndex, args) => arg === 'pr' && args[argIndex + 1] === 'merge'),
      ),
  },
  {
    reason:
      'git worktree remove --force discards uncommitted work in the worktree.',
    matches: (tokens) => {
      const args = gitArgs(tokens, 'worktree');
      return (
        args?.[0] === 'remove' &&
        args.some((arg) => arg === '--force' || hasShortFlag(arg, 'f'))
      );
    },
  },
  {
    reason: 'git switch --discard-changes / --force discards uncommitted work.',
    matches: (tokens) =>
      gitArgs(tokens, 'switch')?.some(
        (arg) =>
          arg === '--discard-changes' ||
          arg === '--force' ||
          hasShortFlag(arg, 'f'),
      ) ?? false,
  },
  {
    reason: 'git reset --hard discards work. Use a WIP commit instead.',
    matches: (tokens) => gitArgs(tokens, 'reset')?.includes('--hard') ?? false,
  },
  {
    reason: 'git clean -f deletes untracked files.',
    matches: (tokens) =>
      gitArgs(tokens, 'clean')?.some(
        (arg) => arg === '--force' || hasShortFlag(arg, 'f'),
      ) ?? false,
  },
  {
    reason: 'force-pushing is never allowed.',
    matches: (tokens) =>
      gitArgs(tokens, 'push')?.some(
        (arg) =>
          arg.startsWith('--force') ||
          hasShortFlag(arg, 'f') ||
          (arg.startsWith('+') && arg.length > 1),
      ) ?? false,
  },
  {
    reason:
      'git push --all / --mirror pushes main and develop. Push a feature branch and open a PR.',
    matches: (tokens) =>
      gitArgs(tokens, 'push')?.some(
        (arg) => arg === '--all' || arg === '--mirror',
      ) ?? false,
  },
  {
    reason:
      'git commit -n is --no-verify, which skips checks and is never allowed.',
    matches: (tokens) =>
      gitArgs(tokens, 'commit')?.some((arg) => hasShortFlag(arg, 'n')) ?? false,
  },
  {
    reason:
      'real .env files hold secrets Arnel owns. Only .env.example may be referenced.',
    matches: referencesSecretEnv,
  },
];

/** Rules that depend on the project's git flow. */
const PROTECTED_BRANCH_RULE = {
  reason:
    'pushing to main or develop is not allowed. Push a feature branch and open a PR.',
  matches: (tokens) =>
    gitArgs(tokens, 'push')?.some((arg) => PROTECTED_REF.test(arg)) ?? false,
};

/** @type {Array<{ reason: string, matches: (command: string) => boolean }>} */
const COMMAND_RULES = [
  {
    reason: '--no-verify skips checks and is never allowed.',
    matches: (command) => /--no-verify(?![\w-])/.test(command),
  },
  {
    reason:
      'commits must not carry a Co-Authored-By or Claude attribution line.',
    matches: (command) => /co-authored-by/i.test(command),
  },
];

/**
 * @param {string} command
 * @param {{ flow?: string }} [options] `flow` defaults to `branches` (fails closed).
 * @returns {Decision}
 */
export function checkCommand(command, { flow = 'branches' } = {}) {
  const rules = [...SEGMENT_RULES];
  if (!FLOWS.has(flow) || flow === 'branches') rules.push(PROTECTED_BRANCH_RULE);
  for (const rule of COMMAND_RULES) {
    if (rule.matches(command))
      return { decision: 'block', reason: rule.reason };
  }
  const parts = segments(command);
  for (const tokens of parts) {
    for (const rule of rules) {
      if (rule.matches(tokens))
        return { decision: 'block', reason: rule.reason };
    }
  }
  return { decision: 'allow' };
}

if (isMain(import.meta.url)) {
  await runGuard((input) => {
    const command = pick(input, 'tool_input', 'command');
    if (command === undefined) return { decision: 'allow' };
    const projectDir =
      process.env.CLAUDE_PROJECT_DIR || pick(input, 'cwd') || process.cwd();
    return checkCommand(command, { flow: readFlow(projectDir) });
  });
}
