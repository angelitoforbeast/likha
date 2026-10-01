// PreToolUse guard for file tools (Read, Edit, Write, MultiEdit, Grep).
// Spec: docs/superpowers/specs/2026-09-26-project-foundation-design.md §7.
import fs from 'node:fs';
import path from 'node:path';
import { isMain, pick, runGuard } from './lib/hook-io.mjs';
import { findSecretEnvSegment, isSecretEnvName } from './lib/secret-env.mjs';

/** @typedef {import('./lib/hook-io.mjs').Decision} Decision */

const WRITE_TOOLS = new Set(['Edit', 'Write', 'MultiEdit']);
// Case-insensitive because macOS volumes usually are.
const HANDOFF_SPEC = /(^|\/)handoff\/[^/]+\/handoff\.md$/i;
// The guards and the settings that register them: editing these could switch the guards off.
const GUARD_CONFIG = /(^|\/)\.claude\/(hooks\/|settings(\.local)?\.json$)/i;

/**
 * @param {string} segment
 * @returns {Decision}
 */
function blockSecretEnv(segment) {
  return {
    decision: 'block',
    reason: `${segment} holds secrets Arnel owns. Update .env.example instead and tell Arnel what to set.`,
  };
}

/**
 * @param {string} toolName
 * @param {string} filePath
 * @returns {Decision}
 */
export function checkFileAccess(toolName, filePath, { exists = fs.existsSync } = {}) {
  const normalized = path.posix.normalize(filePath.replaceAll('\\', '/'));

  const secret = normalized.split('/').find(isSecretEnvName);
  if (secret !== undefined) return blockSecretEnv(secret);
  if (!WRITE_TOOLS.has(toolName)) return { decision: 'allow' };
  // The worker saves Mira's handoff text in its first commit (dev-kit 0.8.0): creating a
  // HANDOFF.md that doesn't exist yet is allowed; changing one never is.
  if (HANDOFF_SPEC.test(normalized) && !(toolName === 'Write' && !exists(filePath))) {
    return {
      decision: 'block',
      reason:
        'HANDOFF.md is read-only under the handoff protocol. Put questions in RESULT.md.',
    };
  }
  if (GUARD_CONFIG.test(normalized)) {
    return {
      decision: 'ask',
      reason:
        'This edit changes the project guards or their settings. Confirm this edit.',
    };
  }
  return { decision: 'allow' };
}

if (isMain(import.meta.url)) {
  await runGuard((input) => {
    const toolName = pick(input, 'tool_name');
    const glob = pick(input, 'tool_input', 'glob');
    if (toolName === 'Grep' && glob !== undefined) {
      const secret = findSecretEnvSegment(glob);
      if (secret !== undefined) return blockSecretEnv(secret);
    }
    const filePath =
      pick(input, 'tool_input', 'file_path') ??
      pick(input, 'tool_input', 'path');
    if (toolName === undefined || filePath === undefined) {
      return { decision: 'allow' };
    }
    return checkFileAccess(toolName, filePath);
  });
}
