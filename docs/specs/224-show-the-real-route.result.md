# Result 224: show the address a reply really went through

**Branch:** `feat/224-show-the-real-route` from `78d48eed`. **Status:** done, not pushed, not deployed.

Commits, one per story, each deployable on its own in order:

| Commit | Story |
|---|---|
| `721ed10f` | the spec |
| `af110811` | S1 `{{replies_via}}` (no migration) |
| `d99f9d36` | S2 `chatbot_replies.route` (one migration) |
| `50893187` | S3 Inbox and Replies labels |

## What shipped

- **S1** `ChatbotProvider::repliesVia($page, $via)`: when the via is `forbeast` and the page's address
  (`Gateways::route`) is not the default, the address label (`Gateways::routeLabel`, e.g. "Incepxion"); otherwise
  `viaLabel` as before. Used by `FlowRun::vars()` and the flow preview (`ChatbotController::preview`). The help text of
  `replies_via` in `ChatbotFlowController::RUN_VAR_INFO` names the address. With no `GATEWAY_URLS`, `Gateways::route`
  is always `forbeast`, so every label is today's.
- **S2** New nullable column `chatbot_replies.route` VARCHAR(30). `GatewayProvider::route()` gives the address of its
  last call; `ChatbotProvider::routeOf()` reads it; `ChatbotReply::routeColumn($via, $route)` writes it only when the via
  is `forbeast` and the column exists. Written by every gateway send path: flow sends (`BotcakeOutbox::send` →
  `record`), manual Inbox replies, and the sequence pool (`SequenceSendPool` passes `req.route`; `calledSequence` also
  saves it on the ticket before the call, so a send finished by the sweep after a crash keeps it). A default-address
  send stores `forbeast`; Botcake, the incepxionbot gateway, sheet rows and old rows stay null. `via` values and every
  query on `via` are unchanged. Botcake tickets' `result` JSON is unchanged (no `route` key).
- **S3** `ChatbotProvider::viaLabelColumn($via, $route)` adds `via_label` (e.g. "Incepxion") to a reply's JSON only
  when the reply went through a non-default address: the Inbox thread items, the manual-send response, the Replies list
  rows and the Replies detail. The Inbox (`viaText`) and Replies (list and detail "Via") use `via_label` first, then the
  old `VIA` map. Rows without it have exactly today's data.

## Migration

`database/migrations/2026_11_17_100000_add_route_to_chatbot_replies.php`, guarded by `Schema::hasTable` and
`Schema::hasColumn` (running it twice does nothing).

MySQL (production, 8.0.46):

```sql
ALTER TABLE `chatbot_replies` ADD COLUMN `route` VARCHAR(30) NULL, ALGORITHM=INSTANT;
-- down
ALTER TABLE `chatbot_replies` DROP COLUMN `route`, ALGORITHM=INSTANT;
```

`ALGORITHM=INSTANT` is a metadata-only change on MySQL 8.0.29+ (no table copy, no row rewrite). If the server cannot do
it instantly, MySQL raises an error and the migration fails; it never falls back to copying the table. Other drivers
(SQLite in tests, pgsql) use the schema builder's nullable `string('route', 30)`. Run it by hand with
`php artisan migrate --force` as usual. Until it runs, the code writes no `route` (the column check is cached per
process).

## Case table

| Case | Test | Result |
|---|---|---|
| S-224.1.1 | `test_S_224_1_1_a_page_on_forbeast_without_an_address_is_forbeast` | pass |
| S-224.1.2 | `test_S_224_1_2_a_page_on_the_incepxion_address_is_incepxion` | pass |
| S-224.1.3 | `test_S_224_1_3_botcake_stays_botcake_even_with_an_address` | pass |
| S-224.1.4 | `test_S_224_1_4_without_gateway_urls_the_label_is_todays` | pass |
| S-224.1.5 | `test_S_224_1_5_the_variable_help_names_the_address` | pass |
| S-224.2.1 | `test_S_224_2_1_a_flow_send_records_the_address_it_went_through` | pass |
| S-224.2.2 | `test_S_224_2_2_an_inbox_reply_records_the_address` | pass |
| S-224.2.3 | `test_S_224_2_3_only_a_forbeast_send_stores_an_address_and_old_rows_stay_null` | pass |
| S-224.2.4 | `test_S_224_2_4_a_sequence_send_records_the_address_it_went_through` | pass |
| S-224.2.5 | `test_S_224_2_5_a_sequence_send_finished_after_a_crash_keeps_the_address_from_the_call` | pass |
| S-224.2.6 | `test_S_224_2_6_a_sequence_send_on_the_default_address_records_forbeast_and_a_botcake_one_records_nothing` | pass |
| S-224.2.7 | `test_S_224_2_7_the_migration_adds_a_nullable_column_instantly_and_has_a_down` | pass |
| S-224.3.1 | `test_S_224_3_1_the_inbox_says_via_incepxion_only_for_a_reply_that_went_there` | pass |
| S-224.3.2 | `test_S_224_3_2_a_manual_inbox_reply_through_incepxion_comes_back_labelled` | pass |
| S-224.3.3 | `test_S_224_3_3_the_replies_screen_labels_the_address_too` | pass |

Tests: `tests/Feature/Chatblast/ShowRealRouteTest.php`, `tests/Feature/Chatblast/ShowRealRouteSequenceTest.php`.

## Full suite

`php artisan test` (Herd PHP 8.4, SQLite in memory):

| Tree | Tests | Failed | Passed | Warnings |
|---|---|---|---|---|
| `78d48eed` (base) | 4358 | 2 | 206 | 4150 |
| `af110811` (S1) | 4363 | 2 | 206 | 4155 |
| `50893187` (S1–S3) | 4373 | 2 | 206 | 4165 |

The 2 failures are the known ones on the base: `ExampleTest` and `ItemTableFitScriptTest`. The warnings are PHPUnit's
doc-comment metadata deprecation (a test with a warning has passed its assertions).

## Rulings

- A send on the default address stores `route = forbeast` (it records the address it went through); null means "not
  the Forbeast gateway, or written before this column". Both label as "Forbeast".
- Results (`results.blade.php`, grouped by `via`) and Health (`ResponseSpeed`, the bot filter) group and filter by
  bot, not by reply, so they keep `viaLabel(via)` and are unchanged. The `via` filter on Replies is unchanged.
- The Pages screen is unchanged: its "Bot" label (`provLabel`) also drives the Connected match and the bot switch, and
  since spec 223 the page already shows "App: Incepxion" under the bot when more than one address is configured.
- Inbound Inbox items ("via Forbeast" on a customer's message) are unchanged: the incoming event does not say which
  Meta app delivered it.

## Story changes

None to the spec's stories; the cases above were written from them (`qa/stories.md`, "Spec 224").

## Deploy notes

- `af110811` alone: code only, no migration, no env change.
- `d99f9d36` and `50893187`: run the migration (instant on MySQL 8). No env change. No queued-job shape change: the
  resume job tickets and `GatewayAfterSend` arguments are the same; only gateway sequence tickets' `result` JSON gains
  a `route` key, which older code ignores.
