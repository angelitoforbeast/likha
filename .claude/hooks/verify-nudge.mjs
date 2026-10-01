#!/usr/bin/env node
// Verification nudge (dev-kit 0.8.0). A Stop hook in the developer agents' frontmatter (it runs as
// SubagentStop): when the agent changed source files but its transcript shows no test run, it
// asks the agent once to run the tests before finishing. A nudge, not a gate: it never blocks
// twice (stop_hook_active), never errors, and stays silent when anything is unclear.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { isMain, pick } from './lib/hook-io.mjs';

const NOT_SOURCE = [
  /\.md$/i,
  /(^|\/)docs\//,
  /(^|\/)handoff\//,
  /(^|\/)\.claude\/agent-memory\//,
  /\.(test|spec)\.[^/]+$/,
  /(^|\/)(tests?|__tests__)\//,
];
const TEST_COMMAND =
  /\b(?:(?:pnpm|npm|yarn|bun)\s+(?:run\s+)?test\b|vitest\b|jest\b|node\s+--test\b|turbo\s+run\s+test\b|artisan\s+test\b|pytest\b|phpunit\b|go\s+test\b|cargo\s+test\b)/;

/** Changed files from `git status --porcelain` that are source, not docs, tests or memory. */
export function changedSource(status) {
  return status
    .split('\n')
    .filter((l) => l.length > 3)
    .map((l) => {
      const p = l.slice(3);
      return (p.includes(' -> ') ? p.split(' -> ').pop() : p).replace(/^"|"$/g, '');
    })
    .filter((f) => !NOT_SOURCE.some((re) => re.test(f)));
}

/** True when the transcript (JSONL) holds a Bash call that runs a test command. */
export function ranTests(transcript) {
  for (const line of transcript.split('\n')) {
    let entry;
    try {
      entry = JSON.parse(line);
    } catch {
      continue;
    }
    const content = entry?.message?.content;
    if (!Array.isArray(content)) continue;
    for (const c of content) {
      if (c?.type === 'tool_use' && c.name === 'Bash' && TEST_COMMAND.test(String(c.input?.command ?? ''))) {
        return true;
      }
    }
  }
  return false;
}

/** The hook output asking for a test run, or null to let the agent stop. */
export function nudge({ event, stopHookActive, changed, testRan }) {
  if (stopHookActive || testRan || changed.length === 0) return null;
  const shown = changed.slice(0, 5).join(', ');
  const more = changed.length > 5 ? ` and ${changed.length - 5} more` : '';
  return {
    hookSpecificOutput: {
      hookEventName: event,
      additionalContext:
        `Source files changed (${shown}${more}) but no test run shows in this session. Before ` +
        'finishing, run the affected tests with the command in CLAUDE.md `## Stack` and report the ' +
        'result (or say why no test applies).',
    },
  };
}

if (isMain(import.meta.url)) {
  let raw = '';
  process.stdin.on('data', (c) => (raw += c));
  process.stdin.on('end', () => {
    try {
      const input = JSON.parse(raw);
      const cwd = pick(input, 'cwd');
      const transcriptPath = pick(input, 'agent_transcript_path') ?? pick(input, 'transcript_path');
      if (!cwd || !transcriptPath) return;
      const status = execFileSync('git', ['status', '--porcelain', '--untracked-files=all'], {
        cwd,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'ignore'],
      });
      const out = nudge({
        event: pick(input, 'hook_event_name') ?? 'SubagentStop',
        stopHookActive: pick(input, 'stop_hook_active') === true,
        changed: changedSource(status),
        testRan: ranTests(fs.readFileSync(transcriptPath, 'utf8')),
      });
      if (out) process.stdout.write(JSON.stringify(out));
    } catch {
      // never block a stop
    }
  });
}
