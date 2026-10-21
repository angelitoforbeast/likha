Small task: spec 078, about 30–45 minutes.

**Branch.** In this worktree, run `git checkout -b fix/078-sequence-simplify main`. Main is ffa70ba7, which is live with spec 070.

**Rules**
- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/078-sequence-simplify.md` and the result as `docs/specs/078-sequence-simplify.result.md`.

# Brief 078: sequences send at any hour and always skip outside 24 hours

## Why

The owner, 2026-10-19, on the sequence editor:
- On "Send from / Send until": "mali to, wala kaming paki kung anong oras e".
- On "Outside the 24-hour window": "skip na to".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Remove the send hours.** A step is due exactly at its delay. Remove the window logic from scheduling: `next_send_at` = the previous send (or the subscribe time) plus the delay. Remove the two time fields from the editor and the list. Keep the columns in the table (no migration needed). Ignore them, and give them a comment saying they are unused. | The owner. |
| **Always skip outside 24 hours.** A due step whose customer last messaged more than 24 hours ago is skipped, with an event, and the sequence continues. Remove the "Outside the 24-hour window" dropdown and the `stop` behaviour. Ignore the column. | The owner. |
| **Update the editor's help text:** "Each step runs at its delay. A step is skipped when the customer's last message is over 24 hours old (Messenger's rule)." | Clarity. |
| **Update the tests:** remove or adjust the window and stop tests. Add a test that a step due at 2 AM is sent at 2 AM, and one that the outside-24h case always skips. | — |

## Done when

- `php artisan test tests/Feature/Chatblast` passes.
- Report the commits.
