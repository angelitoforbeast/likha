# 275: Replied backfill, same 48 hours, a fraction of the work — result

## Summary
`ChatbotTags::backfill()` now starts from the customers who chatted instead of from every unreplied send. The signature,
the `self::ready()` guard, the return value (rows filled), the 48-hour window, the 24-hour reply rule and the schedule are
unchanged. `markReplied()` (the live path) and `MarkChatbotReplied` are untouched.

How it works now:
1. **Pages.** It reads the page_ids in `botcake_chats` one at a time with a keyset walk on the index
   (`WHERE page_id > ? ORDER BY page_id LIMIT 1`, covering index): one short read per page, never the whole table.
2. **Customers per page.** One plain read per page: `SELECT DISTINCT psid FROM botcake_chats WHERE page_id = ? AND
   received_at >= now-48h AND psid IS NOT NULL`, plus today's filters (`active()`, not `_ad_open`). Processed in
   chunks of 1000 customers.
3. **Per chunk, two plain reads:**
   a. the candidate sends: `chatbot_replies WHERE page_id = <int> AND psid IN (<ints>) AND sent_at >= now-48h AND
      replied_at IS NULL AND kind IN (send, manual) AND status = sent` (columns id, psid, sent_at);
   b. the chats of the customers who have candidate sends: `botcake_chats WHERE page_id = '<string>' AND psid IN
      (<strings>) AND received_at > <earliest sent_at> AND received_at <= <latest sent_at + 24 h>`, the same filters,
      `ORDER BY psid, received_at`.
4. **In PHP:** for each send, a binary search over that customer's chats picks the first one with
   `received_at > sent_at` and `received_at <= sent_at + 24 h`, the same rule as the old per-send query. Sends with no
   such chat are skipped.
5. **Updates:** one `UPDATE chatbot_replies SET replied_at, reply_seconds WHERE id = ? AND replied_at IS NULL` per
   matched send, exactly as before (`reply_seconds = max(0, received - sent)`).

There are no joins, no subqueries over `botcake_chats`, no CTAS and no temporary tables. Every write is by primary key.

**Types.** `botcake_chats.page_id` and `.psid` are `varchar(64)`. `chatbot_replies.page_id` and `.psid` are
`unsigned bigint`. Reads on chats bind strings and reads on replies bind ints, so each `IN` compares like types. The old
code compared the string of the number, so only a chat psid/page_id equal to `(string) (int)` of the reply's number
could match: `'07001'` or `'abc'` never matched `7001`. The new code keeps that rule (`idString()`) and has a test for it.

**Why the chats read orders by `psid, received_at`.** The first version ordered by `received_at` only. On SQLite the
planner then chose `(page_id, received_at)` to avoid a sort, which re-reads the page's whole 48-hour window for every
chunk. `ORDER BY psid, received_at` is the order of `(page_id, psid, received_at)`, so that index is chosen and only
the chunk's customers' chats are read. Each customer's chats are still in time order, which the binary search needs.

**Why per page and not one keyset over `(page_id, psid)`.** A `GROUP BY page_id, psid … LIMIT 1000` keyset needs
`received_at >= ?` in the same query. The optimizer could then answer each chunk from the `received_at` (or
`(page_id, received_at)`) range plus a sort, which reads the whole window once per chunk: worse than today. One
`DISTINCT` per page reads that page's 48-hour rows exactly once. The trade-off is that memory holds one page's distinct
psids at a time (about 141k customers across all pages on the larger site, so a few MB for the busiest page) instead
of being strictly flat. Sends, chats and matching are held for one 1000-customer chunk at a time.

## Files changed
- `app/Services/Chatbot/ChatbotTags.php`: `backfill()` rewritten, plus private helpers `replyChats()`, `idString()`,
  `backfillChunk()` and the const `BACKFILL_CHUNK = 1000`.
- `tests/Feature/Chatblast/ChatbotTagsBackfillTest.php`: new (14 tests: 11 cases + the equivalence test on 3 seeds).
- `docs/specs/275-replied-backfill-fast.md` and this result.

## Tests and evidence

### New tests (`ChatbotTagsBackfillTest`)
Every scenario test seeds the case, runs a copy of the **old** `backfill()` (pasted into the test as `oldBackfill()`),
restores `chatbot_replies` to the seed, runs the new one, and asserts the same `(replied_at, reply_seconds)` for every
row and the same return count. Each test also asserts the expected values directly.
- S_275_1: chat within 24 h, filled; the earliest chat wins (an ad-open chat in between is ignored).
- S_275_2: chat 24 h + 1 s after, not filled; exactly 24 h after, filled (86400 s).
- S_275_3: chat before the send or in the same second, not filled.
- S_275_4: ad-open chat, not filled; standby (non-active) chat, not filled.
- S_275_5: same psid on another page, not filled.
- S_275_6: row already replied, unchanged.
- S_275_7: kind `sheet`, status `failed` and `skipped` untouched; `manual` filled.
- S_275_8: send 48 h + 1 s old untouched; exactly 48 h old filled.
- S_275_9: two sends before one chat, both filled (1800 s and 1200 s), as today.
- S_275_10: chat psid `'07001'` / `'abc'` and page `'01001'` do not match 7001 / 1001, as today.
- S_275_11: 2,300 customers (more than two chunks) are all filled.
- S_275_12 (**equivalence**, 3 seeds: 275, 1, 4242): 400 random sends (3 pages, 60 psids, kinds send/manual/sheet,
  statuses sent/failed/skipped, 10% already replied) and 350 random chats (normal, ad-open, standby, some `'0'`-prefixed
  psids). Times are on a 30-minute grid over 60 h with a few ±2 s offsets, so ties at sent_at, the 24 h edge and the
  48 h edge happen often. Old and new give **identical** rows and counts for all three seeds.

As a check that the tests bite, changing the boundary `>` to `>=` in the binary search makes 2 of the new tests fail
(S_275_3 and the equivalence test). The change was then reverted.

`vendor/bin/phpunit --filter ChatbotTags` (the existing ChatbotTagsTest plus the new file):
```
OK, but there were issues!
Tests: 27, Assertions: 145, PHPUnit Deprecations: 106.
```
(The "issues" are the suite's existing PHPUnit deprecations. `php artisan test --filter=ChatbotTags` shows the same 27
passing. It also prints a warning on each test because the worktree has no `.env` file, which this work did not read or
create.)

### Full suite, before and after
- Before (cb1ae4fc): `Tests: 5216, Assertions: 81710, Failures: 3, PHPUnit Deprecations: 106, Skipped: 6.`
  The 3 failures, which existed before this change: `ExampleTest::test_the_application_returns_a_successful_response`,
  `Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`,
  `OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_… "/owner/private"`.
- After: `Tests: 5230, Assertions: 81772, Failures: 3, PHPUnit Deprecations: 106, Skipped: 6.` The same 3 failures as before, nothing new; the 14 extra tests are the new file.

### Local timing (SQLite in-memory, PHP 8.4, the test database)
The seed is 50,000 unreplied sends in the window (plus 5,000 older ones) to 25,000 customers on 2 pages. 5,000 of
those customers chatted, 3 chats each over 50 h with 10% ad-open. There are also 2,000 chats from customers with no
sends and 2,000 older chats: 19,000 chats in all. The old and new runs start from the same seed.

| | Filled | Time | Queries | of which reads |
|---|---|---|---|---|
| Old | 6,394 | 7,838 ms (8,267 ms on an earlier run) | 56,448 | 50,054 |
| New | 6,394 | 725 ms (745 ms on an earlier run) | 6,415 | 21 |

The final state is the same (hash of every row's replied_at and reply_seconds). Almost all of the new run's queries are
the 6,394 per-id updates. This seed fills far more sends than production does, because the live job already fills
most of them there. The 50,000 per-send reads become 21 reads.

### EXPLAIN QUERY PLAN (SQLite, the test database, on the bench data)
```
select "page_id" from "botcake_chats" where "page_id" is not null [and "page_id" > ?] order by "page_id" asc limit 1
  SEARCH botcake_chats USING COVERING INDEX botcake_chats_page_id_received_at_index (page_id>?)

select distinct "psid" from "botcake_chats" where json_extract("payload", '$."_standby"') is null and json_extract("payload", '$."_ad_open"') is null and "page_id" = ? and "received_at" >= ? and "psid" is not null
  SEARCH botcake_chats USING INDEX botcake_chats_page_id_received_at_index (page_id=? AND received_at>?)
  USE TEMP B-TREE FOR DISTINCT

select "id", "psid", "sent_at" from "chatbot_replies" where "page_id" = ? and "psid" in (?, …) and "sent_at" >= ? and "replied_at" is null and "kind" in (?, …) and "status" = ?
  SEARCH chatbot_replies USING INDEX chatbot_replies_page_id_psid_sent_at_index (page_id=? AND psid=? AND sent_at>?)

select "psid", "received_at" from "botcake_chats" where json_extract(...) is null and json_extract(...) is null and "page_id" = ? and "psid" in (?, …) and "received_at" > ? and "received_at" <= ? order by "psid" asc, "received_at" asc
  SEARCH botcake_chats USING INDEX botcake_chats_page_id_psid_received_at_index (page_id=? AND psid=? AND received_at>? AND received_at<?)
```
Every read is an index SEARCH, and no read is a SCAN. No new index is needed.

## Open points
- **MySQL plans were not checked.** Production runs MySQL. The EXPLAIN above is SQLite, the only test database here,
  and no MySQL was available locally. Before release it is worth running `EXPLAIN` on the four reads on one site. Expected:
  the page walk uses `botcake_chats_page_id_received_at_index` (or `…page_id_psid_received_at…`) "Using index";
  the customer read uses `(page_id, received_at)` range; the sends read uses `chatbot_replies_page_id_psid_sent_at_index`;
  the chats read uses `botcake_chats_page_id_psid_received_at_index`.
- **MySQL internal temporary.** The per-page `DISTINCT psid` on MySQL may show "Using temporary". That is MySQL's own
  in-memory dedupe for one statement's result (one page's distinct psids, a few MB). It is not a `CREATE TEMPORARY
  TABLE` and takes no lock beyond a plain read.
- **Time arithmetic.** The 24 h edge is compared in PHP as `sent + 86400 s`, where before it was `addHours(24)` in the
  database. The app timezone is Asia/Manila, which has no daylight saving, so the two are the same.
- **Trailing spaces.** Under MySQL `utf8mb4_unicode_ci` (PAD SPACE), a chat psid `'7001 '` equals `'7001'`. The new
  code strips trailing spaces when it groups, so it follows whatever the database matches, as the old query did.
