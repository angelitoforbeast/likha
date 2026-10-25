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
