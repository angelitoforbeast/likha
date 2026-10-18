# Result 058: the item row's RTS / DEL / INT cell finds its J&T stats within ₱1

## What shipped

One slice, medium risk, backend.

- `app/Http/Controllers/OwnerPrivateController.php`, `itemSummary()`, at the J&T lookup (lines 2367-2379). The exact `$jntStatsMap[page||item||price]` read now sums the buckets at price − 1, price and price + 1 of the same page and canonical item (total, RTS and DEL). The rest is unchanged: transit is still the remainder (total − RTS − DEL), and the percentages come from the sums. When there is no bucket, the cell is still empty.
- That lookup is the only place in `itemSummary()` that reads `$jntStatsMap`. All of the cell's and tooltip's `jnt_*` fields come from it.
- The `$jntStatsMap` near line 4892 belongs to another endpoint, with its own cache. It does not feed this cell, so it was left alone.
- The 60-day window and the campaign/expand numbers are unchanged.
- Expected live value for the reported row (2026-09-18 to 2026-10-17, end-date price ₱298, shipments in the ₱299 bucket): RTS 52.7% (405), DEL 30.9% (237), INT 16.4% (126). The test S-058.1 checks this with the same counts.

## Case table

| Case | Test | Result |
|---|---|---|
| S-058.1 | `ItemJntStatsToleranceTest::test_S_058_1_an_end_date_price_one_peso_off_gets_the_bucket_stats` | Pass |
| S-058.2 | `ItemJntStatsToleranceTest::test_S_058_2_buckets_on_both_sides_within_one_peso_are_summed` | Pass |
| S-058.3 | `ItemJntStatsToleranceTest::test_S_058_3_a_bucket_two_pesos_away_is_not_included` | Pass |
| S-058.4 | `ItemJntStatsToleranceTest::test_S_058_4_an_exact_bucket_alone_works_as_before` | Pass |

Red run, before the fix:

- S-058.1 failed with "Failed asserting that two arrays are equal" (every `jnt_*` value was null).
- S-058.2 failed because only the ₱298 bucket was read: RTS 2 of total 6 instead of 6 of 20.
- S-058.3 and S-058.4 passed, because they pin behaviour that does not change.

Full suite (`php artisan test`): 160 tests ran.

- 158 passed (counting tests that also gave a warning).
- 2 failed, both known and unrelated: `Tests\Feature\ExampleTest` and `Tests\Feature\NightRun\ImportStartTest` ("macro job final write applies only while the run is still active").
- 1,503 warnings. Each is a `file_get_contents` warning: the worktree has no local env file, and the existing item-summary tests show the same warning. No warning is an assertion failure. Plain `vendor/bin/phpunit` on the new file: OK (4 tests, 8 assertions).

## Review

`skeptic-reviewer`, standard depth: no blockers, no majors, no minors.

- It confirmed that dropping the transit sum is correct, because transit is recomputed as the remainder.
- It confirmed that no other exact-price read feeds the cell or its tooltip.
- It confirmed that the expected values were computed by hand.

## Story changes

Added section S-058 to `qa/stories.md` with the spec's four cases. S-058.2 also includes a ₱297 bucket, to show that both sides of the ±1 range are summed.

## Conflicts and notes

- The saved spec replaces the two customer/page names with "Page A" and "Page B". The project rules say specs never name people; that rule wins over saving the brief verbatim.
- The worktree had no `vendor/`. A link to the main checkout's `vendor/` made Composer load the main checkout's `app/`, so `vendor/` was copied into the worktree instead. The lock file is identical, and `vendor/` is git-ignored.

## Suggestions for the owner (not done)

- The other endpoint's `lookupRtsPct` (near line 4931) also matches the J&T price exactly. It may want the same ±1 rule.
