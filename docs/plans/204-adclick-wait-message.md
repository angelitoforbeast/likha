# Plan: spec 204, the Main Flow starts on the customer's first message (with amendments 204-1 and 204-2)

## Today
- `HandleGatewayEvent` stores a `referral` (ad open) as a `botcake_chats` row: message NULL, payload `{source: gateway, ad_id, _via}`,
  then `ChatbotInbound::afterStore` exits "exit when the customer replies" sequences, dispatches `MarkChatbotReplied` and
  `RunChatbotFlow`. The run starts the Main Flow on an empty input and sends the opening ~0.7 s after the click.
- `ChatbotRunner::handle` runs every row of the customer with `id > __last_chat` up to the chat, skipping only `_ignored`.
- `AdGroups::latestAd` picks the group: the newest of the customer's last 50 chat rows on the page with an `ad_id`, then
  `botcake_psids.ad_id` of the page. Read once per run, at the start of the run.

## Design
- Marker `payload._ad_open = true` on every gateway referral row (`ChatbotInbound::AD_OPEN`). Stored exactly as today otherwise.
- Referral path: store, count `referral` as today, `MarkChatbotReplied` as today (Results reads it the same way the hourly
  backfill does), no sequence exit, no `RunChatbotFlow`. If a first message of this customer is being held (below), the
  referral releases it now (dispatches its `RunChatbotFlow`), so the held start runs with the ad without waiting the full hold.
- Runner: `_ad_open` rows are passed over like `_ignored` (`__last_chat` moves past them, never run). `skip()` (the wait
  timeout) does not count an ad open as the customer's reply, and its "last chat" (input and 24-hour window) is the last
  real chat, not the ad open.
- Ad of the reply (204-1): unchanged rule, newest ad among the customer's last 50 chat rows on the page, so the referral's
  ad_id (or the message's own referral object, already stored as the message's ad_id) is used; newest wins; no new time limit.
- Race (204-2): on an Ad-based page with groups, a gateway message or postback with no ad of its own from a customer with no
  other stored chat on the page is held: `RunChatbotFlow` dispatched with a 2 s delay plus a cache key
  `chatbot-ad-wait:<page_id>:<psid>` = chat id (10 s). A referral that arrives pulls the key and dispatches the run at once.
  Either way the runner's lock and `__last_chat` let only the first job run the chat (never twice). Page-based pages, pages
  with no groups, messages that carry an ad, returning customers and the Botcake webhook path start immediately as today.
- Readers (.6): skip `_ad_open` where an ad open would otherwise read as an unanswered or slow customer message or as a
  second inquiry: `BotHealth::inbound`, `ResponseSpeed` (build, pageSeconds), `ChatEchoes` (not answered), `LiveFeed`
  (inquiries in and the "Inquiry received" event), `ForbeastWatch::rerunFailed` (an ad open is not a newer chat). Everything
  that reads the ad (`AdGroups`, `ChatbotTags::adId`, `Inbox` ad, ad lists) reads the row as today.
- .7: `BotHealth::metrics` gets `ad_opens` per day: customers (page + PSID) with an ad open that day and no real chat from
  60 s before that day's first ad open onward; shown as the row "Ad opens without a message" next to "Attachments ignored".

## Tasks
| # | Task | Cases | Tier | Side |
|---|---|---|---|---|
| 1 | Referral marked and not run; runner and wait-skip pass over ad opens | S-204.1, .3, .4, .5 | medium-high | backend |
| 2 | Ad of the first message, and the 2 s hold on Ad-based pages with early release | S-204.2, .8 (204-1 A2), 204-2 A2 | medium-high | backend |
| 3 | Readers skip ad opens (Health, Speed, echoes, Live, rerun) | S-204.6 | medium | backend |
| 4 | "Ad opens without a message" daily figure on Health | S-204.7 | low | backend + blade row |
| 5 | Skeptic review of the trigger path and dedupe; fix majors; full tests/Feature | all | - | - |

Tasks 1-2 share `HandleGatewayEvent` / `ChatbotInbound` / `ChatbotRunner`, so they run in order, not in parallel. No migration.

## Cases (tests/Feature/Chatblast/AdOpenWaitTest.php)
- S-204.1 referral alone: row stored with ad_id and `_ad_open`, counted `referral`, no `RunChatbotFlow`, no send.
- S-204.2 referral then message: the message starts the Main Flow from its first step (opening is the first send), ad_id of the referral attached (tags ad_id).
- S-204.3 message then referral (page-based): one start, the referral adds no run; a later message runs once, the ad open is passed over.
- S-204.4 referral inside a running flow (a wait step pending): nothing changes in the run; the wait-skip still fires and is not "customer replied".
- S-204.5 postback starts as today; the Botcake webhook path unchanged.
- S-204.6 readers: Health inbound, Speed, echoes and Live do not count the ad open as a message or as unanswered.
- S-204.7 the daily figure.
- S-204.8 (204-1) Ad-based: referral X then message → group of X; X then Y then message → Y; message with its own referral → that ad; no referral → today's fallback (botcake_psids / page values); page-based unchanged.
- S-204.9 (204-2) Ad-based new customer: message first → held 2 s (delayed job + key); referral within the hold → released at once, ad reply, one start; no referral → fallback after the hold; page-based, returning customer, message with ad → no hold.
