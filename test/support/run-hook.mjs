import { spawn } from 'node:child_process';
import path from 'node:path';

/**
 * @typedef {{ code: number | null, stdout: string, stderr: string }} HookResult
 */

/**
 * Runs `.claude/hooks/<script>` with `input` on stdin, the way Claude Code does.
 * @param {string} script
 * @param {string | object} input
 * @param {NodeJS.ProcessEnv} [env]
 * @returns {Promise<HookResult>}
 */
export function runHook(script, input, env = {}) {
  const scriptPath = path.resolve('.claude/hooks', script);
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [scriptPath], {
      env: { ...process.env, ...env },
    });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (chunk) => {
      stdout += chunk.toString();
    });
    child.stderr.on('data', (chunk) => {
      stderr += chunk.toString();
    });
    child.on('error', reject);
    child.on('close', (code) => {
      resolve({ code, stdout, stderr });
    });
    child.stdin.end(typeof input === 'string' ? input : JSON.stringify(input));
  });
}
