# 149 Comment replies: result

## Plan

Storage: a small `chatbot_comment_events` table (one row per comment), not `chatbot_replies`. Reasons:
- `chatbot_replies.kind` is `varchar(10)`: `comment_reply` does not fit, and widening the column rebuilds a large MySQL table.
- `chatbot_replies.psid` is required, and a comment may have no `from_id`.
- One row per comment holds the dedupe (unique `comment_id`), the skip reason and both results in one place.

The Replies page gets a "Comments" view that reads this table; Health counts from it.

Event path:
1. `/api/gateway/events`: the same signature check as the other gateway events; an unknown page gets 401.
2. `HandleGatewayEvent` sees `type: comment`.
3. `CommentReplies::receive()` stores the row. `comment_id` is unique, so a second copy is a duplicate.
4. Job `HandleComment` (chatbot queue) → `CommentReplies::handle()`:
   - checks: Forbeast provider, bot On, top level, not page-authored, not one of ours, values set;
   - the per-page slot: 20 per minute; when the minute is full the job is released to the next minute;
   - then the private reply (Comment PM), then the public reply (one random Comment Reply).

Sends go through `GatewayComments` (the page's Forbeast token, else `GATEWAY_KEY`, like `GatewayProvider`). Random seam: `CommentReplies::pickUsing(callable)`.

| Task | Covers | Tier | Side |
|---|---|---|---|
| T1 Columns: `comment_reply_1..3` and `comment_pm` as default botfields (long text), with paste-mapping descriptions. | .1 | medium | backend |
| T2 Comment intake and replies: the migration, the `HandleGatewayEvent` comment branch, `CommentReplies`, `HandleComment`, `GatewayComments`. | .2 .3 .4 .5 .6 .7 .8 .10 | high (a new event type from the public, publishing to Facebook) | backend |
| T3 Logs: a "Comments" filter on Replies (server plus a small view change) and Health counts (comments in, replied, failed). | .9 | medium | backend + frontend (one small view change, done in the same slice) |
| T4 Check: Forbeast's `GET /v1/pages/{id}` does not report scopes today, so the permission check is skipped and noted. | .11 | low | — |

## Progress
- [x] T1 Columns: green, checked by the main session. Reviewed together with T2 and T3 in the one adversarial review.
- [x] T2 Comment intake and replies:
  - The adversarial review found 1 major: no test for the re-check when a waiting job runs.
  - It was fixed in 1 loop, and the re-check found nothing still open.
- [x] T3 Logs on Replies and Health: reviewed with T2.
- [x] T4 Check (.11): not built; see Open points.
- [x] Review: `skeptic-reviewer`, adversarial depth, on `5a4cbd03..HEAD`. The major and 5 minors are fixed; the other minors are in `TODO.md` with reasons.
- [ ] Browser check: not run. This worktree has no running app or database. The Replies "Show" select and the comment detail popup are covered only by the markup and JSON tests.
- Deviation: the kit wants a spec review before the plan for a high-risk spec. I skipped it because the brief said "write the plan, commit, continue". The code got the adversarial review instead.

## Files
- New:
  - `database/migrations/2026_11_08_100000_create_chatbot_comment_events_table.php`
  - `app/Models/ChatbotCommentEvent.php`
  - `app/Services/Chatbot/CommentReplies.php`
  - `app/Services/Chatbot/GatewayComments.php`
  - `app/Jobs/HandleComment.php`
  - `tests/Feature/Chatblast/CommentRepliesTest.php`
- Changed:
  - `app/Services/ChatbotFields.php`: the 4 new default columns, kind `long`.
  - `app/Services/Chatbot/PasteFieldAi.php`: the one-line descriptions.
  - `app/Jobs/HandleGatewayEvent.php`: the comment ID fields in `fields()`, and the `type: comment` branch.
  - `app/Http/Controllers/ChatbotTabsController.php`: `kind=comments` on `/chatbot/replies/data`.
  - `resources/views/chatbot/replies.blade.php`: the Show select and the comment detail popup.
  - `app/Services/Chatbot/BotHealth.php` and `resources/views/chatbot/health.blade.php`: the three comment counts.
  - `tests/Feature/Chatblast/ChatbotPagesTest.php`: S-081's column order now includes the 4 new columns.
  - `qa/stories.md` (S-149) and `TODO.md`.

## Columns
`comment_reply_1`, `comment_reply_2` and `comment_reply_3` (labels Comment Reply 1, 2, 3) and `comment_pm` (label Comment PM).
- They are default botfields in `page_settings` (provider `chatbot`), of kind `long`, so they open in the modal editor.
- They sit after After Order 4 and before the columns added with "+ Column".
- An empty value turns that part off.

## Event handling path
1. **Intake.** `POST /api/gateway/events` runs the same size, page and signature checks as the other gateway events: an unknown page gets 401, and so does a bad or missing signature.
2. **Routing.** `HandleGatewayEvent` (queue `chatbot-high`) sees `type: comment` and calls `CommentReplies::receive()`. The comment text and `from_name` are never kept.
3. **Store.** `receive()` inserts one row into `chatbot_comment_events`. `comment_id` is unique: the second copy is counted `duplicate`.
4. **Skips on arrival:**
   - a reply to a reply (`is_reply`);
   - written by the page (`is_page_author`, or `from_id` = the page);
   - one of our own replies (`reply_comment_id`);
   - a Botcake page, or the bot is Off.

   Every other comment dispatches `HandleComment` (queue `chatbot`).
5. **`HandleComment` → `CommentReplies::handle()`:**
   - It checks the page again (provider, Off) and the values (`not_set`).
   - It takes a slot: 20 comments per minute per page (a cache counter). When the minute is full, the job is released to the next minute, for up to 6 hours. `maxExceptions = 1`, so an error is never retried.
   - It claims the row with a compare-and-set (`queued` → `sending`), sends the private reply (Comment PM), then the public reply (one random variant), and records each result.
   - A job that fails for good runs `failed()`, which marks the row `failed` and sends nothing.
6. **Variables** are filled the way FlowRun does it: the botfields first fill each other one level deep, plus `page_name`, `page_id` and `current_date`. There is no name variable.
7. **Sending.** `GatewayComments` sends with the page's Forbeast token (else `GATEWAY_KEY`). An `already_replied` answer (`error`, or Meta #10900) is recorded and not retried.

## Random seam
`CommentReplies::pickUsing(callable|null)`: the callable gets the filled variants (variables already filled) and returns the one to send. `null` uses `random_int`.

## Logs
- **Where:** table `chatbot_comment_events`, one row per comment, with:
  - `status` and `reason`;
  - `pm_status`, `pm_text`, `pm_error`;
  - `reply_status`, `reply_text`, `reply_error`, `reply_comment_id`;
  - `received_at` and `handled_at`.
- **Replies:** Show: Comments (`/chatbot/replies/data?kind=comments`), with the same date, page, status and search filters. Every value is shown as text.
- **Health:** Comments in, Comments replied and Comments failed, in the tiles and the JSON.
- **No comment text** is stored or logged. The S-149.9 test listens to every log line.
- **Counters:** the `gateway-events` counters also count `comment`, `duplicate` and `no_id`.

## Story changes
- **S-081 (`ChatbotPagesTest`):** the expected column order after Picture now includes the 4 comment columns, before the "+ Column" columns. The new defaults require this.
- **S-149.3:** the brief says "all replies empty → nothing sent". I read that with Decision 1 ("Empty = that part is off"):
  - With no Comment Reply, the private message still goes.
  - Only when all four columns are empty is the comment skipped as "not set".

  Each of these has its own row in the test table.
- **S-149.11:** not built; see Open points.

## Evidence per case
| Case | Test | Result |
|---|---|---|
| S-149.1 | `test_S_149_1_the_four_comment_columns_are_editable_with_history_and_in_the_paste_mapping` | pass |
| S-149.2 | `test_S_149_2_a_comment_gets_the_private_message_first_then_one_random_public_reply`, `test_S_149_2_a_botfield_inside_a_comment_value_is_filled_too` | pass |
| S-149.3 | `test_S_149_3_an_empty_column_turns_its_part_off` (3 rows) | pass |
| S-149.4 | `test_S_149_4_and_5_…` (Botcake page, bot Off) | pass |
| S-149.5 | `test_S_149_4_and_5_…` (reply, page-authored, ours) | pass |
| S-149.6 | `test_S_149_6_the_same_comment_twice_is_handled_once` | pass |
| S-149.7 | `test_S_149_7_already_replied_is_recorded_and_not_retried`, `test_S_149_7_a_failed_send_is_recorded_failed_and_not_retried` (no answer, ok:false, from_id null) | pass |
| S-149.8 | `test_S_149_8_the_21st_comment_in_a_minute_waits_for_the_next_minute` (released for 45 s at 10:00:15, sent at 10:01), `test_S_149_8_a_waiting_comment_is_skipped_when_the_page_changed_before_its_turn` (2 rows) | pass |
| S-149.9 | `test_S_149_9_comments_show_on_replies_with_a_filter_and_are_counted_on_health_without_comment_text_in_the_log` | pass |
| S-149.10 | `test_S_149_10_an_unknown_page_or_a_bad_signature_is_refused` | pass |
| S-149.11 | — | not built |

Red runs:

| Slice | First failure |
|---|---|
| T1 | `S_149_1`: `comment_reply_1 Failed asserting that two arrays are identical` |
| T2 | `Class "App\Services\Chatbot\CommentReplies" not found`, and "File does not exist" for the migration |
| T3 | `S_149_9`: `Failed asserting that two arrays are identical` (no comment rows yet) |
| Fix loop | The nested-botfield test: `Failed asserting that two strings are identical` |

In the fix loop, the re-check and failure-path tests passed on the existing code. They pin behaviour that already worked but had no test, which is what the major asked for.

Suite `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode`:
- Final: `OK, but there were issues! Tests: 1479, Assertions: 8612, PHPUnit Deprecations: 75, Skipped: 1`. No failures.
- Baseline at 5ff034d1: `Tests: 1459`, no failures. So no new failures.

## Commits
- `5a4cbd03` docs: spec 149 and plan for comment replies
- `ca0c0d5b` feat: Comment Reply 1-3 and Comment PM botfield columns with paste-mapping descriptions (spec 149)
- `4ab79866` feat: comment events from Forbeast get one private message and one random public reply, 20 per minute per page (spec 149)
- `5d41e1a8` feat: comments on the Replies page (Show: Comments) and comment counts on Health (spec 149)
- `2209c3bc` fix: comment values fill nested botfields, re-check and failure paths pinned by tests, a dead job shows failed (spec 149)
- plus the docs commit with this result, the S-149 stories and `TODO.md`

## Open points
- **S-149.11 is skipped.** Forbeast's `GET /v1/pages/{id}` (used by connect and Test) does not report the token's scopes, so Check cannot tell whether the comment permissions are there. Add the check once Forbeast reports scopes.
- **Migration:** `php artisan migrate --force` must run with the deploy. Until it does, comment events are only counted (`no_id`).
- **Forbeast contract assumptions to confirm** against its handoff 014:
  - top-level comments come with `is_reply: false`;
  - the reply answer carries the new `comment_id` (used to skip our own replies);
  - errors use `error: "already_replied"` and/or `meta_code: 10900`;
  - a 2xx with `ok:false` counts as failed.
- **The 20-per-minute limit counts comments, not sends.**
- **Comment send failures do not trigger the Forbeast auto-switch** (`ForbeastWatch`).
- **Accepted review minors:** in `TODO.md` under "149".

149: done — top-level comments on Forbeast pages get one Comment PM private message and one random Comment Reply, set per page in /chatbot/pages, at most 20 per minute, logged on Replies and Health; S-149.11 skipped because Forbeast does not report scopes.

## Amendment 149-1

| Change | Who decided, why |
|---|---|
| **Connected column filter becomes a per-bot condition filter.** Replace the Connected header filter's value checkboxes (Botcake / Forbeast / None from spec 148) with one row per bot: `<Bot name>  ( Lahat | May | Wala )` (a 3-way segmented control; default Lahat), plus a checkbox **"Walang kahit anong bot"**, plus **I-reset**. "May X" = the page's complete connections include X; "Wala X" = they do not. Rows AND together, and AND with the other column filters. "Walang kahit anong bot" = the page has no complete connection (it overrides the per-bot rows while ticked). The bot rows come from the list of providers the page data already knows (Botcake, Forbeast today; a future provider appears automatically, no filter code change). Keep the panel's look consistent with the other header filters (same panel, sticky header, no horizontal scroll, usable at 390 px); the other columns keep their Sheets-style checkbox filters. | The owner, 2026-10-25: "possible up to 5 bots… gusto ko mashow lang, botcake, or yung walang botcake, or yung walang bot" then "okay go mo na". |

Tests (markup/JS-logic level like the existing chatbot page tests; if the filter logic lives in a JS helper with a node test, add cases there): May Botcake; Wala Forbeast; May Botcake + Wala Forbeast; Walang kahit anong bot; I-reset restores all; a third provider in the data shows a third row. Update S-148.11 (story change, listed) and add S-149 cases for this. Re-run the brief's test folders (no new failures). End with "149-1: <done|blocked> — <one line>".

## Amendment 149-2

| Change | Who decided, why |
|---|---|
| **Health page split by bot.** On /chatbot/health add a filter **"Bot: Lahat / Botcake / Forbeast"** (list built from the providers the data knows, like 149-1) that scopes every tile, list and graph by the page's provider (`chatbot.provider`; pages without the setting count as Botcake), and a **comparison table** with one row per bot for today and yesterday: inbound chats, answered %, first reply median and p90, failed % (without customer-not-reachable) with the top reasons, AI calls failed %, comment events in / replied (from this spec), and the number of automatic switches to Botcake (`chatbot.auto_switch` rows of the day). A page's provider is taken as of now (state the limitation in the result: a page that switched during the day counts entirely under its current bot). Same access, cache and refresh rules as the Health page today; no horizontal scroll. | The owner, 2026-10-25: moved 7 Likha pages to Forbeast at 17:41 and asked to monitor and compare with Botcake: "sige lagyan mo filter". |

Tests: the filter scopes the tiles (a Forbeast page's chats count only under Forbeast); the comparison rows' numbers for a seeded day; a page without a provider setting counts as Botcake. End with "149-2: <done|blocked> — <one line>".

## Amendment 149-3

Align with the contract Forbeast handoff 014 actually delivered.

| Change | Who decided, why |
|---|---|
| **Event**: Forbeast posts `{"kind":"event","event_id":42,"page_id":"…","type":"comment","comment_id":"900_501","post_id":"…_900","parent_id":"…_900","message":"How much po?" or null (sticker/photo only),"created_time":1761000000 (unix s),"from_id":null|"…" (NOT a psid),"from_name":null|"…","is_reply":false,"is_page_author":false,"received_at":"…Z","attempt":1}`, signed like every gateway event, dedupe `<page_id>:c:<comment_id>`, only verb add, Forbeast's own replies never sent back. Make sure HandleGatewayEvent passes these fields through and a null `message` is handled (still reply: the PM/reply text does not depend on the comment text). | The reviewer, from Forbeast 014's RESULT "Contract for the external system". |
| **Endpoints**: `POST {same base as the existing Forbeast message sends}/v1/pages/{page_id}/comments/{comment_id}/reply` `{"message"}` (1–8000) → 200 `{"ok":true,"comment_id"}`; `…/private-reply` `{"text"}` (1–2000) → 200 `{"ok":true,"message_id","recipient_id"}` (Forbeast documents the paths as `/api/v1/…`; reuse exactly how GatewayProvider builds the existing send URL from GATEWAY_URL so the prefix matches). Errors and handling: 409 `already_replied` (meta_code 10900) → record, no retry; 504 `unknown_outcome` → record as "unknown", **never retry** (Meta may have posted it); 502 `meta_error` → failed, no retry; 429 → retry later within the 20/min budget; 401/403/404/422 → failed with the error code as the reason. | Same. |

Tests: a 504 unknown_outcome is not retried and is logged as unknown; a 429 is retried later; a null-message comment still gets the PM and reply. End with "149-3: <done|blocked> — <one line>".

## Result of the three follow-up changes

### 149-1 Connected filter per bot
- **Data:** `/chatbot/data` now returns `bots` (`ChatbotConnections::BOTS` = Botcake, Forbeast). `connected()` takes its labels from that list.
- **Page** (`resources/views/chatbot/index.blade.php`): the Connected header filter opens the same `#hf` panel without search or Check all. The panel holds:
  - one row per bot with Lahat | May | Wala buttons (`aria-pressed`);
  - "Walang kahit anong bot", which greys out and overrides the rows;
  - I-reset.

  The other columns keep their checkbox filters. The Connected rule ANDs with them in `visible()`.
- **Logic:** one pure helper, `CF` (`bots`, `active`, `match`, `reset`), between `// connected-filter:start` and `:end`.
  - The bot rows are `CF.bots(data.bots, rows)`: the data's list plus any name found in a row's `connected`. A new provider therefore needs no filter code.
  - Clicks stop propagation before the repaint, so the panel doesn't close.
  - Focus goes back to the same control.
- **Tests** (`ChatbotConnectedFilterTest`): the helper is taken out of the rendered page and run in Node. The cases are May Botcake, Wala Forbeast, May Botcake + Wala Forbeast, Walang kahit anong bot, I-reset, and a third provider. The test also runs `node --check` on the page's whole script, and checks the markup.
- **Red run:** `the helper is on the page — Failed asserting that 0 is identical to 1`, and `json('bots')` was null.
- **Story changes:** S-148.11 now reads "Connected is set to May Forbeast (the per-bot filter)" instead of "Connected = Forbeast only", with the Connected logic proven by `test_S_149_12…`. New: S-149.12, S-149.13, and S-149.14 (390 px, browser, not run).

### 149-2 Health by bot
- **Filter:** `/chatbot/health?bot=Botcake|Forbeast` (select "Bot: Lahat / Botcake / Forbeast", built from `ChatbotConnections::BOTS`).
  - It scopes every tile, graph (days, hourly) and list (slowest, failures). An unknown value is ignored.
  - The bot filter goes into the cache key (5 minutes, same as today), and it is kept on threshold save.
- **Each page's bot** comes from `BotHealth::botOfPages()`: `chatbot.provider` = gateway means Forbeast; anything else, or no setting, means Botcake.
- **Compare bots table:** one row per bot for today and one for yesterday, with:
  - inbound, answered %, first reply median and p90;
  - failed % without not reachable, plus the top 3 reasons;
  - AI failed %;
  - comments in / replied;
  - "Switched to Botcake".

  The table follows the Page filter but not the Bot filter. It scrolls inside its card, so the page itself never scrolls sideways.
- **Switch counts:** taken from every `chatbot.auto_switch` row created that day with result `switched`, active or not, and shown on the row of the bot the page left (Forbeast).
- **Limitations, also stated on the page:**
  - A page's bot is the one it has now, so a page that switched during the day counts all day under its current bot.
  - With a Bot picked, pages missing from the chatbot page map (no Botcake ID) are left out.
  - Comments in also counts waiting, skipped and unknown comments.
- **Tests** (`ChatbotHealthByBotTest`): the filter scopes the tiles and the failures list; a page without a provider setting counts as Botcake; the compare numbers for a seeded day and the day before are checked by hand.
- **Red run:** `Undefined array key "compare"`, and the tiles were not scoped.

### 149-3 The contract Forbeast delivered
- **Event:** the test event now has Forbeast's real shape: `kind`, `event_id`, unix `created_time`, `received_at`, `attempt`.
  - `HandleGatewayEvent::fields()` passes `comment_id`, `post_id`, `parent_id`, `from_id`, `is_reply` and `is_page_author` through.
  - The comment text is still never kept, and a `message: null` comment is answered as usual.
  - Dedupe stays the unique `comment_id`. Facebook comment ids are unique across pages, so this matches `<page_id>:c:<comment_id>`.
- **URLs:** built like `GatewayProvider` builds the message send, from the same base (`GATEWAY_URL` + `/v1/pages/…`), so the `/api` prefix matches.
- **Answers:**

  | Answer | What happens |
  |---|---|
  | 409 `already_replied` / Meta #10900 | Recorded, not retried. |
  | 504 or `unknown_outcome` | That part is `unknown`, never sent again. The other part still goes, and the row is `unknown`; Replies can filter on it. |
  | 502, 401, 403, 404, 422 | `failed`, with the error code in `reason`, not retried. |
  | 429 | Nothing is recorded for that part. The parts already done are kept, the row goes back to `queued`, the page's minute counts as full (so its other comments wait too), and the job is released to the next minute. Only the part that is left is sent, within the 20 per minute and the 6-hour limit. |

- **Tests:**
  - `test_S_149_17…`: 504 recorded as unknown, no second private reply, Replies filter; and the 5 refused codes.
  - `test_S_149_18…`: 429 waits a minute and sends only what is left; a failed PM keeps its code across a 429 on the reply.
  - `test_S_149_19…`: a null message.
- **Red runs:** the 504 test failed with `Failed asserting that two arrays are identical` (the part showed failed); the 429 test with `Job was expected to be released, but was not`; the refused codes with `reason` null; the PM 403 + reply 429 test with `'forbidden'` expected, got `null`. The null-message test passed at once, since the comment text was never used: it pins existing behaviour.
- **Changed:** `GatewayComments.php` (unknown, throttled, code), `CommentReplies.php` (sends only the part left, `throttled()`, status and reason), the Replies status option "Unknown (comments)", and the comments in the unreleased migration (the schema is unchanged).

### Review
- `skeptic-reviewer` reviewed `dc2a0635..HEAD`: adversarial depth on 149-3, standard depth on 149-1 and 149-2. It found no blocker and no major.
- Minors fixed:
  - a debug line left in a test;
  - a failed PM's code lost when the reply then got a 429;
  - the Health note about unknown comments.
- The rest are in `TODO.md` under "149".
- Browser check: not run (no app or database in this worktree). S-148.11 (browser part) and S-149.14 are still open for a browser pass.

### Commits
- `dc2a0635` docs: three follow-up changes for spec 149 saved in the result
- `c4a80cd1` feat: Connected filter on /chatbot/pages with Lahat, May and Wala per bot, no-bot and reset (spec 149)
- `4c5d91a9` feat: Health filter by bot and a per-bot comparison for today and yesterday (spec 149)
- `739f1b7c` feat: comment sends follow the delivered Forbeast contract: 504 unknown never retried, 429 waits a minute, error codes as reasons (spec 149)
- `47ec43b9` fix: a failed private message keeps its error code when the reply waits for a 429; Health notes unknown comments (spec 149)

### Suite
`tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode` on 47ec43b9: `OK, but there were issues! Tests: 1492, Assertions: 8683, PHPUnit Deprecations: 75, Skipped: 1`. No failures (baseline 1459 at 5ff034d1, no failures), so no new failures.

149-1: done — the Connected filter has Lahat / May / Wala per bot (from the data), "Walang kahit anong bot" and I-reset; the logic is tested in Node, the browser pass is not run.
149-2: done — Health filters by bot (pages without a provider count as Botcake), and Compare bots shows today and yesterday per bot; a page counts under its bot as of now.
149-3: done — comment sends follow Forbeast's delivered contract: 504 is unknown and never retried, 429 waits for the next minute and sends only what is left, refusals keep the error code, and null-text comments are answered.

## Amendment 149-4

A live bug, the owner said "oo apply na".

### What happened (live, 2026-10-25 17:50–17:56, Venice Lim on Forbeast)
A customer sent voice messages. Forbeast delivered every one to Likha within 1 s (`botcake_chats.payload.source = gateway`, text "[Audio]") and the bot answered them (n100, 17:50:35 … 17:52:24). Botcake's chat block also delivered copies (`source = default_reply`, text "[audio] https://…" ~450 chars, no message_id). `ForbeastWatch` pairs a Botcake copy with its Forbeast twin by the same text (or mid) within TWIN_MINUTES; the texts differ for attachments, so at 17:53:04 the `inbound` condition fired ("1 Botcake chat(s) with no Forbeast copy after 120 seconds"), switched the page to Botcake, and the waiting standby copies then ran, so the customer got 7 more replies (n21, n101 × 6) for messages Forbeast had already answered.

### Fix
| Change | Why |
|---|---|
| **Pair by kind and time, not exact text.** Normalise both copies: text messages compare case- and space-insensitively; an attachment message compares by its type only (audio, image, video, file, sticker, location — derive it from the Forbeast attachments / the "[Audio]"-style marker and Botcake's "[audio] <url>" marker); a Botcake copy is the twin of a Forbeast chat of the same page + psid + same normalised kind/text within TWIN_MINUTES (either order). Each Forbeast chat pairs at most one Botcake copy. | Attachments never matched. |
| **The `inbound` switch needs real evidence**: unmatched Botcake copies from **at least 2 different customers** within 10 minutes (and still after the 120 s grace), not one message. Keep the other conditions (failed sends, connection errors, no answer, health) as they are. | One odd message must not move a whole page. |
| **No double replies after a switch**: when a page switches to Botcake, a standby Botcake copy runs only if no Forbeast twin of it exists (normalised as above) and no bot reply was sent to that psid on that page after the copy's received_at minus 5 s; skipped copies are logged with a reason. | The customer was answered twice. |
| Log line on every switch with the evidence (customer count, chat ids), no message text. | Diagnosis. |

Tests: audio/image/sticker twins pair (no switch); text twins with different spacing/case pair; one unmatched copy → no switch; two customers unmatched after grace → switch; after a switch, a standby copy whose twin was answered is skipped, one with no twin runs; regression of the existing ForbeastWatch tests (update and list any you change under Story changes). Re-run the brief's folders (no new failures). End with "149-4: <done|blocked> — <one line>".

### Result of 149-4

What changed, all in `app/Services/Chatbot/ForbeastWatch.php` unless noted:

- **`kindOf()`:** text is compared without case or extra spaces. An attachment is compared by its type only:
  - Forbeast's `[Audio]` / `[Photo]` / `👍` / `[Video]` and Botcake's `[audio] <url>` style markers and cleaned fbcdn links are read the same way.
  - Image, sticker and Like count as one kind: Forbeast delivers stickers as images, and Botcake may send a Like as `[sticker] <url>`.
- **`pairs()`:** a Botcake standby copy and a Forbeast chat pair when they are from the same customer, and either have the same mid or the same kind within 2 minutes, in either order.
  - Each chat pairs at most once; the closest in time wins.
  - A Forbeast chat that has already removed its standby (`payload._twin_used`) never pairs again.
  - `inbound()`, `twin()` (used by `answered()` and the switch run) all use this pairing.
- **`inbound` switch:** needs unmatched copies from at least 2 customers within the window (`inbound_minutes`, default now 10), each past the 120 s grace. The data loaded reaches 2 extra minutes back, so the earliest copy can still find its twin. The event text is now "Botcake chats from N customers with no Forbeast copy after 120 seconds". The other conditions are unchanged.
- **After a switch** (`runStandby`): a standby copy runs only if it has no Forbeast twin, and the bot sent that customer no reply on the page since the copy arrived minus 5 s. Otherwise it is skipped, with a log line `forbeast watch: standby skipped` {page, chat_id, reason: `forbeast_twin` | `already_answered`}.
- **Switch log:** every switch writes `forbeast watch: switched` {page, condition, error (HTTP and Meta code only)}. The inbound switch adds the evidence: customers and up to 50 chat ids. No message text.
- **`answered()`:** removes at most one standby per Forbeast chat. A flow with several sends no longer removes the standby of a message Forbeast never got.
- **Config:** `config/services.php` and the example env file change `GATEWAY_SWITCH_INBOUND_MINUTES` to 10. **Deploy note:** if the production environment file still sets `GATEWAY_SWITCH_INBOUND_MINUTES=5`, set it to 10 (or remove it).

**Cases:**

| Case | Test | Result |
|---|---|---|
| S-149.20 | `test_S_149_20_a_botcake_copy_pairs_with_its_forbeast_twin_by_kind_and_time` (audio, image, sticker, Like as a sticker marker, Like, video, text with other spacing and case; two customers, no switch) | pass |
| S-149.21 | `test_S_149_21_the_inbound_switch_needs_unmatched_copies_from_two_customers_after_the_grace` (one customer: no switch; still in the grace: no switch; two customers: switch; the log has the evidence and no text) | pass |
| S-149.22 | `test_S_149_22_after_a_switch_a_copy_whose_twin_was_answered_is_skipped_and_one_without_a_twin_runs`, `test_S_149_22_a_copy_with_no_twin_is_skipped_when_the_customer_was_answered_since_it_came` | pass |
| S-149.23 | `test_S_149_23_a_forbeast_reply_removes_only_its_own_standby_copy_even_after_several_sends` | pass |
| S-117 regression | `ForbeastAutoSwitchTest`: 26 tests, 157 assertions | pass |

**Red runs:**
- S_149_20 failed with "two customers, but every copy has its twin" for audio, sticker, video and text. Image and Like passed on the old code, since both sides store `[Photo]` / 👍.
- S_149_21: "one customer is not enough".
- S_149_22: `Failed asserting that two arrays are identical`.
- Review loop: S_149_23 "the second voice note stays for a switch", and the Like-as-sticker row. The `already_answered` test passed at once: it pins a branch the fix had already added.

**Story changes:**
- **S-117.4:** was "3 chats in 5 minutes switch; one Forbeast chat keeps it". Now: unmatched chats from at least 2 customers in 10 minutes switch, and one customer alone does not. `test_S_117_4…` now pushes from two customers; `test_S_117_4b…` now shows that one customer's orphan does not switch and a second customer's does.
- **S-117.9:** a standby now runs only with no Forbeast twin and no reply to that customer since it came.
  - In `test_S_117_9…`, "Una po" is now from a second customer, so it runs.
  - "Pumalya ang sagot" (its Forbeast twin's reply failed) no longer runs and stays on standby.
- **New cases:** S-149.20 to S-149.23.

**Review:**
- `skeptic-reviewer`, adversarial, found 1 major: `answered()` could remove the standby of another message after a second send, because the freed Forbeast chat paired again. It was fixed in 1 loop, and the re-check found nothing major left.
- Fixed minor: a Like pairs with a Botcake `[sticker]` copy.
- The other minors are in `TODO.md` under "149".

**Open point for the owner (decided rule, kept as written):** "a standby copy runs only if no Forbeast twin of it exists". So a message that Forbeast received but could not answer is not answered by Botcake after the switch. This hits the `failed_sends` and `connection_error` switches hardest: there Forbeast receives fine but cannot send, so the customers whose failed replies caused the switch get nothing. The earlier rule ("skip only if the twin got a sent reply"), together with the new "no reply since it came" check, would still have prevented the live double reply. Say if you want that instead.

**Suite:** `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode` on b9f38515: `OK, but there were issues! Tests: 1503, Assertions: 8740, PHPUnit Deprecations: 75, Skipped: 1`. No failures (baseline 1459 at 5ff034d1, no failures).

149-4: done — copies pair by customer, kind and time (audio, images and stickers now match), the inbound switch needs 2 customers, and after a switch an answered message is not answered again; open point: a message Forbeast received but failed to answer is not re-answered by Botcake.

## Amendment 149-5

The owner, 2026-10-25: "yes" to via, "okay" to freezing Check, and on voice/image: "wag ipapasa, walang need gawin, pero magready ka na pwede itranscribe yun… para pwedeng mareplyan".

| Change | Why |
|---|---|
| **"via" everywhere.** Record which provider carried every outgoing send: add a nullable `via` (`botcake` / `forbeast`) to `chatbot_replies`, filled by the send path (bot sends, sequence sends, Inbox manual sends, comment replies). Backfill old rows best-effort from the stored provider answer shape (Forbeast answers carry message_id/recipient_id; Botcake's `{success:true}`); leave null when unsure. Incoming chats already carry it (`payload.source`: gateway = Forbeast, default_reply = Botcake). Show a small label on every Inbox thread item ("via Forbeast" / "via Botcake"; outgoing as "Bot · via Forbeast" or "<staff> · via Botcake") and a Via column + filter on the Replies page. | The owner wants to see which bot sent each message. |
| **Freeze the Check column** on /chatbot/pages next to Page, Active, Flow (desktop widths only; not frozen at phone width). While frozen it shows a compact cell: the status icon, the count of problems and the first line; the full list opens on click/hover (keyboard reachable). No horizontal page scroll. | "eto kasama to sa freeze na column". |
| **Attachment-only messages are ignored by the bot on every page (Botcake and Forbeast).** A customer message that is only a photo, voice/audio, video, reel, file, or Botcake "fallback" (no text) does not start or resume a flow, does not call the AI, sends nothing, and is not handed to a human; it is logged as an event "ignored: <type>" (visible in the Inbox thread and counted on Health). **Stickers and Likes are NOT ignored**: they keep working as today. A photo/attachment that comes with text: the text is handled as today. Keep the attachment's type and URL on the stored chat so a later feature can transcribe audio / describe images. Add a global setting `chatbot_attachments` with values `ignore` (default) and `reply` (today's behaviour), CEO-only on the existing chatbot settings place; `reply` restores today's behaviour exactly. These messages never count for the Forbeast inbound switch. | Today the AI answers 130+ photos/voices a day blind ("[Photo]"), slowing the bot; the owner wants them ignored for now and the data kept ready for transcription later. |

Tests: via stored per send path and shown in the Inbox payload and the Replies filter; backfill rules; Check frozen markup + compact cell; photo-only / audio-only / video-only / fallback ignored (no run, no AI, no send, event logged), sticker/Like still answered, photo+text handled as text, `reply` setting restores old behaviour, ignored ones never trigger the inbound switch. Re-run the brief's folders (no new failures). End with "149-5: <done|blocked> — <one line>".

### Result of 149-5

**Via**
- `chatbot_replies.via` (nullable `botcake` / `forbeast`) is added by migration `2026_11_09_100000_add_via_to_chatbot_replies.php`. A new column only, so it is fast on MySQL.
- Every send path fills it:
  - bot sends and sequence sends (`BotcakeOutbox::send` → `ChatbotProvider::viaOf(provider)`);
  - the Botcake send pool (`BotcakeSendQueue::finishChatbot`, always Botcake);
  - Inbox manual sends;
  - sheet rows stay null;
  - comment replies are always Forbeast: shown as such on the Replies Comments view, since they live in `chatbot_comment_events`.
- **Inbox:** incoming items carry `via` from `payload.source`, shown as "· via Forbeast" next to the time. Outgoing items show "Bot · via Forbeast" or "<staff> · via Botcake". Old rows fall back to the Results `tags.via`.
- **Replies:** a Via column, a Via filter, and Via in the detail.
- **Backfill:** `php artisan chatbot:replies-via-backfill` (a dry run by default; `--apply` writes, since 149-7), to run by hand after the migration. It works in 1,000-row chunks by id and only touches send and manual rows that have no via.
  - Order of evidence: Results `tags.via`, then the stored provider answer (`message_id` / `recipient_id` = Forbeast, `success` = Botcake). Anything else stays null.
  - Note: `tags.via` is the bot of the chat that started the run, which almost always matches the sender.

**Frozen Check**
- At 1024 px and wider, Check is a fourth sticky column (`stick-4`, left 28rem, 14rem wide). Below 1024 px nothing changes; from 640 px, Page, Active and Flow stay frozen as before.
- While frozen, a problem cell shows one line: icon, count and first problem. The full list, with "Check again", opens on hover, on click (a button with `aria-expanded`) or on keyboard focus, and Escape closes it while keeping the focus on the line.
- The summary is a pure helper (`checkSummary`) tested in Node. The table still scrolls inside its box, so there is no horizontal page scroll.

**Attachment-only messages** (`app/Services/Chatbot/ChatAttachments.php`)
- **What counts:**
  - photo (`[Photo]`, or Botcake's `[image] <link>`);
  - audio, video, reel, file and fallback, from the Forbeast `[Audio]` style or Botcake's `[audio] <link>` style.
  - A marker must be followed by an http(s) link or nothing, so typed text like "[photo] ito" is answered.
  - Likes (👍) and stickers are not ignored. That covers Botcake's `[sticker]` and Forbeast images on Facebook's sticker CDN path `t39.1997-6`.
- **What happens:** the gate is in `ChatbotInbound` (one path for both bots).
  - An ignored chat gets `payload._ignored = <type>` and an `ignored` event ("Ignored: audio only …").
  - It is not dispatched, and `ChatbotRunner` never runs it later either: it skips `_ignored` chats in the pending list and in the wait-timeout check. So no flow starts or resumes, no AI is called, and nothing is sent.
  - Sequence exit-on-reply and Results "replied" still count it, as before (see TODO).
- **Kept for later:** `payload.attachment` = `{type, url}` (http(s) only) on both paths, in both modes.
- **Shown:**
  - Inbox: "Not answered by the bot (audio only)" under the bubble;
  - Health: an "Attachments ignored" tile, and ignored chats no longer count as inbound or not answered;
  - Forbeast watch: they never count for the inbound switch.
- **Setting:** `chatbot_attachments` (app_settings) is `ignore` by default, or `reply` for today's behaviour.
  - It is set on Health → "Photos, voice and other attachments", CEO only (`POST /chatbot/health/attachments`, validated). Other roles see the current mode.
  - Health was chosen because it holds the only existing CEO-only chatbot settings form.

**Cases:**

| Case | Test | Result |
|---|---|---|
| S-149.24 | `test_S_149_24_every_send_path_stores_which_bot_carried_it` | pass |
| S-149.25 | `test_S_149_25_the_inbox_thread_and_the_replies_page_show_via` | pass |
| S-149.26 | `test_S_149_26_old_rows_get_via_from_their_tags_or_the_provider_answer_and_stay_empty_when_unsure` | pass |
| S-149.27 | `test_S_149_27_check_is_frozen_on_desktop_only_with_a_compact_cell_that_opens_the_full_list` (markup, helper in Node) | pass; browser not run |
| S-149.28 | `test_S_149_28_an_attachment_only_message_is_ignored_logged_and_kept_for_later` (photo, audio, video, reel, file, fallback), `…_a_forbeast_audio_or_photo_is_ignored_and_its_link_kept`, `…_an_ignored_photo_is_not_run_later_when_the_customers_next_text_runs`, `…_a_photo_during_a_wait_does_not_hold_or_drop_the_no_reply_branch` | pass |
| S-149.29 | `test_S_149_29_stickers_likes_and_a_photo_with_text_are_answered_as_today` | pass |
| S-149.30 | `test_S_149_30_the_reply_setting_restores_todays_behaviour_and_only_the_ceo_changes_it` | pass |
| S-149.31 | `test_S_149_31_ignored_messages_show_in_the_inbox_and_are_counted_on_health` | pass |
| S-149.32 | `test_S_149_32_attachment_only_copies_never_count_for_the_inbound_switch` | pass |

**Red runs:**
- Via: the migration file was missing.
- Frozen Check: the markup strings were missing.
- Attachments: "no run, so no AI and no send" for each type, and "Undefined array key ignored". The sticker/Like test passed at once: it pins today's behaviour.
- Review loop:
  - the next-text test failed with "only the text ran — 2 is identical to 1";
  - the wait test failed with "the voice note is not a reply that will run";
  - the Health test failed with "inbound 1" once it had a sending page.

**Story changes:**
- **S-111.4b** (`ChatbotProviderTest`): the stored payload now includes `attachment`. The test runs under `reply` so it still pins the storage shape and one run per event.
- **S-069.A4** (`ChatbotAmendmentOneTest`): the photo echo is pinned under `reply`. The default (ignored) is covered by S-149.28.
- **S-149.21:** uses text messages, since attachment-only copies are no longer evidence.
- **New cases:** S-149.24 to S-149.32.

**Review:**
- `skeptic-reviewer`: standard depth on via and frozen Check, adversarial on attachments.
- It found 1 blocker: an ignored photo still ran when the customer's next text came, because the runner runs every chat after LAST_CHAT. It also found 1 major: Health counted ignored chats as not answered. Both were fixed in 1 loop, and the re-check found nothing open.
- Fixed minors:
  - only a link after the marker counts;
  - injected `_ignored` / `attachment` params are dropped;
  - the Forbeast URL gets an http(s) check;
  - the popup's z-index;
  - Escape.
- The rest are in `TODO.md` under "149".

**Deploy notes:**
- Run the migration.
- Then optionally run `php artisan chatbot:replies-via-backfill` (a dry run: counts only), then `php artisan chatbot:replies-via-backfill --apply` to write.
- Ignore is the default from the deploy on. The CEO can switch to reply on Health.

**Suite:** `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode` on 95085abf: `OK, but there were issues! Tests: 1520, Assertions: 8833, PHPUnit Deprecations: 75, Skipped: 1`. No failures (baseline 1459, no failures). The later commit b2d4bb7c changes only the Escape handling, and the page-script parse test passes on it.

149-5: done — every send records via (shown in the Inbox and on Replies, with a backfill), Check is frozen on desktop with a compact cell, and attachment-only messages are ignored on every page (logged, counted, link kept; CEO can switch back to reply).

## Amendment 149-6

Live data check by the reviewer, 2026-10-25.

| Change | Why |
|---|---|
| **Botcake's real attachment format is markdown-link style, not "[type] url".** Read live from `botcake_chats.message` (last 3 days): voice = `[audio](https://cdn.fbsbx.com/…)`, reel = `[reel](https://www.facebook.com/…)`, Botcake fallback = `[fallback](https://www.facebook.com/…)`, photo = `[Photo]` (no link). Forbeast sends `[Audio]`, `[Photo]`, etc. Make `ChatAttachments` (and every place that classifies or pairs attachments, including the 149-4 twin pairing in ForbeastWatch) accept `[type](url)` as well as `[type] url` and a bare `[Type]`, case-insensitive, and keep the URL. Add these exact live examples as test fixtures (pairing `[Audio]` from Forbeast with `[audio](https://cdn.fbsbx.com/x)` from Botcake within the window must pair; `[reel](https://www.facebook.com/x)` and `[fallback](…)` must be ignored by the bot; `[Photo]` ignored). | Without this the voice-message fix and the ignore rule miss Botcake's copies (the exact Venice Lim case). |
| Answer to your question: an ignored photo/voice **still counts as the customer replying** (sequence exit-on-reply, Results "replied") — keep today's behaviour. | Reviewer's decision: the customer did respond; only the bot's answer is skipped. |

Re-run the brief's folders (no new failures). End with "149-6: <done|blocked> — <one line>".

### Result of 149-6
- **One parser:** `ChatAttachments::MARKER` / `fromMarker()` now read all three forms, case-insensitive:
  - `[type](https://…)` (Botcake's live markdown link);
  - `[type] https://…`;
  - a bare `[Type]` (Forbeast).

  The link is kept in `payload.attachment.url`, and a photo is stored as type `image`, like Forbeast's. Only an http(s) link counts: `[audio](not-a-link)` and `[photo] ito` stay text and are answered.
- **ForbeastWatch:** `kindOf()` (the twin pairing from 149-4) now uses the same parser, so a Botcake `[audio](https://cdn.fbsbx.com/…)` pairs with Forbeast's `[Audio]`. The old second regex is gone.
- **Live fixtures:**
  - `ChatbotAttachmentsTest`: `[audio](https://cdn.fbsbx.com/x)`, `[reel](https://www.facebook.com/x)`, `[fallback](https://www.facebook.com/share/y)` and `[Photo]` are ignored, with the link kept; `[audio](not-a-link)` is answered.
  - `ForbeastAutoSwitchTest` twins: `[audio](https://cdn.fbsbx.com/x)` against `[Audio]`, `[Photo]` against `[Photo]`, and `[AUDIO](HTTPS://…)` against `[Audio]` all pair, so the page does not switch.
- **Decision recorded:** an ignored photo or voice note still counts as the customer replying (sequence exit-on-reply, Results "replied"). No code change was needed; the TODO entry is updated.
- **Red runs:**
  - the four live rows failed with "no run, so no AI and no send" (and a type mismatch for `[Photo]`);
  - the live twin failed with "two customers, but every copy has its twin".
- **Story changes:** new S-149.33.
- **Review:** `skeptic-reviewer`, standard depth, found no blocker or major. Two minor test rows were added (no-link and upper-case forms). The rest are in `TODO.md`: a link with a parenthesis is read as text, a marker followed by something other than a link is compared as text when pairing, and the stale "other types" line was refreshed.
- **Suite:** the brief's folders on 28555d17: `OK, but there were issues! Tests: 1526, Assertions: 8861, PHPUnit Deprecations: 75, Skipped: 1`. No failures (baseline 1459, no failures). The later commit 0ae1ef00 only adds test rows: those two files pass (46 tests).

149-6: done — Botcake's live `[audio](link)` / `[reel](link)` / `[fallback](link)` / `[Photo]` forms are ignored by the bot with the link kept, and they pair with Forbeast's `[Audio]`; ignored attachments still count as the customer replying.

## Amendment 149-7

From the reviewer's qa of 149; these are the reviewer's decisions.

| Change | Why |
|---|---|
| **After a switch, a standby Botcake copy is skipped only when the customer was actually answered**: skip it when a bot reply to that psid on that page has status sent after the copy's received_at minus 5 s (`already_answered`). A Forbeast twin alone is NOT a reason to skip: if Forbeast received the message but its reply failed (the failed_sends / connection_error switches), Botcake must answer it. Restore `test_S_117_9`'s original Then ("Pumalya ang sagot" runs via Botcake) and keep the 149-4 tests for the answered case. | Customers whose Forbeast reply failed would get nothing. |
| **Pairing is really tested**: S-149.20's attachment rows (audio, live `[audio](url)`, photo, image, video, Like) must assert pairing with `chatbot_attachments=reply` (where attachments count as inbound evidence), so they fail if `pairs()` breaks; add `test_S_149_33` asserting `[audio](https://cdn.fbsbx.com/x)` pairs with Forbeast `[Audio]`. | qa's mutation run: 7 of 10 rows passed with pairing disabled. |
| **Forbeast refusal shape**: in `GatewayComments` read the error code from `error.code` when `error` is an object (Forbeast's 401/403/404/422 shape `{"error":{"code","message"}}`) and from Laravel's `{message, errors}` for validation 422s; update the `refused()` test rows to Forbeast's real shapes. | Stored reason showed http_403 instead of the code. |
| **Length guard**: the Check column warns when Comment PM is over 2000 characters or a Comment Reply over 8000 (Forbeast refuses them); no silent truncation. | They would fail at send time. |
| **Backfill is a dry run by default**: `chatbot:replies-via-backfill` writes only with `--apply`; test both. | Safer default. |
| Accepted as they are (no change): S-149.3 reading (empty reply columns still send the PM — Decision 1 "empty = that part off"); `chatbot_comment_events` prune left to TODO; the 20/min limit uses the app's cache store (note in the result which store production uses per config). | Reviewer's decision. |

Re-run the brief's folders (no new failures). End with "149-7: <done|blocked> — <one line>".

### Result of 149-7

**After a switch** (`ForbeastWatch::runStandby`)
- A standby copy is skipped as `already_answered` only when that customer got a sent bot reply on the page since 5 s before the copy arrived. A Forbeast twin alone no longer skips it: a message whose Forbeast reply failed is answered by Botcake. `test_S_117_9` has its original Then back ("Una po" and "Pumalya ang sagot" run). It now waits a minute between the answered message and the failed one, so the 5 s rule tells them apart.
- **Added after review (a major):** the copy is also skipped as `forbeast_pending` while its Forbeast twin's run has not finished. That is when the runner has not reached it yet (twin id > the customer's LAST_CHAT), or it waits for an AI or a send. That run will reply itself, through Botcake, since the provider is picked at send time. Without this check, a switch during the run answered twice. This keeps the decision: a finished twin whose reply failed is still answered.
  - `test_S_149_35` covers it.
  - The `forbeastCopy` helper of S-117.9 now sets LAST_CHAT when it records a reply, the way a finished run does.

**Pairing is really tested**
- `ForbeastWatch::inbound` leaves attachment-only copies out of the evidence only while they are ignored. Under `reply` they count, as text does.
- S-149.20 runs under `reply` and stores the Botcake copy first. Before, when Forbeast came first with the same text, `/api/botcake/chat` dropped the Botcake copy as a duplicate, so pairing never ran.
- `test_S_149_33`: the live `[audio](https://cdn.fbsbx.com/x)` pairs with Forbeast's `[Audio]`. A control in the same test removes the Forbeast copies, and the page then switches.
- **Mutation check:** I stubbed `pairs()` to return nothing, and all 10 S-149.20 rows and S-149.33 failed. Then I restored it.

**Forbeast refusal shape** (`GatewayComments`)
- The code is read from `error.code` when `error` is an object, or from a flat `error` string.
- Laravel's `{message, errors}` validation answer gives `invalid_<field>`.
- `error.message` (or the Laravel `message`) is added to the stored error text.
- A `meta_code` inside the error object counts too.
- The `refused()` rows now use these shapes: 401/403/404/422 objects, a Laravel 422 and a flat 502.

**Length guard**
- Check warns "Comment PM is N characters: Forbeast sends at most 2000" and the same for each Comment Reply (8000). The limits are `GatewayComments::MAX_PM` / `MAX_REPLY`.
- The warning shows on pages without a flow too: their status becomes `ok` with the warning.
- Nothing is cut.

**Backfill:** `chatbot:replies-via-backfill` is a dry run (counts only) unless `--apply` is given. Both are tested, and the 149-5 deploy steps above are updated.

**Accepted as they are:**
- the S-149.3 reading;
- no prune for `chatbot_comment_events` (TODO);
- the 20/min cache store. Per `config/cache.php` it is `CACHE_STORE`, default `database`, and `.env.example` sets `database`. On that store the per-minute increment is atomic (a row lock). Production uses whatever `CACHE_STORE` its environment sets, which I can't see here.

**Cases:**

| Case | Test | Result |
|---|---|---|
| S-117.9 | `test_S_117_9_standby_chats_are_stored_without_running_and_run_once_after_the_switch` | pass |
| S-149.17 | `test_S_149_17_a_refused_send_is_failed_with_the_error_code_as_the_reason` (6 shapes) | pass |
| S-149.20 | `test_S_149_20_…` (10 rows, under reply, Botcake copy first) | pass; all fail with pairing disabled |
| S-149.22 | `test_S_149_22_…` (2 tests; reason `already_answered`) | pass |
| S-149.26 | `test_S_149_26_…` (dry run, then `--apply`) | pass |
| S-149.33 | `test_S_149_33_a_live_botcake_voice_copy_pairs_with_the_forbeast_audio_and_without_it_the_page_switches` | pass |
| S-149.34 | `test_S_149_34_check_warns_when_a_comment_text_is_longer_than_forbeast_takes` | pass |
| S-149.35 | `test_S_149_35_a_copy_whose_forbeast_run_is_still_going_is_left_to_that_run` | pass |

**Red runs:**
- `S_117_9`: "two arrays are identical" (Pumalya was skipped).
- `S_149_22`: the log said `forbeast_twin`.
- `S_149_33`: the control "without the Forbeast copies … switch the page".
- The refused rows: the reason was `http_<n>`.
- `S_149_34`: the warnings were missing.
- `S_149_26`: the output had no "Dry run".
- `S_149_35`: "The unexpected RunChatbotFlow job was pushed".

**Story changes:**
- S-117.9: back to "a message whose Forbeast reply failed is answered by Botcake", plus the pending-twin rule.
- S-149.17: the real shapes.
- S-149.20: under reply, Botcake first.
- S-149.22: the reason is already_answered, and a copy with a twin whose reply failed runs.
- S-149.26: dry run by default.
- S-149.32: "while attachments are ignored".
- S-149.33: the new test.
- New: S-149.34 and S-149.35.

**Review:**
- `skeptic-reviewer`: standard depth, adversarial on `runStandby`. It found 1 major: a twin still running at switch time led to a double reply. It was fixed in 1 loop, and the re-check found nothing major left.
- The minors are in `TODO.md` under "149":
  - a twin mid-send (LAST_CHAT already set) is not seen as pending. Checking the runner lock was not done, because the failed-sends switch runs inside that lock and would then skip that customer's failed copies too;
  - a late Botcake copy more than 5 s after Forbeast's reply;
  - timeouts are stored as failed;
  - untested error shapes;
  - the "No flow" Check bucket;
  - the code points vs UTF-16 count.

**Suite:** the brief's folders on 20b683f2: `OK, but there were issues! Tests: 1531, Assertions: 8897, PHPUnit Deprecations: 75, Skipped: 1`. No failures (baseline 1459, no failures). The later commit 6b3d4247 is TODO only.

149-7: done — a standby copy is skipped after a switch only when the customer was answered (or its Forbeast run is still going), pairing tests now fail without pairing, Forbeast error codes are read from their real shapes, Check warns on over-long comment texts, and the backfill is a dry run unless --apply.
