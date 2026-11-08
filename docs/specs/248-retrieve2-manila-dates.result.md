# Spec 248 result: Retrieve 2 reads created_at as Manila time (no 8-hour shift)

Status: done on branch `fix/248-retrieve2-manila-dates` (from `96980ba1`). Code head: `fc029602`. This result is committed on top of it.

## Plan

1. Copy the spec to `docs/specs/248-retrieve2-manila-dates.md` (`1f392cc6`).
2. Run the full suite at the base with the shim bootstrap.
3. Tests first (`0367c24c`): re-pin the spec 247 tests that encoded the UTC reading, and add S2 and S3 cases on both the page and the export. The sqlite `CONVERT_TZ` stand-in is kept as it is. It really shifts by 8 hours, so any conversion left in the query would show up as a wrong day or time.
4. Fix (`fc029602`), in `PancakeRetrieve2Controller::retrieveQuery()` only:
   - the bounds are the Manila from-date at `00:00:00` and the day after the to-date at `00:00:00`, with no `->utc()`;
   - `date_created` is `pc.created_at`;
   - the shop-mode join is `DATE(pc.created_at)`.
   The comment states the reason: created_at is stored as Manila wall-clock time.
5. Run `tests/Feature/PancakePsid/` and the full suite at head.

## Evidence

Tests in `tests/Feature/PancakePsid/PancakeRetrieve2SpeedTest.php`. Each case checks the page and the export.
- S1: `test_the_manila_day_boundary_decides_the_range_from_the_stored_created_at`. Rows at `2026-10-19 23:59:59` (out), `2026-10-20 00:00:00` (in), `2026-10-20 17:00:00` (in; the old query put it on the 21st), `2026-10-20 23:59:59` (in) and `2026-10-21 00:00:00` (out of the 20th, in for 20th to 21st and for the 21st alone).
- S2: `test_date_created_shows_the_stored_created_at_on_the_page_and_in_the_export`. `08:15:00` and `17:00:00` show unchanged in the export's `date_created` and in the page rows.
- S3: `test_the_shop_details_come_from_orders_of_the_stored_created_at_date`. A 17:00 conversation on the 20th gets the 20th's shop details, not the 21st's. This is checked for the single day and for the 20th-to-21st range.
- S4 / query shape: `test_the_query_filters_the_bare_created_at_and_probes_macro_output_by_page_and_name`. The bounds are bound as `2026-10-20 00:00:00` and `2026-10-21 00:00:00` on the bare `pc.created_at`. The SQL has no `CONVERT_TZ` and has `DATE(pc.created_at)`. The PSID, page and name probe assertions are unchanged.

With the old controller, 5 of the 15 tests in the file failed: the three above, the query-shape test, and `test_the_shop_details_of_the_day_and_page_still_come_with_the_row`. At head:
- `tests/Feature/PancakePsid/`: OK, 37 tests, 188 assertions.

Full suite (shim bootstrap):

| Commit | Tests | Passed | Failed | Skipped | Deprecations |
|---|---|---|---|---|---|
| `96980ba1` (base) | 4805 | 4797 | 2 | 6 | 99 |
| `fc029602` (head) | 4807 | 4799 | 2 | 6 | 99 |

The 2 failures at both commits are the known ones: `ExampleTest::test_the_application_returns_a_successful_response` and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`.

## Story changes (spec 247 tests re-pinned)

- `chat()` fixture: the default `created_at` was `2026-10-20 02:00:00`, read as UTC (10:00 Manila). It is now `2026-10-20 10:00:00`, read as Manila. The parameter `$createdAtUtc` is renamed `$createdAt`. Because of this, `test_the_shop_details_of_the_day_and_page_still_come_with_the_row` keeps its assertion `date_created === '2026-10-20 10:00:00'` unchanged.
- The day-boundary test is renamed from `..._from_the_utc_created_at` to `..._from_the_stored_created_at`.
  - Old rows (UTC): `2026-10-19 15:59:59`, `2026-10-19 16:00:00`, `2026-10-20 15:59:00`, `2026-10-20 16:00:00`. They were expected as `['At Start', 'Last Minute']` for the 20th and with `'Next Day'` added for the 20th to 21st.
  - New rows (Manila): the five rows listed under S1. They are expected as `['At Start', 'Last Second', 'Late Afternoon']` for the 20th, with `'Next Day'` added for the 20th to 21st, and `['Next Day']` alone for the 21st.
- The query-shape test:
  - `assertContains('2026-10-19 16:00:00')` became `assertContains('2026-10-20 00:00:00')`.
  - `assertContains('2026-10-20 16:00:00')` became `assertContains('2026-10-21 00:00:00')`.
  - The two old values are now `assertNotContains`.
  - The two `assertStringNotContainsString("DATE(CONVERT_TZ(...)) >= ?" / "<= ?")` became one `assertStringNotContainsString('CONVERT_TZ')` on the whole statement, plus `assertStringContainsString('DATE(pc.created_at)')`.
- The `CONVERT_TZ` stand-in is unchanged. Only its comment changed.
- `PancakeReadersPsidTest` is unchanged. Its identity `CONVERT_TZ` stand-in and its `10:00` row on the 20th give the same result either way.

## New SQL

`toSql()` of `retrieveQuery('2026-11-07', '2026-11-07', '', '')`, from the sqlite test connection. On MySQL the identifiers are quoted with backticks and the sqlite-only `COLLATE NOCASE` is absent.

```sql
select
            pc.created_at as date_created,
            COALESCE(pi.pancake_page_name, pc.pancake_page_id) as page,
            sm.shop_details as shop_details,
            pc.full_name,
            pc.psid as psid,
            pc.customers_chat
         from "pancake_conversations" as "pc" left join "pancake_id" as "pi" on "pi"."pancake_page_id" = "pc"."pancake_page_id" left join (select sc.ts_date, sc.page_key, MIN(sc.shop_details) as shop_details from (select
                ts_date,
                LOWER(TRIM(`PAGE`)) as page_key,
                TRIM(`SHOP DETAILS`) as shop_details,
                COUNT(*) as cnt
             from "macro_output" where "ts_date" is not null and TRIM(ts_date) <> '' and "ts_date" between ? and ? and "PAGE" is not null and TRIM(`PAGE`) <> '' and `SHOP DETAILS` is not null and TRIM(`SHOP DETAILS`) <> '' group by ts_date, LOWER(TRIM(`PAGE`)), TRIM(`SHOP DETAILS`)) as "sc" inner join (select ts_date, page_key, MAX(cnt) as max_cnt from (select
                ts_date,
                LOWER(TRIM(`PAGE`)) as page_key,
                TRIM(`SHOP DETAILS`) as shop_details,
                COUNT(*) as cnt
             from "macro_output" where "ts_date" is not null and TRIM(ts_date) <> '' and "ts_date" between ? and ? and "PAGE" is not null and TRIM(`PAGE`) <> '' and `SHOP DETAILS` is not null and TRIM(`SHOP DETAILS`) <> '' group by ts_date, LOWER(TRIM(`PAGE`)), TRIM(`SHOP DETAILS`)) as "scm" group by ts_date, page_key) as "mx" on "mx"."ts_date" = "sc"."ts_date" and "mx"."page_key" = "sc"."page_key" and "mx"."max_cnt" = "sc"."cnt" group by sc.ts_date, sc.page_key) as "sm" on "sm"."ts_date" = DATE(pc.created_at) and "sm"."page_key" = LOWER(TRIM(COALESCE(pi.pancake_page_name, pc.pancake_page_id))) where ((
            (pc.psid IS NULL AND (NOT EXISTS (
                SELECT 1 FROM macro_output mo
                WHERE mo.`PAGE` = TRIM(COALESCE(pi.pancake_page_name, pc.pancake_page_id)) COLLATE NOCASE
                  AND mo.`fb_name` COLLATE NOCASE IN (TRIM(pc.full_name), CONCAT(' ', TRIM(pc.full_name)))
                  AND TRIM(mo.`PAGE`) <> ''
                  AND TRIM(mo.`fb_name`) <> ''
            )))
            OR (pc.psid IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM macro_output mo
                WHERE mo.botcake_psid = pc.psid
                  AND (pi.pancake_page_name IS NULL OR LOWER(TRIM(mo.`PAGE`)) = LOWER(TRIM(pi.pancake_page_name)))
            ))
        )) and "pc"."created_at" >= ? and "pc"."created_at" < ? order by "pc"."created_at" desc
```

Bindings: `["2026-11-07","2026-11-07","2026-11-07","2026-11-07","2026-11-07 00:00:00","2026-11-08 00:00:00"]`. The range is still compared on the bare `pc.created_at`, so the spec 247 `created_at` index stays usable.

## Files changed

- `app/Http/Controllers/PancakeRetrieve2Controller.php`
- `tests/Feature/PancakePsid/PancakeRetrieve2SpeedTest.php`
- `docs/specs/248-retrieve2-manila-dates.md` (the spec, unchanged)
- `docs/specs/248-retrieve2-manila-dates.result.md` (this file)

There is no migration, no `.env` change and no server change.

## Head commit

The code head is `fc029602`. This result file is committed on top of it; the pushed branch head is the commit that adds this file.
