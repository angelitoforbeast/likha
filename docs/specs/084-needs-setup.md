Small task: spec 084, about 45–60 minutes. Start it after 086 is committed.

**Branch.** In this worktree, run `git checkout -b feat/084-needs-setup main`. If 082 is merged to main by then, branch from that main; otherwise branch from `main` as it is.

**Rules**
- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/084-needs-setup.md` and the result as `docs/specs/084-needs-setup.result.md`.
- Do a headless browser check at 1366 and 390 px, with screenshots in `docs/specs/084-needs-setup/`.
- Budget: 2 attempts per failing step, then stop and report.

# Brief 084: a "Needs setup" list of pages whose chatblast is blocked

## Why

Between 2026-10-17 and 2026-10-20, 4,200 chatblast messages were not sent because of page setup, and nobody noticed. The rows sit in `botcake_messages` with status `no_config`. By page:
- Xivana Agustin: 744, missing flow.shipping and field.message;
- Karla Chavez: 626, "Page not ready: needs a test send";
- Alyssa Aquino: 499, missing flow.shipping and field.message;
- Chelsea Mercado: 478, needs a test send;
- Kim Villanueva: 390, needs a test send;
- others.

The owner, 2026-10-20: when a new page isn't set up, the staff must be able to see that a problem is still unfixed.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **One list, from data that already exists.** Use the non-test `botcake_messages` rows of the last 3 days (Asia/Manila) with status `no_config`, `failed` or `field_mismatch`, grouped by page and reason. No new table and no new column. | The owner's lean-schema rule; the rows already say why. |
| **Where.** Show it as a red panel at the top of `/chatblast/page-settings` and of `/chatblast` (the main page). Its title is "Needs setup (N pages)". Each line shows the page, the reason in plain English, the count of unsent messages in the last 3 days, and the time of the latest one. A "Fix" link opens that page's row in page-settings, or the page-settings filter when the page has no row yet. If nothing is blocked, the panel is hidden. | The staff open these pages every day. |
| **Grouping of reasons:** "Not set up in page-settings" when the page has no Botcake ID or token; "Missing flow or message field"; "Needs a test send"; "Send failed" (`failed`, `field_mismatch`); and the 082 check reasons, if 082 is on the branch, such as "No flow named … in Botcake" or "Sent N, none delivered". Normalise the old Tagalog reasons ("walang …") to the same English groups. | One reason per line, readable. |
| **A row clears itself.** When a later build changes the row (`ready`, `sent` or `skipped`), it stops counting. A page drops off the list when it has no blocked row left in the window. | No manual dismiss to forget. |
| **Cost.** One grouped query with an index-friendly range on `created_at`. It is cached for 60 seconds. | The pages load often. |
| **Endpoint** for later alerts: `GET /chatblast/needs-setup.json`, behind the same auth as the page. It returns the same list (page, group, count, latest). | The monitoring spec will send it to Slack each morning. |

## Done when

- Tests:
  - grouping, including the Tagalog-to-English reasons;
  - the 3-day window;
  - a row that turned `sent` no longer counts;
  - the panel is hidden when the list is empty;
  - the JSON shape.
- `php artisan test tests/Feature/Chatblast` passes.
- The browser check shows the panel on both pages at both widths, with no page-level horizontal scroll.
- Report the commits.

## Out of scope

- Slack or Messenger alerts (the monitoring spec).
- `no_psid` rows. They are a matching issue, not page setup.
