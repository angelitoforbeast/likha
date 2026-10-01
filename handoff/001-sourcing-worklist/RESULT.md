# Result 001: Sourcing worklists on the item page

Status: **done, waiting for Mira's review of branch `feat/sourcing-worklist`.** `gh` isn't installed, so there is no PR. This file stands in for the PR body. Nothing was pushed or merged; `main` and `develop` are untouched.

## Summary

- **Worklist endpoint and chips.** On `/item`, the CEO view has new chips: **Lahat** (today's view, unchanged), **Hanapan ng supplier**, **May quote, hindi pa na-order**, **I-order na**, **Naka-order, hinihintay**. Each list chip shows a count. They come from the new `GET /item/worklist`, which is CEO-only in the data layer. The chosen chip is kept in `?list=`.
- **Worklist rows.**
  - One row per base item, with its variants and HOLD units, sorted by HOLD desc then name.
  - Columns: item photo plus quote photo thumbnails, PO suppliers and quotes with price and "dati ₱X (date)", open PO (supplier, date, ordered/received/waiting, days vs lead time) or the shortfall.
  - The existing "+ supplier quote", Change and Copy actions.
- **Classification.** A pure class, `App\Services\SourcingClassifier`, decides the list, first match wins.
- **Quotes.**
  - Old price/MOQ/link values go to the new `item_supplier_quote_history` table, on change and on delete.
  - The quote display shows "dati ₱X (date)", in the worklist and on the Lahat quote line.
  - Quotes can carry a photo (jpg/jpeg/png/webp ≤10 MB, checked by content, generated name, `supplier-quote-images/`). A save without a photo keeps the existing one.
- **HOLD.** `/item/data` and the worklist share `HoldService::liveHoldQuery`. It excludes STATUS `CANNOT PROCEED` and `ODZ` (normalised for case, spaces and underscores) and filters on the indexed `ts_date` instead of `STR_TO_DATE`. The daily snapshot query (`unitsByBaseItem`) is unchanged.
- **`latest.dump`** is untracked and in `.gitignore`. The local file is kept and history wasn't rewritten.

Mira's answers applied:
- cancelled = `cannotproceed` + `odz`
- open PO = `ordered` + `delivered`
- `ordered_qty` as entered
- "dati" on the Lahat line too
- `ts_date` used (production gap count 0)

## Done-when evidence

| Done-when | Evidence |
|---|---|
| Suite green apart from pre-existing failures; new tests cover the three seams | **Before** (develop, 2026-10-01): `Tests: 1 failed, 3 skipped, 78 passed (983 assertions)`. **After** (`666be19`): `Tests: 1 failed, 3 skipped, 95 passed (1094 assertions)`. The one failure in both runs is pre-existing: `Tests\Feature\ExampleTest > the application returns a successful response` (`Expected response status code [200] but received 302` — `/` redirects to login). Seams: (1) `tests/Unit/SourcingClassifierTest.php`; (2) `tests/Feature/Item/WorklistTest.php`, `QuoteHistoryTest.php`, `QuotePhotoTest.php`; (3) `tests/Feature/Item/HoldDataTest.php` + `tests/Unit/HoldServiceGroupingTest.php`. Plus `ItemPageTest.php` (CEO-only markup). |
| `php -l` on every changed PHP file; `npm run build` | `No syntax errors detected` on all 15 changed PHP files (app, migrations, routes, tests). `npm run build` → `✓ built in 2.41s`. |
| `/item/worklist` returns the four lists; classifier tests encode each rule and the first-match order | `WorklistTest`: 3 tests. Five items land in the four lists with counts `{hanapan:2, may_quote:1, i_order:1, naka_order:1}`; open PO = ordered+delivered; two open orders summed, oldest wins; free (cost 0) line counts as a PO line but gives no price; discount line ignored; `N x` PO names matched; Marketing / Marketing - OIC get `{ok:true, counts:{}, items:[]}`, other roles 404; impossible dates fall back to the default range. `SourcingClassifierTest`: 8-case table with both boundaries and "quote + no PO line → may_quote before i_order". |
| Quote price change and delete write history; "dati ₱X (date)"; photo upload works and rejects non-images | `QuoteHistoryTest` (2 tests): one row per price/MOQ/link change, null→0 counts, "100" vs "100.00" and note-only write nothing, delete writes one, mismatched delete writes nothing, `prev_price`/`prev_date` returned. `QuotePhotoTest` (5 tests): jpg stored with a generated name; a JSON save without a photo keeps it; a replaced photo is deleted; a deleted quote's photo is deleted; the item photo and another quote's photo are untouched; `photo_path` from the request is ignored; text, a PHP file named `.jpg`, SVG and an 10 MB+ file → 422 with nothing stored; Marketing → 403. |
| `/item/data` excludes approved statuses and filters on `ts_date`; characterisation test first | `5448257 test: characterise item data HOLD rule` pinned today's rule (with `CANNOT PROCEED` still counted) and passed against the old code. Red run after changing it: `hold excludes cancelled statuses only — Failed asserting that two arrays are identical`, and `date range filters on order date inclusive — no such function: STR_TO_DATE` (the old MySQL-only filter). Green in `17dd3c0`. |
| `latest.dump` untracked and in `.gitignore` | `0a58dbb`: `latest.dump | Bin 87496318 -> 0 bytes`; `git ls-files latest.dump` → empty; `.gitignore` ends with `latest.dump`; the local file is still there (87,496,318 bytes). |
| skeptic-reviewer ran on the diff | Spec review (before the go) plus one review per task: T2, T3+T4, T5, T7 at standard depth (sonnet), T6 at adversarial depth (opus). See "Review findings". |
| All commits on the branch; `git status` clean; RESULT filled in | 16 commits `a23a73e..` on `feat/sourcing-worklist`; `git status` clean after the last commit. |

Kit hook tests (`node --test 'test/hooks/*.test.mjs'`): `tests 1075, pass 1026, fail 5, skipped 44`, the same before and after. The 5 failures are the known symlink `EPERM` on Windows (`guard-files.qa.test.mjs`). Not touched.

**Red runs per slice** (test → first failure line):
- T2 characterisation: written against the old code, green by design. Then `HoldDataTest::hold excludes cancelled statuses only → Failed asserting that two arrays are identical`, and `HoldServiceGroupingTest → Call to undefined method App\Services\HoldService::groupUnitsByBaseItem()`.
- T3: `SourcingClassifierTest → Class "App\Services\SourcingClassifier" not found`.
- T4: `WorklistTest → Expected response status code [200] but received 404`.
- T5: `QuoteHistoryTest → 2 failed (0 assertions)`, because the history migration and table didn't exist yet.
- T6: `QuotePhotoTest::stores the photo… → Failed asserting that null is not identical to null`, and `rejects bad uploads → Expected response status code [422] but received 200`.
  - Two T6 tests were green from the start; they pin guards the code already enforced: the 403 for non-CEO, and `photo_path` ignored.
  - With `UploadedFile::fake()`, the "PHP named .jpg" case passed validation, because the fake reports the type from the file name. The tests now use real temp files so Laravel's content check (finfo) runs.
- Final wave: `WorklistTest::impossible dates… → Failed asserting that an array contains 'OLD ITEM'`.
  - Its first version passed for the wrong reason, so I changed the inputs and it went red.
  - The other cases added in that wave pin behaviour that was already correct, so they were green on their first run: `ts_date` NULL row, two open orders, free line, worklist `photo_url`, jpg, file isolation.
- T7 (Blade): no PHP test seam for Alpine behaviour. `ItemPageTest` was written after the markup; it is a check, not a red-first test.

## Files changed

- `app/Services/HoldService.php`: `CANCELLED_STATUSES`, `liveHoldQuery()`, `groupUnitsByBaseItem()` (extracted from `unitsByBaseItem`, adds `variants`).
- `app/Services/SourcingClassifier.php` (new).
- `app/Http/Controllers/ItemController.php`:
  - `data()` uses `liveHoldQuery`
  - `parseRange()` returns `Y-m-d`
  - new `worklist()` and `OPEN_PO_STATUSES`
  - `quoteSave()`: history, photo, transaction
  - `quoteDelete()`: history, photo cleanup
  - new `quoteChanged()` and `writeQuoteHistory()`
  - `quoteRows()`: `prev_price`, `prev_date`, `photo_url`
  - the four HOLD constants that became unused were removed
- `app/Models/ItemSupplierQuote.php`: `photo_path` fillable.
- `routes/web.php`: one route, `GET /item/worklist` (`item.worklist`).
- `database/migrations/2026_10_01_100000_create_item_supplier_quote_history_table.php` (new).
- `database/migrations/2026_10_01_100100_add_photo_path_to_item_supplier_quotes.php` (new).
- `resources/views/item/index.blade.php`:
  - chips bar and worklist table (CEO view only)
  - the main table hides only when a list chip is chosen
  - "dati", the quote thumbnail and `safeLink` on the Lahat quote line
  - photo input in both quote forms; `saveQuote` sends FormData
  - worklist load, error and stale-response handling
- `tests/Feature/Item/` (new): `ItemTestCase`, `HoldDataTest`, `WorklistTest`, `QuoteHistoryTest`, `QuotePhotoTest`, `ItemPageTest`. `tests/Unit/` (new): `SourcingClassifierTest`, `HoldServiceGroupingTest`.
- `.gitignore` (+`latest.dump`); `latest.dump` removed from the index.
- Docs: `docs/specs/001-sourcing-worklist.md`, `docs/plans/001-sourcing-worklist.md`, `TODO.md` (new), `handoff/README.md`, this file. Reviewer memory: `.claude/agent-memory/skeptic-reviewer/`.

## Migrations

1. **`2026_10_01_100000_create_item_supplier_quote_history_table`** creates `item_supplier_quote_history`:
   - id, quote_id (nullable, no foreign key), item_key(190), item_name, supplier_id, price decimal(10,2) nullable, moq unsigned nullable, link(500) nullable
   - action(10) `update`|`delete`, quoted_at (the old quote's `updated_at`), updated_by (who made the change), timestamps
   - index (item_key, supplier_id)
   - Guarded: `if (Schema::hasTable(...)) return;`.
2. **`2026_10_01_100100_add_photo_path_to_item_supplier_quotes`** adds a nullable string `photo_path` after `note`. Guarded: it returns early when the table is missing or the column already exists. `down()` is guarded the same way.

Both use the schema builder only (no raw SQL). Both ran in the test suite on sqlite in memory. Neither has run against any real database.

## Rulings

- Ruling: did the developer work in the main session, not through the `backend-developer`/`frontend-developer` agents — they exist in `.claude/agents/` but weren't loaded as agent types in this session — cost if wrong: none to the code; every slice still went red → green with a reviewer per tier.
- Ruling: T6 (quote photo upload) raised to high tier (opus adversarial review), approved by Mira — a new upload field is a new way in for untrusted files (CLAUDE.md) — cost if wrong: one extra review.
- Ruling: the snapshot query (`HoldService::unitsByBaseItem`) keeps the old HOLD rule — changing it would alter `item_hold_snapshots` history used by Supply Finance, a ripple outside the handoff — cost if wrong: the snapshot still counts cancelled orders and uses `STR_TO_DATE` (suggestion below).
- Ruling: HOLD side and PO/quote side join on the existing key (`HoldService` grouping key, with a test that it equals `ItemSupplierQuote::keyFor`; PO names go through `keyFor`, which also strips `N x`) — no fifth copy — cost if wrong: an item name made only of a quantity prefix ("3x") keys differently; not realistic.
- Ruling: PO line = `ordered_qty > 0 AND unit_cost >= 0`, for both "has a PO line" and open qty. The shown supplier price keeps `/item/suppliers`' `unit_cost > 0` — discount lines (negative) aren't purchases, free goods are — cost if wrong: an item with only free lines counts as having a supplier.
- Ruling: `open_po` sums qty over all open orders and shows supplier/date/days of the oldest — the one waiting longest is the one to chase — cost if wrong: Busing sees the oldest supplier, not the latest.
- Ruling: `quoted_at` / "dati (date)" = the old quote's last save time (a note-only save also moves it) — matches the approved spec; no extra tracking column — cost if wrong: the date can be later than when that price was first quoted.
- Ruling: the quote photo isn't a history field, and a replaced photo file is deleted — the handoff lists price/MOQ/link only, and it mirrors the item photo behaviour — cost if wrong: an old product photo can't be recovered.
- Ruling: "Lahat" chip has no count — "each with its count" read as the four lists; Lahat is today's view, which has its own item total — cost if wrong: one number to add.
- Ruling: the quote thumbnail sits next to the item photo in the worklist, but on the quote line in the Lahat view — keeps the default view's item cell unchanged ("the current default view stays as it is") — cost if wrong: a small markup move.
- Ruling: `safeLink` (http/https only) also applied to the existing Lahat quote link — a reviewer minor on an untrusted field (CEO-typed link rendered as `href`) — cost if wrong: a non-http link stops being clickable.
- Ruling: worklist dates must be real calendar dates (`checkdate`), otherwise the default range applies (first day of last month → today, Manila), same defaults as `/item/photo` — cost if wrong: none.
- Ruling: browser check skipped — running the app locally reads the local `.env` database, which may be real (handoff §7) — cost if wrong: Alpine runtime issues are found by Busing instead of before review.
- Conflict note: the handoff says "Weight: two migrations" and those are exactly the two. No CLAUDE.md/handoff conflicts beyond the T6 tier raise above.

## Review findings

Reviewer: `skeptic-reviewer`. Spec review before the go, then once per task. Majors start a fix loop. No review found a blocker or a major, so no fix loop ran. Minors on untrusted paths were fixed in one final wave (`ff06e2d`, `3c63353`). The rest are accepted in `TODO.md` with reasons.

### Spec

- **Spec review (before the go), "needs revision", 2 majors, both fixed in the spec before the plan went to Mira:**
  - the open-status question was missing → now question 2, answered by Mira
  - a save without a photo could erase the photo → the spec says it keeps it; tested in `QuotePhotoTest`
- Its minors were also folded in before the go: summed open-PO numbers, null-vs-0 compare, FormData nulls, `safeLink`, the T7 check method, the `/item/photo` ripple.
- T2: no findings. T3+T4: no deviations (rule 3 written as "has a PO line": same behaviour). T5: no findings. T7: no findings.
- T6: minor, "the spec says jpg, the test uses PNG" → **fixed** (jpg case added).

### Correctness

No blocker or major in any review. Minors and what I did:

- T2: no test for a row with NULL `ts_date` → **fixed** (row added to the range test). A malformed `date_range` means no filter, and `/item/photo` has no empty guard → **accepted** (TODO: pre-existing, page-built input).
- T3+T4:
  - impossible dates such as `2026-13-45` → **fixed** (`checkdate`, plus a test).
  - missing tests for multiple open orders, free line, worklist `photo_url` → **added**.
  - all PO lines loaded into PHP; partial-receipt, fallback-path, lead-time and empty-HOLD tests → **accepted** (TODO).
- T5: the existing `quoteSave` clears absent fields (now also logged), and missing history edge tests → **accepted** (TODO: pre-existing, CEO-only, can't lose data).
- T6 (adversarial):
  - **No bypass found:** mime/extension spoofing, SVG, path traversal, a chosen `photo_path`, deleting the wrong file, a double submit, CSRF and oversize all failed.
  - Missing isolation test → **added** (item photo + another quote's photo untouched).
  - Transaction-failure cleanup untested, photo dropped silently if the migration hasn't run, URL helper repeated four times → **accepted** (TODO).
- T7:
  - the Lahat quote link wasn't through `safeLink` → **fixed**.
  - a failed worklist fetch looked like an empty list → **fixed** (error message on the chips bar and in the table).
  - an older worklist response could overwrite a newer one → **fixed** (request counter).
  - quote rows depend on the `/item/quotes` fetch, and there is no JS harness → **accepted** (TODO).

### Declined to judge (reviewers) and what I did

- **Behaviour on MySQL and pgsql** (the pgsql quoting branch, `lockForUpdate` under concurrency): tests are sqlite only. Recorded in TODO. Mira's production checks covered `ts_date`.
- **Whether production has STATUS free-text cancel values beyond CANNOT PROCEED/ODZ, and J&T-cancel waybills:** Mira answered from production (all 13,265 held rows in the last 30 days are PROCEED / J&T NEW). Nothing to do.
- **Server upload limits** (`upload_max_filesize`/`post_max_size` vs 10 MB) and directory listing on `/storage/supplier-quote-images/`: a deploy note below.
- **Red-run evidence and full diffs:** reviewers couldn't run `git show` (Bash denied to them) and read the files instead. The red lines are listed under "Done-when evidence" above.
- **Visual layout / live Alpine behaviour:** no browser check (see Rulings).

## How Mira can preview it locally without touching a real database

**Not the page, plainly.** Running the app (`php artisan serve` / Herd) uses the local `.env` database, which may be real. A fresh sqlite database won't work either: the repo's older migrations include MySQL-only SQL (for example the `ts_date` trigger migration), so `migrate` fails on sqlite.

What she can do safely:
- `"/c/Users/Forbeast/.config/herd/bin/php.bat" artisan test --filter=Item` runs the new `/item` tests against sqlite in memory (15 tests). Add `--filter=SourcingClassifierTest` and `--filter=HoldServiceGroupingTest` for the two unit tests.
- The fixtures in `tests/Feature/Item/WorklistTest.php` show the five example rows and their expected lists.
- With a **throwaway local MySQL database**, she could run the app with env overrides (`DB_DATABASE=likha_preview` etc. set in the shell, which win over `.env`) and `php artisan migrate`. That touches only the throwaway database. I didn't try this, because the handoff forbids `migrate`.

## Deploy notes for Mira

1. **Pre-check already done:** the `ts_date` gap count on production = 0 (Mira, 2026-10-01).
2. **Migrations** (by hand): `php artisan migrate --force`. It runs exactly the two new migrations (`2026_10_01_100000`, `2026_10_01_100100`). Both are guarded and additive; no data changes.
3. **Storage:** quote photos go to `storage/app/public/supplier-quote-images/`. That folder sits on the same public disk as `item-images/`, so the existing `public/storage` link covers it and no new `storage:link` is needed. Laravel creates the folder on the first upload, so the web user needs write access to `storage/app/public` (already needed for item photos).
4. **Uploads:** confirm PHP `upload_max_filesize` and `post_max_size` allow 10 MB, and that the web server doesn't list directories under `/storage/`.
5. **Assets:** `npm run build` if production builds on deploy. The changes are inline Blade/Alpine and the Vite bundle hashes are unchanged.
6. **Caches:** `php artisan route:clear` (or `route:cache`) for the new route, and `php artisan view:clear` for the Blade change. No config change.
7. **Behaviour change to expect:** HOLD on `/item` and `/item/photo` no longer counts `CANNOT PROCEED`/`ODZ` rows. Per Mira's production check there are none today, so the totals stay the same.

## Suggestions for Busing

- **Daily HOLD snapshot** (`hold:snapshot` / Supply Finance history) still uses the old rule (counts cancelled, `STR_TO_DATE`). Moving it to `liveHoldQuery` would make the history match `/item`. It's a separate decision because it changes past-vs-future comparisons.
- **One shared base-item key helper.** The rule exists in four places, and `SupplyFinanceController::itemKey` doesn't strip `N x`, so a PO typed "2 x foo" is stored under a different key than its quotes. The worklist works around it with `keyFor`.
- **Stock on hand vs HOLD:** counted stock is never compared with HOLD. An item with plenty of counted stock but no open PO shows as "I-order na" while it still has HOLD. A stock-aware list would need stock-out data.
- **`quoteSave` clears fields a page doesn't send** (note today). Only updating fields present in the request would make the two pages safer.
- `/jnt/hold` (`JntHoldController`) still counts cancelled rows and uses `STR_TO_DATE`, unlike `/item` now.
- `JntHoldDownloadController` counts `PROCEED` only. The HOLD pages now disagree slightly on blank-STATUS rows.
