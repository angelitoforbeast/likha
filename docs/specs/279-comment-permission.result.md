# 279 Show "Walang comment permission" from the gateway, and keep the page on the gateway — result

Branch `feat/279-comment-permission` (from `81c26d5e`). Not deployed. No migrations.

## Summary
- The gateway page check's `comments` map is kept per page and gateway in a new system page setting
  `<gateway>.comments` (JSON): `forbeast.comments`, `incepxionbot.comments`.
- A comment reply or private reply answered with `comments_not_allowed` (any HTTP status, from `error`, `error.code` or a
  top-level `code`) gets its own part status `no_permission`, records `no_permission` for the page's route, and the
  event is `skipped` with reason `comments_not_allowed` (not `failed`, not retried).
- A later successful comment reply on that route sets it back to `ok`.
- The page list shows "Walang comment permission" beside the Replies via / gateway controls; the comment Check shows a
  `warn` with the same words.
- ForbeastWatch never moves a page because of comments; its `apps` check is unchanged.

## Per story
1. **Keep `comments` like `apps`.** `ForbeastPageSetup::commentStatuses(Response)` reads `comments` (at most 10 keys,
   keys `^[a-z0-9_]{1,30}$`, values `ok` | `no_permission`, anything else null; missing or not an object = null =
   nothing changes). `keepComments($page, ?array)` writes `<gateway>.comments` only when the JSON changes (compared
   before calling `PageSettings::set`, so no lock or transaction when nothing changed). Called from `connect()`, `retest()`
   (via `probe()`, which now also returns `comments`), `ForbeastWatch::health()` (next to `keepApps`, in the same
   try/catch) and the Replies via address check in `ChatbotController::provider()`. One choice beyond `apps`: a `null`
   for a key does not erase a known `ok`/`no_permission` already stored, so a refusal recorded by story 2 is not wiped by a
   gateway that does not know yet; a known value from the gateway always wins. Read with `comments($page)`,
   `commentRoute($page)` and `commentStatus($page)`. Both keys added to `PageSettings::SYSTEM_KEYS`.
2. **Refused comment.** `GatewayComments`: code `comments_not_allowed` → status `no_permission` (checked first, whatever
   the HTTP status), error text `Walang comment permission: Forbeast said "comments_not_allowed: …" (HTTP 403)`, and
   `ForbeastPageSetup::markComment($page, false)` for the active gateway and the page's route (forbeast gateway: the
   address `chatbot.gateway_app`; other gateway: its name, as `Gateways::sendRoute`). `CommentReplies::handle()`: a part
   with `no_permission` is not `failed`; when no part was sent, the event is `skipped`, reason `comments_not_allowed`
   (`REASONS`: "Walang comment permission (Meta refused comments for this page and app; messages still go through)").
   Each part is tried once (no retry: the event leaves `queued`; HandleComment run again does nothing). Health counts
   it as neither replied nor failed. The old CommentCheck "i-connect ulit" (X) no longer reads these refusals' Meta codes
   (errors starting `Walang comment permission:` are excluded). Error texts are now capped at 255 letters (the column size).
3. **Back to ok.** A 2xx comment reply calls `markComment($page, true)`: writes `ok` for that route only when it was
   `no_permission` (no write per answered comment, none when unknown). Other routes are untouched.
4. **Shown.** `/chatbot/data` rows carry `comment_permission` (`ok` | `no_permission`) and `comment_route` only when the
   status is known (so the row JSON is unchanged otherwise; `ChatbotDataSpeedTest` compares it). The view's
   `commentPermissionNote(r)` adds an amber "Walang comment permission" line (title names the route) after
   `repliesViaSelect(r), gatewaySelect(r)` when the page replies via the gateway and the status is `no_permission`.
   `CommentCheck::rules()` adds `warn` "Forbeast (Incepxion): Walang comment permission — hindi masasagot ang comment;
   tuloy ang messages" (field = gateway label) before the older permission checks.
5. **No switch.** ForbeastWatch only stores `comments`; it has no comment-based condition. Test: five refusals plus
   four health checks reporting `no_permission` on the page's route leave the page on the gateway with no auto_switch
   row; `apps` = `reconnect_needed` still moves it (unchanged L628-633 logic).

## API contract
- Read: `GET /v1/pages/{id}` → `comments: {"<app_key>": "ok" | "no_permission" | null}` (optional).
- Read: comment reply / private reply error bodies with `comments_not_allowed` in `error` (text), `error.code`, or `code`.
- `/chatbot/data` row, new optional keys: `comment_permission`, `comment_route`.
- Page setting keys added (system-only, JSON): `forbeast.comments`, `incepxionbot.comments`.

## Test output
New file `tests/Feature/Chatblast/CommentPermissionTest.php` (10 tests, 95 assertions).

Before (base app code, new tests): `php.exe vendor/bin/phpunit tests/Feature/Chatblast/CommentPermissionTest.php`
```
Tests: 10, Assertions: 22, Errors: 6, Failures: 3.
S_279_1 (x2), S_279_3 back-to-ok, S_279_3 check, S_279_5: Call to undefined method ForbeastPageSetup::comments()/markComment()/commentStatus()
S_279_4: Undefined array key "comment_permission"
S_279_2 (3 data sets): Failed asserting that two arrays are identical. (the refused comment was a generic failure, not skipped/no_permission)
```
The one test passing before is `S_279_3_a_success_on_an_unknown_route_writes_nothing` (a negative case).

After:
```
OK (10 tests, 95 assertions)
```
Related files: `CommentRepliesTest`, `RepliesViaChoiceTest`, `ForbeastAutoSwitchTest` → OK (95 tests, 510 assertions);
`ChatbotContentCheckTest` → OK (50 tests); `ChatbotDataSpeedTest` → OK.

Full suite: `php.exe -d memory_limit=2G vendor/bin/phpunit`
```
Tests: 5273, Assertions: 82872, Failures: 3, PHPUnit Deprecations: 106, Skipped: 6.
1) ExampleTest::test_the_application_returns_a_successful_response
2) Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order
3) OwnerPrivate\SharedPagesBasePinTest::test_S_42_4 … data set "/owner/private"
```
All three also fail on the base code in this worktree (1 and 2 re-run with base `app/` and `resources/`: same 2
failures; 3 is in the known base list). In this worktree only one of the three known SharedPagesBasePinTest data sets
fails. No new failures.

## Head commit
See the branch head (this file is in that commit).

## Notes for release
- No migration. Works with an older gateway (no `comments` field = nothing stored, nothing shown).
- incepxion.pro runs the same code; its release is separate.
- For a page on the non-forbeast gateway (`incepxionbot`), a refusal is recorded under the key `incepxionbot` (the
  gateway name, as `Gateways::sendRoute`); keys from that gateway's own `comments` map are stored as sent, but only the
  page's route key is shown.

## Questions
None.
