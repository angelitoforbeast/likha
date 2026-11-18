# 304 · Only the owner sees "The owner can read every conversation"

## Why
The owner, 2026-11-18, about the lock line in Likha Chat (spec 296): "kita ba nila to?" → "ok, ako lang dapat makakita".
Spec 296 put the line "The owner can read every conversation in Likha Chat" in every thread header and in the empty pane, for everyone (296's decision "the page says so"). He now wants only himself to see it. He also said, about reading every group chat: "no need naman na nandun ako sa member ng gc": he reads without being a member, and his reading should not show to the staff.

## Stories and cases
**OL-1 The line is for the owner only (P1).** The lock line shows only to the CEO (the same owner check `ChatAccess` already uses for "All conversations").
- Staff (any non-CEO user) see no lock line: not in the thread header, not in the empty pane. It is not in the HTML they receive (not hidden with CSS or JS).
- The CEO still sees it in both places, unchanged.
- Nothing else on the page changes for anyone.

**OL-2 The owner's reading leaves no trace (P1).** When the CEO opens or reads a conversation he is not a member of:
- members see no "Seen" head or "Seen by" entry for him, and no typing line or active dot from him in that conversation;
- no member row, "joined" line or system message is created;
- the poll and unread payloads that members receive carry nothing about him.
Test each of these. If one of them is already true, the test proves it; if one is not, fix it in the smallest way and say so in the result. His reading of a conversation he IS a member of works as before (members see his Seen head there).

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| The line stays for the CEO, worded as today | He wants to see it; only the audience changes |
| Server-side: staff never receive the line | A hidden element is still "kita" to anyone who looks |
| No migration, no setting row | A fixed rule (CEO only), not something to switch |

## Constraints
- Change `resources/views/chat/index.blade.php` (lines with the lock line) and, only if OL-2 needs it, the chat services/controllers. Keep the change small.
- Update the tests that pin the old rule (`tests/Feature/Chat/ConversationsTest.php` around line 18 and `tests/Feature/Chat/CeoTest.php` around line 85): staff must NOT see the line; the CEO must.
- Record the changed rule (296's "the page says so" is now "the owner's page says so") in this spec's result.
- Spec 303 is being built at the same time in `projects/likha-wt303` (sequence lanes). Do not touch its files.
- Comments in the language and style of the file around them. Tests go in `tests/Feature/Chat/`. The full suite adds no new failures compared with base fd835bca. Known base failures: ExampleTest, ItemTableFitScriptTest and SharedPagesBasePinTest `/owner/private`; the Chatblast Speed tests (SpeedMemory, SpeedPrewarm, BigBroadcast) may fail under load but pass alone.

## Threat model and risk
- No new input or route. It removes text from staff pages and may remove trace data from members' payloads.
- Risk tier: low.

## Allowed commands
- Through Herd PHP (`C:\Users\Forbeast\.config\herd\bin\php.bat`):
  - `composer.bat install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`
  - `php.bat artisan test` (with filters) and `vendor/bin/phpunit`
  - `php.bat artisan route:list` and `php.bat artisan view:cache`
- `npm ci` and `npm run build`.
- `php.bat artisan serve` on 127.0.0.1, with a local seeded sqlite database only (never the real database).
- A headless browser for screenshots of 127.0.0.1 pages only.
- `git add`, `git commit` and `git push -u origin feat/304-owner-only-lock-line`.

## Done when
1. Tests pass for OL-1 (staff: no line in the header or the empty pane; CEO: both) and OL-2 (no Seen, typing, active, member row or payload trace from the CEO in a conversation he is not in; his Seen still shows where he is a member). The full suite adds no new failures compared with base fd835bca.
2. Screenshots in `docs/design/304-owner-only-lock-line/`: a staff thread at 390 px without the line, and the CEO's view of the same thread at 1280 px with it.
3. The result lists what changed, what OL-2 found already true and what it fixed, and a merge-danger section (what ships, how to revert).
4. The branch is pushed. No merge, no deploy.

## Out of scope
- Letting the CEO post in a conversation he is not a member of.
- Any other chat change.

## Budget
Small job. At most 2 attempts at the same failing step, then stop and report.
