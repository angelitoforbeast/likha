# 251: An index for the send-queue sweep (result)

## S1: the index

Migration `database/migrations/2026_11_22_100000_add_kind_status_updated_at_index_to_botcake_send_requests.php` adds
`botcake_send_requests_kind_status_updated_at_idx` on `(kind, status, updated_at)`. `down()` drops it. Both skip when the
table is missing or the index is already in the wanted state, so a rerun does not fail. On MySQL it runs
`ALTER TABLE ... ADD INDEX ..., ALGORITHM=INPLACE, LOCK=NONE` (the same form as the 2026_11_21 pancake_conversations
index), so MySQL refuses instead of locking the table if it ever cannot build online. On other drivers (the sqlite test
suite) it uses the schema builder. No column changes, no data changes, no query rewrite. One new index in total.

### Why this index

The equality columns lead (`kind`, then `status` as an IN list of two values), then the range column (`updated_at`).
InnoDB appends the primary key to every secondary index, so each entry is effectively `(kind, status, updated_at, id)`.

### Queries it serves

| Query | Where | How |
|---|---|---|
| sweep() redispatch: `kind = 'chatbot' AND status IN ('done','failed') AND updated_at <= ? ORDER BY id LIMIT 500` | app/Services/Chatbot/BotcakeSendQueue.php:290 | Range access on all three columns: two ranges, `('chatbot','done', ..ts]` and `('chatbot','failed', ..ts]`. Only matching entries are read; the few matches are sorted by id (filesort) and cut at 500. |
| Page transfer: `page_id = ? AND kind = 'chatbot' AND status IN ('pending','sending','done','failed')` UPDATE | app/Services/Chatbot/PageTransfers.php:498 | Range on `(kind, status)`: four small status ranges of chatbot rows, skipping all `resumed` rows; `page_id` is checked on those rows. |
| Sequence lateness report: `kind = 'sequence' AND status IN ('pending','sending') AND created_at` in a day | app/Console/Commands/ChatbotSequenceLateness.php:49 | Range on `(kind, status)`: only in-flight sequence rows are read instead of the table. |

Checked and left on their existing indexes (they filter on status first, or on `kind != ...`, which an index cannot
seek):
- claim() BotcakeSendQueue.php:123 `(status, priority, id)` / `(status, next_at)`;
- recover() :240 and recoverSequence() :488 `status = 'sending'` `(status, ...)`;
- sweep() deadline loop :277 `status = 'pending' AND deadline_at` `(status, ...)`;
- sweep() prune :296 `created_at < ? AND kind != 'sequence' AND status IN (...)` (created_at index or PRIMARY);
- sweepSequence() :516 and :528 `status AND priority = 3` `(status, priority, id)`, :526 `created_at` index;
- ChatbotSequences.php:935 and :942 `status AND priority = 3` `(status, priority, id)`;
- lookups by `request_id` (unique) and by `id` (primary): ChatbotSequences.php:753, :1015, FlowEngine.php:405,
  ChatbotRunner.php:230, BotcakeSendQueue.php:140 and the other single-row updates.

Watch after deploy: `kind != 'sequence'` in the sweep() prune (:296) and in recover() (:240) is a two-interval range on
`kind`, so the optimizer may now consider the new index for them. It only picks it when its estimate is cheaper than
the current plan, but a read-only `EXPLAIN` of the prune DELETE on the second site after the migration confirms it.

### EXPLAIN reasoning (MySQL 8)

Before: no index starts with `kind`. For `status IN ('done','failed') ... ORDER BY id LIMIT 500` the optimizer can
either read `(status, priority, id)` / `(status, next_at)` and filesort, or walk PRIMARY in id order and stop after 500
matches. With 0 matching rows the PRIMARY walk reads the whole table (2.2M rows), which fits the 4 s in the slow log.

After: `type: range`, `key: botcake_send_requests_kind_status_updated_at_idx`, `key_len` covering all three columns,
`rows` about the number of chatbot done/failed rows past 30 s (normally 0 to a few), `Extra: Using index condition;
Using filesort`. The index-dive estimate for that range is tiny, so the range plan is far cheaper than the 2.2M-row
PRIMARY walk and the optimizer picks it.

Test evidence: the suite runs on sqlite in memory (phpunit.xml `DB_CONNECTION=sqlite`), with no MySQL connection, so
the plan is checked on sqlite. `tests/Feature/Chatblast/SendQueueSweepIndexTest.php` captures the real SQL that sweep()
runs and asserts `EXPLAIN QUERY PLAN` reads `USING INDEX botcake_send_requests_kind_status_updated_at_idx (kind=? AND
status=? AND updated_at<?)`. Before the migration sqlite used `botcake_send_requests_status_next_at_index (status=?)`
with a temp B-tree for the order.

### Build time (production)

MySQL 8 InnoDB builds a secondary index in place with concurrent reads and writes (`LOCK=NONE`). It takes a short
metadata lock at the start and the end; that lock waits for any open transaction on the table, and queries queue
behind it while it waits, so run it when no long transaction holds the table. Estimate for three short columns plus the
primary key: roughly 10 to 60 s for 2.2M rows and a few seconds for 570K, about 100 to 150 MB of index on the large
table. Writes made during the build go to the online alter log (`innodb_online_alter_log_max_size`, default 128 MB),
far more than the send queue writes in a minute.

## S2: why the table grows (report only, nothing changed)

1. Old sequence rows are removed: sweepSequence() deletes sequence rows (priority 3) in `resumed`, `failed` or `done`
   whose id is at or below the newest id older than KEEP_DAYS, 5000 per status, at most once a minute per site
   (BotcakeSendQueue.php:523-531, gate `Cache::add('sequence-prune:...', 1, 60)` at :525). It runs from the
   chatbot:sequence-pool every 5 s (SequenceSendPool.php:120 via TicketPool.php:140-143) and from chatbot:sequence-tick
   every minute (routes/console.php:95, ChatbotSequences.php:294 then :921), the tick only when `hasInFlight()` is true
   (ChatbotSequences.php:291). Up to 15,000 rows a minute. Pending and sending sequence rows are never pruned.
2. Old chatbot rows: sweep() deletes non-sequence rows older than KEEP_DAYS in `resumed`, `failed` or `done`, 5000 per
   call (BotcakeSendQueue.php:296). sweep() runs from botcake:send-pool every 5 s (BotcakeSendPool.php:73 via
   TicketPool.php:140-143) and from chatbot:resume-skipped every minute (routes/console.php:90,
   ResumeSkippedChatbotWaits.php:32). Even at one call a minute that is 7.2M rows a day, so the 5000 limit alone cannot
   leave 0.6M old rows. The likely reasons, in order:
   - They may not be old. KEEP_DAYS = 2 keeps two full days, and every chatbot send ends as `resumed`. About 300K
     chatbot sends a day would hold 0.6M `resumed` rows with the prune working. A read-only count by age tells:
     `SELECT kind, status, created_at < NOW() - INTERVAL 2 DAY AS old, COUNT(*) FROM botcake_send_requests GROUP BY 1,2,3`.
   - The prune is the last step of sweep(). In chatbot:resume-skipped there is no try/catch around sweep()
     (ResumeSkippedChatbotWaits.php:32), so any error earlier in the run (the 4 s redispatch query hitting a lock wait
     or timeout, a failing resume dispatch) skips that run's prune; the pool catches and reports the same error
     (TicketPool.php:142-146) and also skips the prune.
   - The prune DELETE itself (`created_at < ? AND kind != 'sequence' AND status IN (...) ORDER BY id LIMIT 5000`) has
     no index that matches it: MySQL reads the created_at range and filters, or walks PRIMARY in id order, which is slow
     on a busy table and can time out.
   - 16 runs in 15 minutes matches the once-a-minute cron, not the pool's 5 s sweep; botcake:send-pool may not be
     running on that site, which leaves one sweep (and one prune of 5000) a minute.
3. Not changed here (out of scope): retention, the prune queries, their limits.

## Tests

- New: `tests/Feature/Chatblast/SendQueueSweepIndexTest.php`, 4 tests: exact redispatch rows among 900 resumed rows
  (id order, only chatbot done/failed past 30 s, stamped, not resent at once), at most 500 per run lowest id first, the
  redispatch query reads the new index (sqlite EXPLAIN QUERY PLAN), and the migration up/down/rerun. The two behaviour
  tests pass before and after the migration; the plan and migration tests failed before it.
- Existing sweep tests (BotcakeAsyncSendTest S_089_11, BigBroadcastTest) pass.
- Full suite at code head `d7261ba6`: 4819 tests, 78799 assertions, 2 failures, 6 skipped, 99 PHPUnit deprecations.
  Baseline `d02ed7a1`: 4815 tests with the same 2 failures. The 4 extra tests are the new file. The 2 failures are the
  known ones: `ExampleTest::test_the_application_returns_a_successful_response` (302 instead of 200) and
  `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order` (57 vs 45).
  No new failures. (A first run with `-d memory_limit=-1` gave 5 extra errors in SpeedPrewarmTest: the warm command
  lowers the limit to 512M and fails once usage is above it. That file passes 12/12 with the default limit.)

## Final sha

Branch `fix/251-send-queue-index` from `d02ed7a1`. Code head: `d7261ba6`. This result is committed on top of it.
