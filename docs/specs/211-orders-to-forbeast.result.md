# Result: spec 211 orders to Forbeast

**Status:** done

**Date:** 2026-11-01

**Branch / PR:** `feat/211-orders-to-forbeast` (worktree `.claude/worktrees/orders-fb-211`), from `vps/main` 8cdb1a7b. Not pushed, no PR.

## Summary

Every order the order-encode paths write to `macro_output` is now also sent to Forbeast, to the page's gateway, by a queued job. The encode never waits for the send.

- **Hooks** (OrderEncode only; FlowEngine, `ChatbotAi*` and `ForbeastWatch` are untouched):
  - `LiveOrder::insertRaw`: Upload to macro, Insert.
  - `Upload::run`, after an accepted Update.
  - `LiveOrder::create` and `LiveOrder::update`: the `encode_order` step, after the encode wrote the row.
  - `LiveOrder::encodeRow`: the "Encode order" of an uploaded row, only when it changed something.
  - A failed encode never writes, so it never sends.
- **`App\Services\OrderEncode\ForbeastOrders`** builds and sends `PUT {gateway url}/v1/site/orders/{ref}` with the gateway's site key.
  - **Ref:** `<site>-mo-<macro_output id>`. The site comes from `order_encode.forbeast.site`, else `ForbeastSite::self()` (likha / incepxion, read from `app.url`).
  - **Version:** the write time in ms, always above that order_encodes row's last version.
  - **Mapping:** per the contract: input → details, shop → item (first 200 characters) and shop, plus name, phone, cxd, price and status. `fields` holds the raw A..H.
  - **Cutting:** texts are cut to Forbeast's lengths, as valid UTF-8.
  - **Price:** sent as a number of up to 2 decimals, or null when it is not one.
  - **`ordered_at`:** the row's TIMESTAMP in Manila, `+08:00`, never in the future.
  - **Status:** the row's STATUS, else `uploaded` or `encoded`.
- **`App\Jobs\SendOrderToForbeast`** runs on the database connection, queue `order_encode.forbeast.queue` (default `default`).
  - **2xx:** sent, with `order_id`, `duplicate` and `stale` kept.
  - **404 `page_not_found`, 422 or another 4xx:** failed with the reason, no retry.
  - **Timeout, 5xx, 429, 408, or a 404 without `page_not_found`:** released with backoff 30 s … 1 h until `retryUntil` (24 h), then failed.
  - **401/403:** paused, plus a 15-minute pause flag per gateway. Later sends to that gateway are paused without a call.
  - **Resume:** `order-encode:forbeast-resume` runs every 5 minutes. For each gateway whose pause has lapsed, it re-queues the oldest paused send as a probe; a successful send re-queues the rest of that gateway's paused sends.
  - **Superseded:** when a send of a row succeeds, older failed, paused or queued sends of the same row become `superseded`.
- **State:** three additive columns on `order_encodes`: `forbeast_state` (indexed), `forbeast_version` and `forbeast` (json). No new table. Every state write is a conditional UPDATE on the version, so an older job never overwrites a newer send.
- **Owner list:** `/order-encode/forbeast`, CEO only, shows failed, paused and stuck sends (order ref, row, gateway, tries, page, state, reason, time) with **Send again**. A queued send untouched past the retry time plus 2 h counts as stuck. Send again refuses when a newer send of the same order exists.
- **Go-live switch:** `order_encode.forbeast.since` (env `FORBEAST_ORDERS_SINCE`). When it is empty, nothing is sent. Rows made before it are never sent (no backfill).

## Checks (command and output)

PHP: `C:\Users\Forbeast\.config\herd\bin\php84\php.exe` (8.4.16). Tests run on sqlite in memory, as `phpunit.xml` sets.

| Check | Command | Output |
|---|---|---|
| New tests | `php vendor/bin/phpunit tests/Feature/OrderEncode/ForbeastOrdersTest.php` | `Tests: 24, Assertions: 157, PHPUnit Deprecations: 2` (OK). The deprecations are the doc-comment `@dataProvider`, the same as in the other OrderEncode tests. |
| OrderEncode folder, before | `php vendor/bin/phpunit tests/Feature/OrderEncode` on 8cdb1a7b | `Tests: 488, Assertions: 1367` (OK) |
| OrderEncode folder, after | same | `Tests: 512, Assertions: 1524` (OK) |
| Full suite, before | `php vendor/bin/phpunit --no-progress` on the base | `Tests: 3928, Assertions: 72530, Failures: 3, Skipped: 4` (see below) |
| Full suite, after | same, on e426f997 | `Tests: 3952, Assertions: 72688, Failures: 2, Skipped: 4`: the ExampleTest and ItemTableFitScriptTest failures of the base; no new failure (AstraReplayCommandTest passed this time) |
| Migration up / down / up | `php storage/t211/migrate-check.php`: a throwaway sqlite file, `migrate --path` with the order_encodes migrations, then `migrate:rollback` and `migrate` again | `add_forbeast_to_order_encodes_table … DONE`; columns `forbeast_state,forbeast_version,forbeast`, index `order_encodes_forbeast_state`; after rollback: `(none)`; after migrating again: the three columns |

The base failures (`storage/t211/base-failing.txt`) are not caused by this branch:

- `ExampleTest::test_the_application_returns_a_successful_response`
- `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
- `AstraReplayCommandTest::test_S_31_3_the_command_only_runs_select_statements_and_changes_no_table_and_no_file`

## Red lines

Every red line below was watched before the code it covers.

1. **Before any code:** `tests/Feature/OrderEncode/ForbeastOrdersTest.php` on commit 8c51cec8 gave `Tests: 20, Assertions: 0, Errors: 20`. Every case failed with `File does not exist at path …/2026_11_15_100000_add_forbeast_to_order_encodes_table.php`; there was no migration, service or job yet. Output: `storage/t211/red-1-before-code.txt`.
2. **The hooks:** `LiveOrder` and `Upload` were put back to 8cdb1a7b, keeping the new classes, and the new tests were run. Result: `Tests: 20, Errors: 9, Failures: 8`.
   - Red: every case that needs a send (.1, .2 ×2, .3, .4, .7 ×2, .8, .9 ×5, .10, .11, .12, .13), e.g. "expected pushed 1, got 0".
   - Green, as expected: the three "nothing is sent" cases (.5, .6, .14), which hold without hooks.
   - Output: `storage/t211/red-2-hooks-reverted.txt`.
3. **The review fixes:** `ForbeastOrders` and the controller were put back to 2c6fc60a and the four new tests were run. Result: `Tests: 4, Failures: 3`.
   - Red: a 404 without `page_not_found` was failed for good instead of retried; Send again replaced a newer send; stuck queued sends were not listed.
   - Green on the old code: "one gateway never holds back the other". With only two rows the old 500-row window still reached both gateways, so this test guards the SQL filter without having been red.
   - Output: `storage/t211/red-3-review-fixes.txt`.

## Done-when checklist

- [x] Cases S-211.1.1 – S-211.1.14 pass, each with a test named after it. There are 20 test methods (24 with the data sets), all `test_S_211_1_<case>_…`.
- [x] The result has a red line for every feat/fix slice, watched before the code (see Red lines).
- [x] The full suite adds no new failures (after: 2 failures, both already failing on the base), and the migration runs and rolls back (see Checks).
- [x] `qa/stories.md` has the stories under `## Slice 211 – Orders to Forbeast`, with Test and "Last run" (passed 2026-11-01) filled.
- [x] `docs/specs/211-orders-to-forbeast.result.md` is filled in.

## Rulings

- Ruling: **which pages send.** The page's `chatbot.gateway` row when it is valid, else `Gateways::active` when the page's `chatbot.provider` is `gateway`; the gateway must be `ForbeastSite::linked` (URL, site key and secret).
  - Why: "a page whose chatbot.gateway is set up". A Botcake page with no gateway row is not served by Forbeast.
  - Cost if wrong: a Botcake-only page that Forbeast knows would not send. It is a one-line change in `ForbeastOrders::gateway`.
- Ruling: **go-live is a config time** (`FORBEAST_ORDERS_SINCE`); empty means off, and rows whose `created_at` is earlier are never sent, even when they are updated later.
  - Why: no backfill, plus a clean switch that needs no deploy to turn off.
  - Cost if wrong: nothing is sent until the env is set at deploy (see Deploy notes).
- Ruling: **one ref per `macro_output` row; the state lives on each `order_encodes` row that wrote it.** A row may carry the upload's send and then the encode's; an Update is a new `order_encodes` row with its own send.
  - Why: no new table. The version and the superseded marking keep Forbeast on the newest write.
  - Cost if wrong: the list shows one line per send, not per order.
- Ruling: **the payload is built at send time** from the order_encodes fields and the row's current STATUS; the version is fixed at the write.
  - Why: no duplicate copy of the conversation stored per send.
  - Cost if wrong: see the deferred minor on status under a lost reply.
- Ruling: **name and phone are the raw C and D, not the encoded FULL NAME / PHONE NUMBER.**
  - Why: the spec maps A..H.
  - Cost if wrong: Forbeast shows the FB name and the flow's phone. It is a small change in `payload()`.
- Ruling: **an unparseable price is sent as null** (the raw value stays in `fields.H`).
  - Why: Forbeast answers 422 to anything that is not `\d{1,10}(\.\d{1,2})?`, and the whole order would be refused for its price.
  - Cost if wrong: the order shows with no price in Forbeast.
- Ruling: **`fields` values are cut to 5,000 characters.**
  - Why: `SiteOrderInput` refuses longer values, which would refuse the order.
  - Cost if wrong: a very long conversation is cut in `fields.E`; the same text is also cut in `details`.
- Ruling: **outages are retried with `release()`, not a thrown exception, for 24 h (`retry_hours`).**
  - Why: an outage is not an app error, so it produces no stack trace per attempt; `retryUntil` overrides the worker's `--tries`.
  - Cost if wrong: after 24 h the send is failed and needs Send again.
- Ruling: **401/403 pause per gateway, with a probe every 5 minutes.**
  - Why: "sending pauses and the failure is visible; nothing is dropped".
  - Cost if wrong: while a key stays wrong, one probe call per gateway goes out every 5 minutes.
- Ruling: **a 404 without `page_not_found` is retried.**
  - Why: it means the route is missing on the gateway (e.g. a gateway not yet serving orders), not a refusal of the order.
  - Cost if wrong: such sends retry for 24 h, then fail.
- Ruling: **the queue is `database` / `default`.**
  - Why: the encode jobs are already on the database connection, and the `default` database queue has its own Supervisor group (per `config/horizon.php`). The send never waits behind GPT jobs on `encode`.
  - Cost if wrong: sends sit queued until a worker takes `default`, and show as stuck after 26 h.
- Ruling: **the base is `vps/main` 8cdb1a7b, as the worktree was created**, not `vps2/main` as the spec table says.
  - Cost if wrong: a rebase before merge.

## Story changes

None to the 14 cases. Added test methods under existing case IDs, from the review:

- S-211.1.7: a 404 without `page_not_found` is retried.
- S-211.1.10: one gateway's paused sends never hold back the other.
- S-211.1.13: Send again never replaces a newer send; stuck queued sends show.

## Deferred minors

- **Status under a lost reply:** the status is read at send time but the version is fixed. If a first try reached Forbeast but the reply was lost, and STATUS changed before the retry, Forbeast answers `duplicate` and keeps the older status. The next write of the row sends the new one.
- **Crash between write and queue:** a crash between `MacroOutput::create` and the queue call (same request, microseconds apart) loses that row's send. The next write of the row, or nothing, follows.
- **Not-linked pause:** a gateway that is not linked any more pauses its sends with no pause flag, so the probe re-queues one of them every 5 minutes. That costs no HTTP call, because it stops before calling.
- **Batch size:** the resume batch is 500 paused sends per successful send. A larger backlog is picked up by the next successes and probes.

## Deploy notes

- Run the migration: `2026_11_15_100000_add_forbeast_to_order_encodes_table` is additive (three nullable columns and one index on `order_encodes`).
- Go-live: set `FORBEAST_ORDERS_SINCE` to the go-live time (ISO 8601 with offset, e.g. `2026-11-02T00:00:00+08:00`), then `php artisan config:cache`. Until it is set, nothing is sent.
- Optional settings: `FORBEAST_ORDERS_SITE` (ref prefix; default from `app.url`), `FORBEAST_ORDERS_QUEUE` (default `default`), `FORBEAST_ORDERS_RETRY_HOURS` (24), `FORBEAST_ORDERS_PAUSE_MINUTES` (15).
- Confirm a worker consumes the `database` connection's `default` queue on each server (Likha and Incepxion). The repo's `deploy/supervisor` files do not include it; the horizon config says it has its own Supervisor group.
- The schedule needs `schedule:run` for `order-encode:forbeast-resume`.
- Forbeast must have spec 042 (`PUT /api/v1/site/orders/{ref}`) live on each gateway that pages use (forbeast and incepxionbot). Until then, sends to that gateway retry (404 without `page_not_found`) and then fail, visibly.
- Merge danger:
  - **Door:** outbound only. Customer order text goes to the page's own gateway, with the site key in the header.
  - **Blast radius:** the encode and upload paths call one guarded method that never throws and dispatches after commit; Forbeast down only queues retries.
  - **Revert:** unset `FORBEAST_ORDERS_SINCE` (instant off), or revert the branch. The migration rolls back cleanly.

## Open questions

None blocking.

## Proposed tasks

- Snapshot the payload per version (or send the row's STATUS on the next write only) to close the "status under a lost reply" minor.
- Add `likha-forbeast-orders` (database `default`) to `deploy/supervisor` so the worker is in the repo.

211: done — encoded and uploaded orders are queued to the page's Forbeast gateway (PUT /v1/site/orders/{ref}) with retry, pause and a CEO failed list; off until FORBEAST_ORDERS_SINCE is set
