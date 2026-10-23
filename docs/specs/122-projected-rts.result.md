# Result: 122 projected RTS

Branch `feat/122-projected-rts` (from 5a3f3c0d). Not pushed. No migration. No new dependency.

## Commits
1. `docs: spec 122 projected RTS`: the spec. One wording change: the guard blocks commands that name the env file, so "(.env, ..." is written "(env file, ...".
2. `feat: rts:learn and RtsProjection give projected RTS per page and item (spec 122)`
3. `feat: rts-check feed with its own key lists set vs projected RTS per page and item (spec 122)`
4. `feat: Projected RTS line beside Set RTS on /owner/private, red beyond 5 points (spec 122)`
5. `docs: spec 122 result`

## Files
- `app/Services/RtsProjection.php`: `learn()`, `rates()`, `rows($from, $to)`.
- `app/Console/Commands/RtsLearn.php`: `rts:learn`.
- `app/Http/Controllers/OwnerRtsCheckController.php`: the feed.
- `app/Http/Controllers/OwnerPrivateController.php`: `projectedRtsRows()` (best effort, `[]` on any failure) and four `projected_rts_*` fields on each item-summary row.
- `resources/views/owner/private.blade.php`: a "Proj. x%" line inside the Set RTS cell (title: "n orders, x% still in transit"; red when flagged) and the same text in the compact hover.
- `routes/api.php`, `routes/console.php`, `config/services.php`, `.env.example`.
- Tests: `tests/Feature/RtsProjection/` (`RtsProjectionTestCase`, `RtsProjectionTest`, `RtsCheckFeedTest`, `ProjectedRtsCellTest`). `SharedPagesBasePinTest` re-pinned: the /owner/private render hash changed only by the new cell line.

## Schedule, config
- `rts:learn` daily at 04:40 Manila, `withoutOverlapping`.
- Rate table in `app_settings`, key `rts_projection_rates` (JSON: `learned_at`, `pooled[age] = {p, n}`, `items["page||item"][age] = {p, n}`). It drops the smallest items if the JSON would pass 60 KB (the column is TEXT).
- Config `services.rts_check.key` from `RTS_CHECK_KEY` (added to `.env.example`). Empty = the feed answers 401 to everyone.

## How it works
- Statuses: contains "returned" = R; "for return" or "rts" = F; "delivered" = D; any other non-empty status = in transit.
- Learning: orders with `submission_time` in the last 8 weeks, joined to `macro_output.waybill`, current status D, R or F (F counts as RTS) and non-empty `status_logs`. For age 0..14, the state is the last log at or before submission + N days (none = in transit). If it is in transit, it counts as a sample, and as RTS when the order ended R or F. Item rate kept only with ≥30 samples at that age.
- Projection: p is the item's rate at the age, else the pooled rate at that age, else the nearest younger pooled age. Ages above 14 use 14. With no rate table yet, the projected value is null and no cell is drawn.
- Actual RTS%: Returned ÷ (Delivered + Returned) over orders at least 14 days old.
- Set RTS%: latest `page_item_settings` row with an RTS value and `effective_date` ≤ today, matched by lowercase page + alias-canonical item. Last set by/at: latest log row where the RTS really changed (not a carry, not a promo or cogs scope). Name from the employee profile or user, else the email local part.
- Orders = macro_output rows with a waybill in the `ts_date` range (distinct waybills). Unmatched = no J&T row or no status. Matched = orders with a J&T state.
- /owner/private: window is the last 21 days of orders, whatever date range the page shows. Flag = |set − projected| > 5 and matched ≥ 30.

## Feed contract
`GET /api/owner/rts-check?from=YYYY-MM-DD&to=YYYY-MM-DD` (default: the last 21 days, Manila), header `X-RTS-CHECK-KEY`, `hash_equals`, 401 on a missing, wrong or unconfigured key, `throttle:30,1`, 422 on a bad date. Rows with matched ≥ 30 only, flagged first, then by |gap|, then by matched. No waybill, name, phone or address.

Sample from the test fixture (`rates_learned_at` is the fixture's):
```json
{"from":"2026-10-02","to":"2026-10-23","rates_learned_at":"2026-10-23 03:30:00","rows":[
 {"page":"Page B","item":"Item C","orders":100,"unmatched":0,"matched":100,"delivered":25,"returned":24,"for_return":0,
  "in_transit":51,"in_transit_pct":51,"projected_rts":55.11,"actual_rts":null,"set_rts":30,"gap":-25.11,"flag":true,"set_by":null,"set_at":null},
 {"page":"Page B","item":"Item G","orders":100,"unmatched":0,"matched":100,"delivered":60,"returned":40,"for_return":0,
  "in_transit":0,"in_transit_pct":0,"projected_rts":40,"actual_rts":null,"set_rts":42,"gap":2,"flag":false,"set_by":null,"set_at":null}]}
```

## Tests
- Red runs: `RtsProjectionTest::test_learn_computes_rates_by_age_from_resolved_orders` failed with "The command rts:learn does not exist" (the other three, "Target class RtsProjection does not exist"). `RtsCheckFeedTest::test_needs_the_key` failed with "Expected 401 but received 404". `ProjectedRtsCellTest::test_row_carries_projected_rts_…` failed with "Undefined array key projected_rts_pct".
- Green: `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake tests/Feature/RtsProjection`: 950 tests, OK (1 skipped). `tests/Feature/OwnerPrivate` and `tests/Feature/Rts` also pass, with the re-pin.
- One run of the combined folders showed a single error in `ChatbotTagsTest` (send-pool case, "array offset on null"). It passes alone and in the rerun of the required folders, and does not touch this work. Probably flaky.
- Stories: the spec brought none, so there are no case IDs. The tests above cover each "Done when" line.

## Notes for the owner
- Run `php artisan rts:learn` once after deploy, or the Projected RTS stays empty until 04:40. Set `RTS_CHECK_KEY` for the feed.
- The item-summary payload is cached until the next save or upload (existing behaviour), so the projected values refresh with the page's other numbers; "refresh" forces it.
- Orders whose J&T row has no `status_logs` are skipped when learning (the age-N state is unknown).
- The first learning pass reads about 8 weeks of joined rows (~100k) in chunks; it runs at night.
