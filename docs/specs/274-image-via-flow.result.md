# 274: Bot images via the page's SEND IMAGE flow — result

## Summary
A new service `App\Services\Chatbot\ImageFlow` holds the whole feature:
- **Check (S1):** `run($page)` reads the page's flows and custom fields (one pooled round, on the page's setup lane, one
  try each), then, when exactly one live "SEND IMAGE" flow (trimmed, case-insensitive, removed flows ignored) and exactly
  one "image_url" field exist, reads that flow's `/content` (one call). OK = the content `status` is `published` and at
  least one live block has an image card (`plugin_id: "image"`) whose `config.url`, trimmed, is `{{<field_id>//image_url}}`.
  Reasons in plain English: "No flow named SEND IMAGE", "Two flows named SEND IMAGE", "No custom field named image_url",
  "Two custom fields named image_url", "SEND IMAGE is not published", "The SEND IMAGE flow's image does not use
  {{77//image_url}}". Botcake not answering (or the token refused) keeps the last result and adds `error`/`error_at`,
  like the shipping setup check.
- **Send (S2):** `forSend($page, $messages)` returns the flow and field only when the messages contain an image and the
  stored check is OK (one page_settings read, no Botcake call). `steps()` is a generator of Botcake requests (the same
  shape as `ChatblastSender::stepper`, so the pools drive it unchanged): consecutive non-image messages go as one
  send_content (as today), each image as `POST customer/{psid}/customer_fields {"data":[{"id":"<field>","value":"<url>"}]}`
  then `POST flows/send_flow {psid, flow_id}`, strictly one after the other. Every step goes through the page's normal
  Botcake lane and rate (no change to rate limits or retry: 429 only, like send_content).
- **Serialization per page+PSID:** within one send the steps are in order; across sends a cache lock
  `image-flow:<page_id>:<psid>` (60 s TTL) is taken before each field write and let go once its send_flow answered (or the
  write failed). The pools try the lock without blocking (not free: the job goes back in its lane 0.5 s later, nothing
  called, the ticket not yet marked called); the direct send waits up to 20 s, then sends that image by send_content.
- **Fallback (S4):** a failed write or send_flow (non-2xx, `success:false`, no answer, lock not free) sends that image alone
  by send_content once and records the reason. A send_flow answer that says the flow is gone (HTTP 404, or a message with
  "flow" and not found / not exist / invalid / deleted / removed / unpublish) sets the page's check to not OK with that
  reason; every send_flow failure queues `CheckImageFlow` for the page (2 minutes later, unique per page for 15 minutes).
- **Record:** the chatbot_replies row stays one row per send node with via `botcake`; each image message in `content`
  carries `sent_via` = `flow` or `send_content`, and `flow_fallback` = the reason when it fell back. No new column.
- **When (S5):** `botcake:image-flow-check --all` daily 05:20 Manila (after the 05:10 accounts check, without overlapping,
  in the background; pages one after another on each page's setup lane); "Check again" per page on
  /chatblast/page-settings (`POST /chatblast/page-settings/image-check`, 20 a minute); after a send_flow failure (the
  job). No Botcake call on page load (tested).
- **Visible (S6):** /chatbot/pages: a badge under the page name next to the shipping badge — green "Image: via flow" or
  grey "Image: send_content", tap/click opens the reasons (details/summary, title on hover). /chatblast/page-settings: the
  Check column shows "Image: via flow" / "Image: send_content" with the reasons, the last check time, and "Check again".

## Image send paths found
| # | Path | Where | How it chooses now |
|---|------|-------|--------------------|
| 1 | Bot reply, queued (live run, pool alive) | `BotcakeSendQueue::submitChatbot` → `BotcakeSendPool::take/issue/respond` | `ImageFlow::forSend` at take: OK page with an image → `ImageFlow::steps` driven by the pool (lock per page+PSID); else one send_content as before |
| 2 | Bot reply, direct (pool off/dead, async off, run started by a person) | `BotcakeOutbox::send` → `ChatbotProvider::for` → `BotcakeProvider::send` | same check; OK + image → `ImageFlow::sendNow` (blocking lock); else `sendContent` as before |
| 3 | Sequence step, queued | `BotcakeSendQueue::submitSequence` → `SequenceSendPool` (Botcake lane) | same as 1, in the sequence pool (lock per page+PSID shared with path 1 and 2) |
| 4 | Sequence step, direct (sequence hand-off not ready) | `BotcakeOutbox::send` → `BotcakeProvider::send` | same as 2 |
| 5 | Inbox manual reply (a person, one try in a web request) | `ChatbotInboxController` → `ChatbotProvider::for($page, true)` → `BotcakeProvider` with `$once` | unchanged: send_content (not a bot send; see Open points) |
| — | Chatblast / Broadcast | `ChatblastSender::stepper` | no image path: they write the text field and send the shipping/broadcast flow; unchanged |
| — | Forbeast Gateway pages | `GatewayProvider` | not Botcake; unchanged |

## Storage
`page_settings`, page = the page, provider `botcake`, key `image_flow.check`, written as system through
`PageSettings::set` (history kept): JSON `{ok, flow_id, field_id, reasons[], at}` plus `error`, `error_at` when the last
check could not reach Botcake. No new table, no migration. The send record reuses `chatbot_replies.content` (markers on
the image messages); `BotcakeOutbox::wire` strips those markers so they never go to Botcake.

## Files changed
- `app/Services/Chatbot/ImageFlow.php` (new): check, badge, forSend, steps generator, sendNow, after/markFailed, lock key.
- `app/Jobs/CheckImageFlow.php` (new): re-check after a send_flow failure (unique per page).
- `app/Console/Commands/BotcakeImageFlowCheck.php` (new): `botcake:image-flow-check {page?} {--all}`.
- `routes/console.php`: the 05:20 Manila schedule.
- `app/Services/Chatbot/BotcakeProvider.php`: the direct bot send uses ImageFlow on an OK page (not the Inbox).
- `app/Services/Chatbot/BotcakeSendPool.php`: chatbot tickets with images on an OK page run the steps; lock taken before
  the field write and released after the flow answered, on forget, or on any end of the job; the first-step checks
  (pause, emergency stop, called) run only before the first step.
- `app/Services/Chatbot/SequenceSendPool.php`: the same for sequence tickets on a Botcake lane.
- `app/Services/Chatbot/BotcakeSendQueue.php`: `finishChatbot` / `finishSequence` record the marked messages when the
  outcome carries them (kept out of the ticket's `result` JSON).
- `app/Services/Chatbot/BotcakeOutbox.php`: `wire()` strips `sent_via` / `flow_fallback`; the direct send records the
  marked messages.
- `app/Http/Controllers/ChatbotController.php`: rows carry `image_flow` (`{ok, reasons, at}` or null) from the stored check.
- `resources/views/chatbot/index.blade.php`: `imageBadge` / `imageBadgeEl` (between `image-badge:start/end`).
- `app/Http/Controllers/PageSettingController.php`, `routes/web.php`, `resources/views/page_settings/index.blade.php`:
  the "Check again" action and the image lines in the Check column.
- `tests/Feature/Chatblast/ImageViaFlowTest.php` (new).
- `tests/Feature/Chatblast/snapshots/207/data.json`: the pinned /chatbot/data JSON gains `"image_flow": null` per page.

## Tests and evidence
`tests/Feature/Chatblast/ImageViaFlowTest.php`, Botcake faked with `Http::fake` (check, direct send, command, Check
again) and with the pool's own fake handler (the send pool calls Guzzle directly, as in the existing pool tests):
- S1: OK page (stored flow 501, field 77); nine failure cases (no flow, two flows, removed flow, no field, two fields,
  both missing, unpublished, image from another field, no image card) with the exact reasons; Botcake 500 keeps the last
  result with the error.
- S2: OK page sends text by send_content then the exact field-write and send_flow bodies; two images go write A, flow,
  write B, flow; the direct send waits for a held lock and falls back; the pool sends text, write, flow, write, flow one
  call at a time (the next only after the answer), records `sent_via: flow`, the ticket ends `done` and the lock is free;
  the pool does not write (and does not mark the ticket called) while another send holds the lock.
- S3: not-OK and never-checked pages send text + image in one send_content, direct and in the pool, record unchanged.
- S4: write refused, write no answer, flow success:false, flow 500, flow gone: the image alone by send_content once,
  reason recorded; only "flow gone" marks the check; send_flow failures queue `CheckImageFlow`, write failures do not;
  the pool falls back the same way and marks the check on a 404.
- S5: schedule `20 5 * * *` Asia/Manila; Check again returns the new cell; the command checks every Botcake page; loading
  /chatbot/pages, /chatbot/data and /chatblast/page-settings makes no Botcake call.
- S6: /chatbot/data rows carry `image_flow`; the badge sits next to the shipping badge; page settings has the Check again URL.

New tests: `Tests:    27 passed (167 assertions)`
Full suite (run once): `Tests:    4 failed, 6 skipped, 5172 passed (81488 assertions)` — the three known failures
(ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest) plus `ChatbotDataSpeedTest > s 207 9`, whose pinned
/chatbot/data JSON snapshot lacked the new `image_flow` key. The snapshot was rewritten the way 271 did
(`WRITE_207_SNAPSHOTS=1`); its diff is exactly 117 added `"image_flow": null` lines, and that test file then passed
(`Tests:    1 passed (12 assertions)`). The full suite was not run a second time.

## Story changes
- S2 serialization: done with a per page+PSID cache lock held from each field write to its send_flow answer (the pools
  had per-customer ordering only inside one pool; the bot reply pool, the sequence pool and the direct send could
  otherwise interleave on one PSID).
- S4 "no answer": the image is sent by send_content even after a send_flow with no answer (as the story says), so that
  image can arrive twice when Botcake did take the flow; the shipping chatblast treats the same case as "unknown".
- The failed check after "flow gone" lists that one reason; the re-check job (or the daily check) restores it.

## Open points
- **The Inbox's manual reply stays send_content.** It is a person's single-try send in a web request (15 s), not a bot
  send; moving it would mean blocking the request on the lock and up to three calls. Say if it should go by flow too.
- **The sequence pool path has no dedicated test.** It runs the same `ImageFlow::steps` and the same lock code as the
  bot-reply pool (tested); a sequence-pool test needs the full sequence hand-off fixture.
- **"Flow gone" detection is by text.** Botcake's exact send_flow error for a deleted or unpublished flow was not seen
  live; the match is HTTP 404 or a message with "flow" plus not found / not exist / invalid / deleted / removed /
  unpublish. Other send_flow failures fall back per image and only queue a re-check.
- **Ordering between send_content text and the flow's image** is the order of the calls; Botcake answers send_flow with
  `{"success":true}` only, so delivery order in Messenger is not confirmed.
- **The pool's lock retry spends the lane's reserved slot** when the lock is busy (0.5 s later it tries again); this only
  happens when two sends to the same customer overlap.
- **A pool that dies between a field write and its send_flow** leaves the lock until its 60 s TTL; the ticket is then
  ended by the existing recovery (called → failed "Botcake did not answer"), no repeat.
