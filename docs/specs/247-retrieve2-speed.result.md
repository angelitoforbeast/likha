# Spec 247 result: the Retrieve 2 page answers in seconds instead of timing out

Status: done on branch `fix/247-retrieve2-speed` (from `eeb2e1f2`), with amendment 247-1 applied. Code head: `181896b2`. This result is committed on top of it. The original result follows the amendment section and still describes the `sp` set where the amendment does not override it.

## Amendments applied

### Amendment 247-1: a one-space IN probe replaces the "sp" set (2026-11-08)

**Why.** The reviewer's read-only check on production found that the `sp` scan alone costs 8.3 s per request: `fb_name LIKE '% '` can only full-scan the index. The data also showed:
- no `PAGE` has leading whitespace;
- exactly 7 `fb_name` values have a leading space, each a single 0x20;
- 52 `fb_name` values have trailing spaces only, which PAD SPACE already ignores.

**What changed.**
- `spacedOrderNames()` and the `sp` left join are removed.
- `nameNotYetAnOrderSql()` now matches the name with `mo.fb_name IN (TRIM(pc.full_name), CONCAT(' ', TRIM(pc.full_name)))`. That is two lookups on `(PAGE, fb_name)`.
- The bare `mo.PAGE = TRIM(...)` stays.
- The sqlite-only `COLLATE NOCASE` stays. For the IN, it sits on the left operand (`mo.fb_name COLLATE NOCASE IN (...)`), because sqlite takes an IN list's collation from its left side. MySQL gets no extra text.

**Accepted gap (reviewer's decision).** An order name with two or more leading spaces would no longer hide its conversation. There are 0 such rows today.

The sections below that describe `sp` (plan step 4 and the "Why the results stay the same" case for spaced rows) are superseded by this amendment. For today's data, the IN probe matches what the old `LOWER(TRIM())` matched.

**Tests (tests first, `b29c70ac`; with the old head only the shape test failed):**
- New: `test_a_single_leading_space_on_the_order_name_hides_the_unpadded_conversation_name_on_its_page_only`. An order `' mia lopez'` hides `Mia Lopez` on Page Uno and not on Page Dos.
- `test_an_order_whose_fb_name_has_a_leading_space_still_hides_its_conversation` still passes unchanged.
- The query-shape test now asserts:
  - the IN probe is present;
  - the main statement has no `LIKE` at all and no `' %'`/`'% '` binding;
  - there is no `group by LOWER(TRIM(PAGE)), LOWER(TRIM(fb_name))`.
- Test changes:
  - The "surrounding spaces" case uses `' Cara LIM  '` (one leading space) instead of two, per the accepted gap.
  - Without `sp`, sqlite has nothing that ignores trailing spaces. This test file therefore registers a test-only `NOCASE` collation that behaves like `utf8mb4_unicode_ci`: case-insensitive, with trailing spaces ignored. It is registered the same way as the `CONVERT_TZ` stand-in. The controller is unchanged by this.
- `tests/Feature/PancakePsid/`: OK, 34 tests, 165 assertions.

**Full suite at `181896b2` (shim bootstrap):**

| Tests | Passed | Failed | Skipped | Deprecations |
|---|---|---|---|---|
| 4805 | 4797 | 2 | 6 | 99 |

The 2 failures are the same known ones as at base `eeb2e1f2` (4792 tests: 4784 passed, 2 failed, 6 skipped): `ExampleTest` and `ItemTableFitScriptTest`. There are no new failures.

**Production check already done by the reviewer** with head `120b2578`, before the migration, Manila day 2026-11-07:
- old 17 rows, new 17 rows, 0 differences;
- the NOT EXISTS uses `macro_output_page_fb_name_idx`;
- `pancake_page_id` is `utf8mb4_unicode_ci`.

That check ran the `sp` version. The amended query has not been re-checked on production; see "Check before release".

**New page SQL (MySQL grammar, `toSql()`, 2026-10-14 to 2026-10-20).** The export is the same without `limit 200 offset 0`.

```sql
select 
            CONVERT_TZ(pc.created_at,'+00:00','+08:00') as date_created,
            COALESCE(pi.pancake_page_name, pc.pancake_page_id) as page,
            sm.shop_details as shop_details,
            pc.full_name,
            pc.psid as psid,
            pc.customers_chat
         from `pancake_conversations` as `pc` left join `pancake_id` as `pi` on `pi`.`pancake_page_id` = `pc`.`pancake_page_id` left join (select sc.ts_date, sc.page_key, MIN(sc.shop_details) as shop_details from (select 
                ts_date,
                LOWER(TRIM(`PAGE`)) as page_key,
                TRIM(`SHOP DETAILS`) as shop_details,
                COUNT(*) as cnt
             from `macro_output` where `ts_date` is not null and TRIM(ts_date) <> '' and `ts_date` between ? and ? and `PAGE` is not null and TRIM(`PAGE`) <> '' and `SHOP DETAILS` is not null and TRIM(`SHOP DETAILS`) <> '' group by ts_date, LOWER(TRIM(`PAGE`)), TRIM(`SHOP DETAILS`)) as `sc` inner join (select ts_date, page_key, MAX(cnt) as max_cnt from (select 
                ts_date,
                LOWER(TRIM(`PAGE`)) as page_key,
                TRIM(`SHOP DETAILS`) as shop_details,
                COUNT(*) as cnt
             from `macro_output` where `ts_date` is not null and TRIM(ts_date) <> '' and `ts_date` between ? and ? and `PAGE` is not null and TRIM(`PAGE`) <> '' and `SHOP DETAILS` is not null and TRIM(`SHOP DETAILS`) <> '' group by ts_date, LOWER(TRIM(`PAGE`)), TRIM(`SHOP DETAILS`)) as `scm` group by ts_date, page_key) as `mx` on `mx`.`ts_date` = `sc`.`ts_date` and `mx`.`page_key` = `sc`.`page_key` and `mx`.`max_cnt` = `sc`.`cnt` group by sc.ts_date, sc.page_key) as `sm` on `sm`.`ts_date` = DATE(CONVERT_TZ(pc.created_at,'+00:00','+08:00')) and `sm`.`page_key` = LOWER(TRIM(COALESCE(pi.pancake_page_name, pc.pancake_page_id))) where ((
            (pc.psid IS NULL AND (NOT EXISTS (
                SELECT 1 FROM macro_output mo
                WHERE mo.`PAGE` = TRIM(COALESCE(pi.pancake_page_name, pc.pancake_page_id))
                  AND mo.`fb_name` IN (TRIM(pc.full_name), CONCAT(' ', TRIM(pc.full_name)))
                  AND TRIM(mo.`PAGE`) <> ''
                  AND TRIM(mo.`fb_name`) <> ''
            )))
            OR (pc.psid IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM macro_output mo
                WHERE mo.botcake_psid = pc.psid
                  AND (pi.pancake_page_name IS NULL OR LOWER(TRIM(mo.`PAGE`)) = LOWER(TRIM(pi.pancake_page_name)))
            ))
        )) and `pc`.`created_at` >= ? and `pc`.`created_at` < ? order by `pc`.`created_at` desc limit 200 offset 0
```

Bindings: `["2026-10-14","2026-10-20","2026-10-14","2026-10-20","2026-10-13 16:00:00","2026-10-20 16:00:00"]`

## Plan (as built)

1. **One query for both endpoints.** `index` and `export` now call `range()` (Manila date range from the request, presets unchanged) and `retrieveQuery()` (the whole query). The page paginates it, the export calls `get()` on it.
2. **Date range on the bare column.** `range()` returns Manila dates. `retrieveQuery()` turns them into UTC bounds in PHP: `pc.created_at >= <from 00:00 Manila in UTC>` and `pc.created_at < <day after to, 00:00 Manila in UTC>`. Manila has no DST, so these bounds are exact. `date_created` and the shop-mode join still use `CONVERT_TZ` as before. They are only in the SELECT and ON, never in the filter.
3. **Name rule = correlated `NOT EXISTS` on the bare `(PAGE, fb_name)`.** For a conversation without a PSID (or when the PSID columns are missing), the rule is:
   `sp.name_key IS NULL AND NOT EXISTS (SELECT 1 FROM macro_output mo WHERE mo.PAGE = TRIM(<page>) AND mo.fb_name = TRIM(pc.full_name) AND TRIM(mo.PAGE) <> '' AND TRIM(mo.fb_name) <> '')`.
   The `mo` columns are bare, so `macro_output_page_fb_name_idx` can be probed. `utf8mb4_unicode_ci` already ignores letter case and trailing spaces in `=`. Only the conversation side is trimmed, which does not block the index.
4. **Order names with leading spaces (`sp`).** A bare `=` cannot match `' Juan'` to `'Juan'`. A small grouped set handles these rows: macro_output rows whose `PAGE` or `fb_name` starts or ends with a space (`LIKE ' %'` / `LIKE '% '`; LIKE does not pad). They are keyed by `LOWER(TRIM())` exactly as before and left-joined once. On production this covers the 59 `fb_name` values. It costs one read of `macro_output` per request, filtered, with no grouping of the whole table. No new column is added.
5. **PSID rule untouched.** The `pc.psid IS NOT NULL` branch is byte-for-byte the same. In the PSID path, the name rule is evaluated only for `pc.psid IS NULL` rows, inside the same OR.
6. **sqlite only:** `COLLATE NOCASE` is added to the two bare comparisons, because sqlite compares binary. MySQL gets no extra text.
7. **Migration:** `2026_11_21_100000_add_created_at_index_to_pancake_conversations.php` adds `pancake_conversations_created_at_idx (created_at)`. On MySQL it runs `ALTER TABLE ... ADD INDEX ..., ALGORITHM=INPLACE, LOCK=NONE` (online; MySQL refuses instead of locking if it cannot do it online). It skips if the index exists, and `down()` drops it only if it is there. Other drivers use the schema builder.
8. `shopMode` is left as it was, since it is bounded by `ts_date`. The plan on production should confirm that it is not the slow part.

### Why the results stay the same

Old rule: an order hides a conversation iff `LOWER(TRIM(PAGE)) = LOWER(TRIM(page))` and `LOWER(TRIM(fb_name)) = LOWER(TRIM(full_name))` under `utf8mb4_unicode_ci`, with non-empty trimmed `PAGE`/`fb_name`. Every macro_output row falls into one of two cases:
- **No leading or trailing space** in `PAGE`/`fb_name`: `TRIM()` is a no-op on that side, and `LOWER()` is a no-op under a case-insensitive collation. So the old test equals `mo.PAGE = TRIM(page) AND mo.fb_name = TRIM(full_name)`, which is the bare `NOT EXISTS` branch.
- **Has a leading or trailing space**: the row is in `sp` and is compared with exactly the old `LOWER(TRIM())` keys.

The union of the two branches is the old rule. A spaced row can also match the bare branch through PAD SPACE, but only when the old rule would match it too.

## Done-when evidence

### 1. Feature tests prove every case of S1 (page and export)

New file `tests/Feature/PancakePsid/PancakeRetrieve2SpeedTest.php`. Every behaviour test calls both `/pancake/retrieve2/export` and `/pancake/retrieve2` through one helper. The helper asserts that the two list the same rows, and checks the export `count` and the page `total()`. The sqlite `CONVERT_TZ` stand-in in this file really shifts UTC to Manila, so the boundary is real.

| Test | Covers |
|---|---|
| `test_a_conversation_without_a_matching_order_is_listed` | S1 happy: no order, listed |
| `test_an_order_with_the_same_page_and_name_in_any_letter_case_and_surrounding_spaces_hides_the_conversation` | S1 happy: case, trailing spaces, both-side spaces, case-different PAGE |
| `test_an_order_whose_fb_name_has_a_leading_space_still_hides_its_conversation` | S1 edge: leading space |
| `test_a_page_name_that_differs_only_in_letter_case_or_trailing_spaces_still_matches` | S1 edge: page case / trailing spaces |
| `test_a_conversation_is_never_hidden_by_an_order_from_a_different_page` | S1 negative |
| `test_an_empty_fb_name_never_hides_a_conversation_with_an_empty_name` | old `TRIM() <> ''` guard kept |
| `test_the_manila_day_boundary_decides_the_range_from_the_utc_created_at` | S1 edge: 23:59:59/00:00 at both ends of the range, and a 2-day range |
| `test_the_psid_rule_still_decides_for_a_conversation_with_a_psid` | S1 happy: PSID rule unchanged |
| `test_the_page_and_name_filters_still_narrow_the_rows` | `page_contains` / `name_contains` unchanged, bound params |
| `test_the_shop_details_of_the_day_and_page_still_come_with_the_row` | shop mode and `date_created` unchanged |
| `test_the_query_filters_the_bare_created_at_and_probes_macro_output_by_page_and_name` | S2 shape: bare `created_at` with UTC bounds, bare `(PAGE, fb_name)` probe, no whole-table group |
| `test_the_migration_adds_the_created_at_index_once_and_down_removes_it` | S2 index: up, idempotent up, down, idempotent down |

Tests first: at the test commit `e5fa0655`, the 10 behaviour tests passed against the **old** query (proof that the cases describe today's results), and the 2 shape/migration tests failed. With the fix all 12 pass. One assertion changed after the fix: "no `group by LOWER(TRIM(PAGE)), LOWER(TRIM(fb_name))`" became "never the whole-table group (the one without the `like` filter)", because the small `sp` set legitimately keeps that GROUP BY text. No story changed.

`tests/Feature/PancakePsid/` at head: **OK, 33 tests, 157 assertions** (including the unchanged `PancakeReadersPsidTest`).

### 2. Full suite, shim bootstrap, base vs head

| Commit | Tests | Passed | Failed | Skipped |
|---|---|---|---|---|
| base `eeb2e1f2` | 4792 | 4784 | 2 | 6 |
| head `3c621ed6` | 4804 | 4796 | 2 | 6 |

The same 2 failures appear at both commits, both known:
- `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response`
- `Tests\Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`

(99 PHPUnit deprecations at both.) The base run used a clean checkout of `eeb2e1f2` in this worktree.

**Local MySQL:** a MySQL 8.0 service runs on this machine, but there are no credentials for it here, so the S1 cases were **not** run against MySQL. The reviewer's read-only old/new comparison on production is needed before release.

### 3. One migration

`database/migrations/2026_11_21_100000_add_created_at_index_to_pancake_conversations.php`: online (`ALGORITHM=INPLACE, LOCK=NONE`), idempotent both ways, with `down()`.

### 4. Branch

`fix/247-retrieve2-speed` pushed to `origin`.

## Old and new SQL (MySQL grammar, `toSql()`, one 7-day range 2026-10-14 to 2026-10-20)

These were printed by building the queries against a `mysql` connection that never connected: statements were captured before execution, with the PSID columns assumed present as on production.

The page runs the same statement as the export, with `limit 200 offset 0` appended. Its count query is `select count(*) as aggregate` over the same FROM/JOIN/WHERE.

### Old

```sql
select 
            CONVERT_TZ(pc.created_at,'+00:00','+08:00') as date_created,
            COALESCE(pi.pancake_page_name, pc.pancake_page_id) as page,
            sm.shop_details as shop_details,
            pc.full_name,
            pc.psid as psid,
            pc.customers_chat
         from `pancake_conversations` as `pc` left join `pancake_id` as `pi` on `pi`.`pancake_page_id` = `pc`.`pancake_page_id` left join (select LOWER(TRIM(`PAGE`)) as page_key, LOWER(TRIM(`fb_name`)) as name_key from `macro_output` where `PAGE` is not null and TRIM(`PAGE`) <> '' and `fb_name` is not null and TRIM(`fb_name`) <> '' group by LOWER(TRIM(`PAGE`)), LOWER(TRIM(`fb_name`))) as `mp` on `mp`.`page_key` = LOWER(TRIM(COALESCE(pi.pancake_page_name, pc.pancake_page_id))) and `mp`.`name_key` = LOWER(TRIM(pc.full_name)) left join (select sc.ts_date, sc.page_key, MIN(sc.shop_details) as shop_details from (select 
                ts_date,
                LOWER(TRIM(`PAGE`)) as page_key,
                TRIM(`SHOP DETAILS`) as shop_details,
                COUNT(*) as cnt
             from `macro_output` where `ts_date` is not null and TRIM(ts_date) <> '' and `ts_date` between ? and ? and `PAGE` is not null and TRIM(`PAGE`) <> '' and `SHOP DETAILS` is not null and TRIM(`SHOP DETAILS`) <> '' group by ts_date, LOWER(TRIM(`PAGE`)), TRIM(`SHOP DETAILS`)) as `sc` inner join (select ts_date, page_key, MAX(cnt) as max_cnt from (select 
                ts_date,
                LOWER(TRIM(`PAGE`)) as page_key,
                TRIM(`SHOP DETAILS`) as shop_details,
                COUNT(*) as cnt
             from `macro_output` where `ts_date` is not null and TRIM(ts_date) <> '' and `ts_date` between ? and ? and `PAGE` is not null and TRIM(`PAGE`) <> '' and `SHOP DETAILS` is not null and TRIM(`SHOP DETAILS`) <> '' group by ts_date, LOWER(TRIM(`PAGE`)), TRIM(`SHOP DETAILS`)) as `scm` group by ts_date, page_key) as `mx` on `mx`.`ts_date` = `sc`.`ts_date` and `mx`.`page_key` = `sc`.`page_key` and `mx`.`max_cnt` = `sc`.`cnt` group by sc.ts_date, sc.page_key) as `sm` on `sm`.`ts_date` = DATE(CONVERT_TZ(pc.created_at,'+00:00','+08:00')) and `sm`.`page_key` = LOWER(TRIM(COALESCE(pi.pancake_page_name, pc.pancake_page_id))) where ((
            (pc.psid IS NULL AND mp.name_key IS NULL)
            OR (pc.psid IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM macro_output mo
                WHERE mo.botcake_psid = pc.psid
                  AND (pi.pancake_page_name IS NULL OR LOWER(TRIM(mo.`PAGE`)) = LOWER(TRIM(pi.pancake_page_name)))
            ))
        )) and DATE(CONVERT_TZ(pc.created_at,'+00:00','+08:00')) >= ? and DATE(CONVERT_TZ(pc.created_at,'+00:00','+08:00')) <= ? order by `pc`.`created_at` desc
```

Bindings: `["2026-10-14","2026-10-20","2026-10-14","2026-10-20","2026-10-14","2026-10-20"]`

### New (before amendment 247-1; the amended page SQL is in "Amendments applied")

```sql
select 
            CONVERT_TZ(pc.created_at,'+00:00','+08:00') as date_created,
            COALESCE(pi.pancake_page_name, pc.pancake_page_id) as page,
            sm.shop_details as shop_details,
            pc.full_name,
            pc.psid as psid,
            pc.customers_chat
         from `pancake_conversations` as `pc` left join `pancake_id` as `pi` on `pi`.`pancake_page_id` = `pc`.`pancake_page_id` left join (select LOWER(TRIM(`PAGE`)) as page_key, LOWER(TRIM(`fb_name`)) as name_key from `macro_output` where (`PAGE` like ? or `PAGE` like ? or `fb_name` like ? or `fb_name` like ?) and TRIM(`PAGE`) <> '' and TRIM(`fb_name`) <> '' group by LOWER(TRIM(`PAGE`)), LOWER(TRIM(`fb_name`))) as `sp` on `sp`.`page_key` = LOWER(TRIM(COALESCE(pi.pancake_page_name, pc.pancake_page_id))) and `sp`.`name_key` = LOWER(TRIM(pc.full_name)) left join (select sc.ts_date, sc.page_key, MIN(sc.shop_details) as shop_details from (select 
                ts_date,
                LOWER(TRIM(`PAGE`)) as page_key,
                TRIM(`SHOP DETAILS`) as shop_details,
                COUNT(*) as cnt
             from `macro_output` where `ts_date` is not null and TRIM(ts_date) <> '' and `ts_date` between ? and ? and `PAGE` is not null and TRIM(`PAGE`) <> '' and `SHOP DETAILS` is not null and TRIM(`SHOP DETAILS`) <> '' group by ts_date, LOWER(TRIM(`PAGE`)), TRIM(`SHOP DETAILS`)) as `sc` inner join (select ts_date, page_key, MAX(cnt) as max_cnt from (select 
                ts_date,
                LOWER(TRIM(`PAGE`)) as page_key,
                TRIM(`SHOP DETAILS`) as shop_details,
                COUNT(*) as cnt
             from `macro_output` where `ts_date` is not null and TRIM(ts_date) <> '' and `ts_date` between ? and ? and `PAGE` is not null and TRIM(`PAGE`) <> '' and `SHOP DETAILS` is not null and TRIM(`SHOP DETAILS`) <> '' group by ts_date, LOWER(TRIM(`PAGE`)), TRIM(`SHOP DETAILS`)) as `scm` group by ts_date, page_key) as `mx` on `mx`.`ts_date` = `sc`.`ts_date` and `mx`.`page_key` = `sc`.`page_key` and `mx`.`max_cnt` = `sc`.`cnt` group by sc.ts_date, sc.page_key) as `sm` on `sm`.`ts_date` = DATE(CONVERT_TZ(pc.created_at,'+00:00','+08:00')) and `sm`.`page_key` = LOWER(TRIM(COALESCE(pi.pancake_page_name, pc.pancake_page_id))) where ((
            (pc.psid IS NULL AND (sp.name_key IS NULL AND NOT EXISTS (
                SELECT 1 FROM macro_output mo
                WHERE mo.`PAGE` = TRIM(COALESCE(pi.pancake_page_name, pc.pancake_page_id))
                  AND mo.`fb_name` = TRIM(pc.full_name)
                  AND TRIM(mo.`PAGE`) <> ''
                  AND TRIM(mo.`fb_name`) <> ''
            )))
            OR (pc.psid IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM macro_output mo
                WHERE mo.botcake_psid = pc.psid
                  AND (pi.pancake_page_name IS NULL OR LOWER(TRIM(mo.`PAGE`)) = LOWER(TRIM(pi.pancake_page_name)))
            ))
        )) and `pc`.`created_at` >= ? and `pc`.`created_at` < ? order by `pc`.`created_at` desc
```

Bindings: `[" %","% "," %","% ","2026-10-14","2026-10-20","2026-10-14","2026-10-20","2026-10-13 16:00:00","2026-10-20 16:00:00"]`

## Migration

`2026_11_21_100000_add_created_at_index_to_pancake_conversations` adds the index `pancake_conversations_created_at_idx` on `pancake_conversations (created_at)`.

## Files changed

- `app/Http/Controllers/PancakeRetrieve2Controller.php`:
  - shared `range()` and `retrieveQuery()`;
  - new `nameNotYetAnOrderSql()` (the one-space IN probe since amendment 247-1);
  - `notYetAnOrder()` uses the new name rule; its PSID branch is unchanged.
- `database/migrations/2026_11_21_100000_add_created_at_index_to_pancake_conversations.php` (new)
- `tests/Feature/PancakePsid/PancakeRetrieve2SpeedTest.php` (new, 13 tests)
- `docs/specs/247-retrieve2-speed.md` (spec, unchanged copy) and this result

## Commits

- `2f0875ac` docs: spec 247 retrieve2 speed
- `e5fa0655` test: retrieve2 keeps its rows while filtering created_at bare and probing macro_output by page and name (spec 247)
- `7ed958bb` fix: retrieve2 filters created_at with UTC bounds and probes macro_output by bare page and name (spec 247)
- `3c621ed6` fix: online index on pancake_conversations.created_at for retrieve2 (spec 247)
- `120b2578` docs: spec 247 result
- `b29c70ac` test: retrieve2 probes a one-leading-space order name by index and scans macro_output by no pattern (spec 247, amendment 1)
- `181896b2` fix: retrieve2 matches a one-leading-space order name with an index probe instead of scanning macro_output (spec 247, amendment 1) (code head)
- plus the commit of this updated result (branch head)

## Check before release

1. **Re-run the read-only old/new comparison and EXPLAIN on the amended query.** The reviewer's check ran the `sp` version. Expected:
   - same rows as old;
   - the `mo` NOT EXISTS uses `ref`/`range` on `macro_output_page_fb_name_idx` with both key parts (two values from the IN);
   - no full scan of `macro_output` anywhere except what `shopMode` does inside its `ts_date` range;
   - `shopMode` is not the slow part.
2. **Plan with the new index.** After the migration, `pc` should show a range on `pancake_conversations_created_at_idx`. The reviewer's run was before the migration.
3. **Migration window.** The online index add on about 730k rows takes seconds to a minute. It needs a short metadata lock at its start and end, so run it when no long transaction holds `pancake_conversations`. If MySQL answers that `LOCK=NONE` is not supported, it changed nothing; report back rather than dropping the clause.
4. **Time zone.** `created_at` is compared as stored, like the old `CONVERT_TZ(created_at,'+00:00','+08:00')`, which assumed the session shows UTC. Keep the connection's session time zone as it is.
5. **Accepted gap.** A future order name with two or more leading spaces would not hide its conversation. There are 0 such rows today, and normalising names on insert is out of scope.
6. Not run against MySQL locally (no credentials on this machine). In sqlite, the collation behaviour comes from the test-only `NOCASE` stand-in.
