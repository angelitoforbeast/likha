# Plan 100: chatbot results (tags, replied, orders, delivered, Results page)

Spec: `docs/specs/100-chatbot-results.md`. Stories: `qa/stories.md` § S-100 (drafted here; the brief brought none).

## Design decisions the spec leaves open

**Where the tags live.** `chatbot_replies.content` stays the list of messages (Inbox, Replies and the AI history read it as a list; a `meta` key would break them). New nullable columns on `chatbot_replies`:

| Column | Why a column |
|---|---|
| `flow_version_id` | filter and compare by flow version |
| `sequence_id`, `sequence_step` | "By sequence step" grouping |
| `ad_id` (string 64) | "By ad" grouping |
| `replied_at`, `reply_seconds` | replied % and median reply time |
| `tags` (JSON) | the full record per message: `{"v":1,"flow_hash":"…","messages":[{"botfields":{"main flow":812},"fields":["first name"]},…]}` for Mira |

Per message and botfield, one row in a new table **`chatbot_reply_tags`** (`reply_id`, `page_id`, `flow_id`, `flow_version_id`, `node_id`, `message` index, `botfield`, `setting_id`, `sent_at`), so "By message" and "compare two botfield versions" are plain indexed GROUP BYs instead of scanning JSON (a week of one page can be 100k+ sends). A message whose template uses no botfield gets one row with `botfield` null, so every node shows up. Written in **one insert per send** (not per message), only for status `sent`.

**Botfield version** = the id of the active `page_settings` row. `ChatbotPage::botfields()` already reads those rows once per run; it now also remembers `[name => id]` per page (same query, no new read). The pool and the Inbox send don't load botfields: the pool gets the tags inside the ticket payload (computed by the run at submit time); the Inbox send uses no template, so it has no botfields.

**Flow version** = the newest `chatbot_flow_versions.id` of the flow (Publish writes the live graph and its version row in one transaction; drafts and restores never add a version), cached per flow and published-graph hash, so a new Publish gets a new id at once and a send costs a cache read, not a query. No version row (flow published before 074) = null id; `tags.flow_hash` (first 12 hex of md5 of the published graph) is always kept.

**ad_id** = the customer's `botcake_psids.ad_id` for that page, else the `ad_id` of their first chat's payload; cached a day per customer.

**One tagging call per send** (to merge cleanly with 098): `FlowEngine` sets `$run->tags = ChatbotTags::forSend($run, $templates)` right before the outbox / pool call; `BotcakeOutbox::record()` gets one optional `$tags` argument; the pool ticket carries `tags` next to `messages`; the Inbox send merges `ChatbotTags::manual(...)`. Sequence runs set `$run->sequenceId/sequenceStep`.

**Replied.** Each send group (one `chatbot_replies` row, kind `send` or `manual`, status `sent`) gets `replied_at` = the customer's first chat on that page after `sent_at`, within 24 h. A chat arriving dispatches `MarkChatbotReplied` (queue `chatbot`): it fills every still-unreplied group of that customer sent in the 24 h before the chat (the latest one included; when the bot sent two nodes before the customer answered, both were answered by that chat). Hourly `chatbot:replied-backfill` redoes the last 48 h for anything the job missed. Bot sends are never chats, so they can't mark anything.

**Order event.** Send, Set field and Sheet get `counts_order` (stored only when ticked, so saved graphs don't change). When a live run passes a ticked node (its port isn't `failed`, the send isn't still pending in the pool), `ChatbotTags::order()` writes a `chatbot_events` row of type `order` unless the customer has one from the last 24 h. Detail: `run`, `flow_version`, `flow_hash`, `sends` (the customer's last 5 sent reply ids), `sequence_id`/`sequence_step` (of the latest sequence send to the customer), `ad_id`. Test tab runs write nothing. `chatbot_events` keeps 30 days; `order` rows are kept (the prune skips them), they are few.

**Delivered** (at report time, nothing copied): for each order event, the `macro_output` rows with the same `botcake_psid` and page (trim, case-insensitive) and `ts_date` from 1 day before to 3 days after the event date; the closest date wins (tie: the one on or after the event, then the older row). Its waybill's furthest `from_jnts` status, the way chatblast ranks it: Delivered → delivered; Returned / For Return, or In Transit / Delivering with an RTS reason → rts; In Transit / Delivering → in transit; no J&T row but a `jnt_latest_scans` row → in transit; otherwise other. No match → unmatched.

**Funnel units.** Chats = customers (page + PSID) who sent a chat in the range. Sent = bot send groups (kind `send`, status `sent`) and the customers they reached. Replied % = replied groups / sent groups. Orders % = orders / customers reached. Delivered % and RTS % = of orders.

**Report** (`ChatbotResults`): filters page (Botcake page id; required in the UI, optional in JSON/command), from/to (Manila dates, default the last 7 days, at most 92 days), flow, flow version. Every query is bounded by `sent_at` / `created_at` / `received_at` on an index. Results are cached 5 minutes per filter set. Median reply time is computed from the newest 200,000 `reply_seconds` of the range (all rows together). Outputs carry counts, node ids, botfield names, setting ids and their values (the bot's own templates), flow versions and ad names; never customer text, names or PSIDs.

**Indexes** (all in one migration, all allowed by the brief): `chatbot_replies (flow_id, sent_at)`, `(sequence_id, sent_at)`, `(page_id, psid, sent_at)`; `chatbot_events (type, created_at)`; `chatbot_reply_tags (page_id, sent_at)`, `(reply_id)`, `(setting_id)`.

**Access.** `/chatbot/results` and `/chatbot/results.json`: `auth` + `allowed_ip` (the `/chatbot` group) and `NavAccess::allows(role, '/chatblast')`, like the other chatbot tabs; 403 otherwise. The Results tab is added to `ChatblastNav::chatbotItems()`.

## Files

New: migration `2026_10_30_100000_add_results_to_chatbot_replies.php`, `app/Services/Chatbot/ChatbotTags.php`, `app/Services/Chatbot/ChatbotResults.php`, `app/Jobs/MarkChatbotReplied.php`, `app/Console/Commands/ChatbotResultsCommand.php`, `app/Console/Commands/ChatbotRepliedBackfill.php`, `app/Http/Controllers/ChatbotResultsController.php`, `resources/views/chatbot/results.blade.php`, tests `tests/Feature/Chatblast/ChatbotTagsTest.php` and `tests/Feature/Chatblast/ChatbotResultsTest.php`.

Small edits: `FlowEngine` (tag call before each send, order after a ticked node), `FlowRun` (`tags`, `sequenceId`, `sequenceStep`), `BotcakeOutbox::record` (+`$tags`), `BotcakeSendQueue` (tags in the ticket payload), `ChatbotSequences::runStep` (2 lines), `ChatbotInboxController::send` (1 merge), `ChatbotPage::botfields` (remember ids), `FlowGraph::cleanConfig` (`counts_order`), `ChatbotEvents` (prune keeps orders; text of an order line), `BotcakeChatController` (dispatch the replied job), `ChatblastNav` (Results tab), `routes/web.php`, `routes/console.php`, `resources/views/chatbot/editor.blade.php` (one checkbox + help line in the node form, for Send, Set field and Sheet).

Not touched: `/chatbot/pages` (099), the editor canvas code (101: only the form body gets one block), the live flow.

## Tasks

| # | Task | Side | Risk | Cases |
|---|---|---|---|---|
| 1 | Migration + tagging on all four send paths, botfield and flow versions | backend | medium | S-100.1, S-100.2, S-100.3, S-100.4 |
| 2 | Replied: job on chat arrival + hourly backfill | backend | medium | S-100.5 |
| 3 | "Counts as an order": graph option, order event with dedupe, editor checkbox | backend + frontend | medium | S-100.6, S-100.7 |
| 4 | Report service: funnel, by message, by sequence step, by ad, compare, delivered mapping | backend | medium | S-100.8, S-100.9, S-100.10, S-100.11 |
| 5 | `/chatbot/results`, `/chatbot/results.json`, `chatbot:results`, nav tab, permissions | backend | medium (new read-only route behind existing auth) | S-100.12, S-100.13 |
| 6 | Results page UI and the browser check at 1366 and 390 px | frontend | medium | S-100.14 |

Tasks share `FlowEngine`/tests, so they run in order in this one worktree (not parallel). Review: one `skeptic-reviewer` pass (standard depth) on the whole diff before the result.
