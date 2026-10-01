// Shared I/O for Claude Code hooks. Hook protocol:
// exit 2 + stderr = block; exit 0 + JSON on stdout = structured decision; exit 0 + nothing = allow.
import { realpathSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

/** @typedef {{ decision: 'allow' } | { decision: 'block' | 'ask', reason: string }} Decision */

/**
 * Returns the string at the nested key path, or undefined.
 * @param {unknown} value
 * @param {...string} keys
 * @returns {string | undefined}
 */
export function pick(value, ...keys) {
  let current = value;
  for (const key of keys) {
    if (typeof current !== 'object' || current === null) return undefined;
    current = /** @type {Record<string, unknown>} */ (current)[key];
  }
  return typeof current === 'string' ? current : undefined;
}

/**
 * @param {string} metaUrl
 * @returns {boolean}
 */
export function isMain(metaUrl) {
  const entry = process.argv[1];
  if (entry === undefined) return false;
  // Node resolves symlinks for import.meta.url, so compare against the real entry path.
  let resolved = entry;
  try {
    resolved = realpathSync(entry);
  } catch {
    // Fall back to the raw path.
  }
  return metaUrl === pathToFileURL(resolved).href;
}

/** @returns {Promise<unknown>} */
export async function readHookInput() {
  let raw = '';
  for await (const chunk of process.stdin) raw += String(chunk);
  /** @type {unknown} */
  const parsed = JSON.parse(raw);
  return parsed;
}

/**
 * @param {Decision} result
 * @returns {never}
 */
function emit(result) {
  if (result.decision === 'block') {
    process.stderr.write(`Blocked by project guard: ${result.reason}\n`);
    process.exit(2);
  }
  if (result.decision === 'ask') {
    process.stdout.write(
      JSON.stringify({
        hookSpecificOutput: {
          hookEventName: 'PreToolUse',
          permissionDecision: 'ask',
          permissionDecisionReason: result.reason,
        },
      }),
    );
  }
  process.exit(0);
}

/**
 * @param {unknown} value
 * @returns {value is Decision}
 */
function isDecision(value) {
  const decision = pick(value, 'decision');
  if (decision === 'allow') return true;
  if (decision !== 'block' && decision !== 'ask') return false;
  return pick(value, 'reason') !== undefined;
}

/**
 * Runs the evaluator, failing closed if it throws or returns something that is not a
 * Decision (exit 1 would let the tool call through).
 * @param {(input: unknown) => Decision} evaluate
 * @param {unknown} input
 * @returns {Decision}
 */
export function decide(evaluate, input) {
  /** @type {unknown} */
  let result;
  try {
    result = evaluate(input);
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { decision: 'block', reason: `guard crashed: ${message}` };
  }
  if (!isDecision(result)) {
    return { decision: 'block', reason: 'guard returned an invalid decision' };
  }
  return result;
}

/**
 * Reads hook input, evaluates it, and exits with the decision. Fails closed on unreadable input.
 * @param {(input: unknown) => Decision} evaluate
 * @returns {Promise<never>}
 */
export async function runGuard(evaluate) {
  /** @type {unknown} */
  let input;
  try {
    input = await readHookInput();
  } catch {
    emit({ decision: 'block', reason: 'hook input was not valid JSON' });
  }
  emit(decide(evaluate, input));
}
