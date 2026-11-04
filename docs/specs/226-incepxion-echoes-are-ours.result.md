# Result 226: the Incepxion app's echoes are the bot's own replies

**Branch:** `fix/226-incepxion-echoes` from `b8fc9d63`. **Status:** done, not pushed, not deployed.

| Commit | Story |
|---|---|
| `9ac3cd8f` | the spec |
| `1964e0fa` | S1 and S2 (one fix: config, echo sender and label, Inbox fold, Bot Health doubles) |

## What shipped

- **Config.** `services.gateway.app_ids` = `GATEWAY_APP_IDS`, "key=app_id" separated by space or comma, the same keys
  as `GATEWAY_URLS`. `Gateways::appIds()` parses it (app_id => key); `forbeast=` and malformed entries are skipped, like
  `Gateways::routes()`. Listed in `.env.example`.
- **S1, storing.** `ChatEchoes::sender()` returns `forbeast` for an app id in `GATEWAY_APP_IDS` (after the incepxionbot
  check, before `APPS`). `ChatEchoes::store()` also writes `route` (the address key) into the echo's detail when the app
  is one of them; other echoes have no `route` key, exactly as before.
- **S1, reading.** `ChatEchoes::who($detail)` gives the sender and label of a stored echo. An echo whose `app_id` is in
  `GATEWAY_APP_IDS` is `forbeast` with the address label even when it was stored before the setting (as `other`), so
  the rows already stored for Tech Flask Ph read correctly without a migration. `ChatEchoes::label($sender, $route)`
  says "Bot (Incepxion)" (via `Gateways::routeLabel`) for a forbeast echo on a non-default address.
- **S1, Inbox.** `Inbox::echoes()` uses `who()`: the echo folds into the reply with `via = forbeast` (same text, within
  `ECHO_MATCH_SECONDS`), so there is no "Other app" bubble; the reply's sender reads "Bot (Incepxion)". An unmatched
  echo from that app is its own bubble labelled "Bot (Incepxion)".
- **S2.** Every place that classifies echoes by sender, checked:
  - `ChatEchoes::health()` (Bot Health "Who answered", also read by `BotHealth::banner` and `ProblemsPanel`): the double
    check compared raw `app_id`s, so a Forbeast-app echo next to an Incepxion-app echo counted as a double. It now
    compares the known sender (the app id only for `other`), so both addresses are one bot; double labels use `who()`
    ("Bot (Incepxion) + Botcake"). For every app other than the configured addresses the comparison is the same as
    before (each known sender has one app id).
  - The CBB double checks (`newCustomers`: the `$cbb` closure and the `detail->sender IN (chatbot_builder, botcake)`
    query): unchanged. These echoes were never CBB (stored `other` before, `forbeast` now), so they never counted as CBB.
  - `ChatEchoes::latest()`: uses `who()`, so it says forbeast / "Bot (Incepxion)".
  - Unanswered (Bot Health) and `ForbeastWatch::echoes()`: any echo counts, sender not read; unchanged.
  - `HandleGatewayEvent`: only calls `store()`.

## Env line to add (production)

```
GATEWAY_APP_IDS="incepxion=2067128400607287"
```

Then `php artisan config:cache` as usual. Without it everything is today's behaviour.

## Case table

| Case | Test | Result |
|---|---|---|
| S-226.1.1 | `test_S_226_1_1_an_incepxion_echo_folds_into_the_bot_reply_and_a_forbeast_echo_is_unchanged` | pass |
| S-226.1.2 | `test_S_226_1_2_without_gateway_app_ids_the_same_echo_is_todays_other_app` | pass |
| S-226.1.3 | `test_S_226_1_3_an_unmatched_echo_from_that_app_is_bot_incepxion_and_so_is_one_stored_before_the_setting` | pass |
| S-226.1.4 | `test_S_226_1_4_gateway_app_ids_reads_like_gateway_urls` (4 data sets) | pass |
| S-226.2.1 | `test_S_226_2_1_bot_health_counts_an_incepxion_echo_as_the_bot_not_another_app` | pass |

Tests: `tests/Feature/Chatblast/IncepxionEchoTest.php`. Red run before the code: 7 of 8 failed (S-226.1.2 passed, it
pins today's behaviour); first failure line "Failed asserting that two arrays are identical" (an "Other app" bubble).

## Full suite

`php artisan test` (Herd PHP 8.4, SQLite in memory):

| Tree | Tests | Failed | Passed | Warnings |
|---|---|---|---|---|
| `b8fc9d63` (base) | 4373 | 2 | 206 | 4165 |
| `1964e0fa` | 4381 | 3 | 206 | 4172 |

The 2 known failures: `ExampleTest` and `ItemTableFitScriptTest`. The third, `ChatbotAiProviderPoolTest` S-150 c11, is a
wall-clock timing assertion (3.9975 s against 5 ± 1.0) in the AI provider pool, which this change does not touch; it
passed 3 of 3 runs on its own. The warnings are PHPUnit's doc-comment metadata deprecation.

## Rulings

- Stored rows are reclassified when read (from their `app_id`), not migrated: the echoes already stored as `other`
  for that app show as the bot once the setting is on. The CBB query on `detail->sender` needs no change, since neither
  value is CBB.
- The Inbox fold matches on the bot (`via = forbeast`), not on the reply's `route`, exactly like a Forbeast-app echo
  today (the spec's "exactly like"). A reply on the default address and an echo from the Incepxion app with the same text
  in the same seconds would fold together; that is one bot either way.
- `forbeast=` in `GATEWAY_APP_IDS` is ignored, like in `GATEWAY_URLS`: the Forbeast app id is already known.

## Story changes

- S-226.1.3 also covers an echo stored before the setting, and `ChatEchoes::latest`; S-226.1.4 (the config parsing) was
  added. Both follow from the read-time ruling above.
