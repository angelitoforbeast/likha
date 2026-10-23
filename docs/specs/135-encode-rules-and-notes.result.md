# Result: spec 135, the Likha encoder gets safer rules and learns from past corrections

Status: done, not pushed. Branch `feat/135-encode-rules-and-notes` (cut from `e717c969`). Nothing was run against real Google, OpenAI or a real queue worker; nothing was deployed. The rules default to `improved` as the brief says, so the first shadow encodes after the deploy already use them; `order_encode_rules = sheet` brings back the spec-130 behaviour with no code change.

## What shipped

| Part | What |
|---|---|
| Rule switch | `app_settings.order_encode_rules` (`improved` default / `sheet`), read by `Settings::rules()`, saved by the CEO on `/order-encode/final` (new "Rules" select in the existing settings form). With `sheet` there is no `Rules` object: the encoder and checker run the old code path (outputs and steps have no new keys). |
| R1 | `Checker::cityFix` / `brgyFix`: a written City / Brgy that equals a label of the province's city list / the city's barangay list is kept and the GPT call is skipped (step `R1_WRITTEN_CITY_KEPT` / `R1_WRITTEN_BARANGAY_KEPT`, `rule: R1`; S is recomputed). |
| R2 | `Checker::verify`: with a written province that resolves to another J&T province, W stays `Province` (no ✅); `Encoder` holds `proceed` with `R2` and the reason. A written province that resolves to nothing is ignored. |
| R3 | `Macro::filter` / `run` take the written barangay and city and pick, among the matching lines, the one whose barangay matches, then city, then list order. |
| R4 | `LocationList::exactLocations()` (a second `Locations` with the barangay labels exactly as the J&T file spells them, matching unchanged); GPT answers are written as the list label; `Locations::hasCombination` guards PROCEED (`R4`, "Not a J&T address combination"). |
| R5 | `Duplicates` (called from `Runner` after the encode): same phone, or same name + barangay + city, on the same `ts_date` in `macro_output`, or in the day's encodes not yet in `macro_output`; whitelist and CANNOT PROCEED skipped; reason "Possible duplicate: <ids>". |
| Notes | table `address_notes`, `NotesBuilder` (+ commands), `Notes` (index-only lookups), `Rules` (NOTE_HIT, past corrections in the three GPT fix prompts), `NoteReview`, `NoteFilter`. |
| Review page | `GET /order-encode/notes` (CEO only) with search, filters, counts, examples, conflicts, approve / reject / edit, bulk approve; `order-encode:notes-review` for scripts. |
| Final page | rules switch, NOTE_HIT and "held by R…" counts and badges. |
| Backtest | `order-encode:backtest --source-mode=backtest2 [--limit=N]`. |
| Live | A held order in `live` mode is written to `macro_output` as APP SCRIPT CHECKER `TO FIX` (not ✅, no STATUS, reason in AI ANALYZE), otherwise the Validate button would have turned it into PROCEED. Small addition to `LiveOrder`; no flow uses live yet. |

## Files

New: `app/Models/AddressNote.php`, `app/Services/OrderEncode/{Written,Rules,Notes,NotesBuilder,NoteFilter,NoteReview,Duplicates}.php`, `app/Console/Commands/{OrderEncodeNotesBuild,OrderEncodeNotesReview,OrderEncodeBacktest}.php`, `app/Http/Controllers/OrderEncodeNotesController.php`, `resources/views/order-encode/notes.blade.php`, `database/migrations/2026_11_06_100000_create_address_notes_table.php`, `tests/Feature/OrderEncode/{EncodeRunTestCase,RulesTest,NotesBuildTest,NotesUseTest,NotesPageTest,BacktestTest}.php`.
Changed (small): `Checker`, `Encoder`, `Macro`, `Locations`, `LocationList`, `Runner`, `Settings`, `LiveOrder` (`app/Services/OrderEncode/`), `OrderEncodeFinalController`, `resources/views/order-encode/final.blade.php`, `routes/web.php` (+6), `routes/console.php` (+6), `docs/order-encode.md`, `qa/stories.md` (S-135), `TODO.md`. `MacroChecker`, Validate, Astra and the imports are untouched (`MacroChecker`'s public static `normBrgyKey` / `normCityKey` are only called).

## The migration

`2026_11_06_100000_create_address_notes_table.php`, additive, guarded by `Schema::hasTable`. Run by hand after the owner's yes: `php artisan migrate --force`. Until then the build and review commands say so and do nothing, the page gives 404, and the encoder runs without notes. Columns: the ones of the brief, plus what the brief needs to be searchable and repeatable:
- `written_brgy`, `written_city`, `written_province` (the parts of `written_key`, indexed as `(status, written_city)` and `(status, written_province)`): the "same written city or province" lookup must use an index, and the key alone cannot answer it.
- `conflicts` (JSON) next to the `conflict` bool; `ledger` (JSON, order id → answer:delivered): the build is repeated every day over overlapping days, so each order must be counted once. This is a column of the same table, not a second table.
- `unique(written_key)`; `index(status, delivered)`.
`source` holds a comma list of the sources seen (e.g. `staff_fix,delivered`), because the auto-approve rule needs "source includes a fix".

## Settings keys

`order_encode_rules` (`improved` / `sheet`; anything else = `improved`). Existing keys are unchanged.

## How the "written" key is made

From the Province / City / Brgy lines of the extracted details, read by spec 130's `Parse::addressParts` (that parser already drops the words brgy / barangay / city / province and symbols, upper-cases, Ñ = N; `Q.city` therefore reads as `Q`). `Written` then normalises each part: lowercase, Ñ = N, punctuation removed, `MacroChecker::normBrgyKey` / `normCityKey` as the base (roman numerals to digits, sta / sto / gen / pob / bgy / brgy expanded); a barangay also loses brgy / barangay / bgy / pob / poblacion (kept if nothing else is left, so "Poblacion" alone stays), so "Pob. 4", "Poblacion 4", "POBLACION BARANGAY 4" and "BARANGAY IV (POB.)" are all `4`; a city also loses city / municipality and a leading province name (`LAGUNA-SANTA-CRUZ` = `santa cruz`, `QUEZON-CITY` = `quezon`); a province uses `Text::normProv` (NCR / Metro Manila = `manila`). `written_key` = `barangay|city|province`, each cut at 80 characters, empty parts kept. If the order has no extracted details, the encode's `parse_parts` step is used.

## Auto-approve rule

A note that is pending and has never been reviewed by a person becomes `approved` (`reviewed_by = auto`) when its main answer has **delivered ≥ 2**, the note has **no conflict**, and its sources include **`staff_fix` or `astra_fix`**. Back to pending if it later gets a conflict or a different main answer. A note with `reviewed_at` (any human approve / reject / edit) is never changed by the build: status and answer stay, new answers only go to `conflicts`. Bulk approve (page and command) never approves a note with a conflict.

## Commands and schedule

- `php artisan order-encode:notes-build --days=60` (1 to 400): reads HISTORICAL LOGS lines (PROVINCE / CITY / BARANGAY, timestamp in the window; orders from 7 days before the window), `ai_checker_logs` rows `fixed` in the window, and `from_jnts` `Delivered` (by `updated_at`); safe to repeat. Scheduled daily 05:30 Manila with `--days=3` (`routes/console.php`).
- `php artisan order-encode:notes-review --approve-where="source=staff_fix,conflict=no,delivered>=2" --reviewer="Name" [--note="…"] [--dry-run]` (keys: q, status, source, conflict, delivered, seen).
- `php artisan order-encode:backtest --source-mode=backtest2 [--limit=N]`.

## Cases

All S-135 cases are in `qa/stories.md`. Case table (every test passes):

| Cases | Tests |
|---|---|
| S-135.1, .2 switch | `RulesTest` |
| S-135.3 to .7 R1 (Bungad / Project 7, San Isidro / Calawis, city kept, normalisation table) | `RulesTest` |
| S-135.8 to .10 R2 (Tanqui / La Union, junk province, held with reason) | `RulesTest` |
| S-135.11 R3 (Pasolod / Cebu City, Cebulan) | `RulesTest` |
| S-135.12 to .14 R4 (POBLACION BARANGAY 4, Burauen / Bayog and Laguna Santa Cruz / Inalmasinan, a valid combination) | `RulesTest` |
| S-135.15 to .19 R5 (three same-day orders, name + barangay + city, exclusions, encodes not yet in macro_output, sheet mode) | `RulesTest` |
| S-135.36 held live order | `RulesTest` |
| S-135.20 to .29 notes build and review command | `NotesBuildTest` |
| S-135.30 to .35 note hit, examples (≤ 5), index-only lookups | `NotesUseTest` |
| S-135.40 to .47 review page, bulk, edit only to J&T, CEO only, escaping, final page, rules form | `NotesPageTest` |
| S-135.50 to .52 backtest on a seeded set (Likha and sheet columns) | `BacktestTest` |

## Test runs

`php vendor\phpunit\phpunit\phpunit tests/Feature/OrderEncode tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake tests/Feature/NightRun`: 1,540 tests (before the last two small fixes), 2 not green, neither from this work:
- `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`: the known local-only failure (`storage/app/credentials.json` missing).
- `OrderEncode\OrderEncodeFlowTest::test_S_130_1…`: expects the row date `2026-10-23` and the clock is now 2026-10-24; it also fails on the untouched spec-133 worktree.
After the last fixes `tests/Feature/OrderEncode` was run again: 259 tests, only that date test red.

## Deviations and things to know

- **No plan "go" was waited for** (no reviewer channel in this session); the brief was treated as the plan. **Tests came after the first slice of code (R1 to R5)**, not before it; each rule test also asserts the old sheet-mode result, which is the red side (e.g. `PROJECT 7`, `CALOOCAN-CITY`, `BARANGAY POBLACION 4`, the PROCEEDed Burauen / Bayog). The later slices were also built in small steps with their tests next to them.
- **The stories were written by the developer**, because the brief brought none; they should be read by the reviewer.
- **`tmp_sheet_out`** is not defined anywhere in the repo. The backtest reads it by assumed column names (see `TODO.md` 135); if the real table differs, only `OrderEncodeBacktest::sheetOutput` changes. The seeded test creates a table with those names.
- **The default is `improved`**, also for pages in `live` mode and for the flow shadow; the owner can flip the select on `/order-encode/final` at any time.
- The first candidate (R3) uses the written parts only; a candidate chosen from GPT-filled parts (when the customer wrote nothing) keeps the old list order.
- The `address_notes` page lists notes by delivered, then seen; there is no export.
- Suggestions for the owner (not done, legacy rule): see the "135" section of `TODO.md`.

## Commits

```
ebd365c0 docs: spec 135 encode rules and notes
853dd1ce feat: the encoder keeps the written barangay and city, holds a contradicting province, an unknown J&T combination and same-day duplicates, behind a rules switch (spec 135)
b89decf1 feat: the encoder learns from corrected and delivered orders: address notes build, review command, note hit and past corrections in the GPT prompts (spec 135)
651bfacf feat: CEO review page for the address notes (search, filters, approve, reject, edit to a J&T combination, bulk approve) and NOTE_HIT and held-by rule on the final page (spec 135)
a2c32d11 feat: order-encode:backtest re-encodes stored inputs under the current rules and reports Likha and the sheet after the Validate rules (spec 135)
53d16615 fix: a held live order is written as TO FIX so Validate cannot make it PROCEED; a log line with an unreadable time no longer stops the notes build (spec 135)
a96d6e53 docs: spec 135 encode rules and notes
```
(plus the review-fix commit and this result.)

## Review

skeptic-reviewer, standard depth, on `e717c969..HEAD`: no blocker, no major. Fixed after it (one loop): the search escapes `% _` with an explicit `ESCAPE '!'` (S-135.48); a filtered bulk approve with no filter is refused (S-135.48); a backtest copy that ended without outputs counts as failed, not as a blank address; a note hit now keeps R4 but not R2, because the approved note is the correction of exactly that written province (S-135.37; it was a decision the brief did not state, say if it should be the other way).

Accepted, in `TODO.md` terms: R5's phone pre-filter matches `9xxxxxxxxx` and `09xxxxxxxxx` only (rows stored as `+63…` are missed); `Written::brgy` folds "Poblacion 4" and "Barangay 4" to the same `4` (rare, R4 still validates); two paths without their own test (the `parse_parts` fallback of the build, the delivered-later ledger upgrade beyond S-135.26); MySQL and PostgreSQL SQL was read, not run (tests are sqlite).

Final run of `tests/Feature/OrderEncode`: 261 tests, only the date test red.

## Amendment 135-1 (`docs/specs/135-encode-rules-and-notes.amendment-1.md`)

- **J&T list de-duplicated on load**, in both rule modes: `LocationList::load` keeps the first of identical `PROVINCE|CITY|BARANGAY` lines (trimmed) in its position, so the macro/FILTER list, the checker's lists, the notes build and `hasCombination` all see each address once. `jnt_address.txt` is not edited. Test S-135.60 (`RulesTest`): a list with the San Isidro (POB.) line twice gives `candidates = 1`, no remark, no GPT barangay call and PROCEED, in `sheet` and in `improved`; the loaded list has the 2 distinct lines.
- **Date-dependent test fixed:** `OrderEncodeFlowTest::test_S_130_1` froze no clock while the flow's `{{current_date}}` is today; it now sets `Carbon::setTestNow('2026-10-23 10:15:00')` and resets it. No other test in the folder depended on today's date (the whole folder passes).
- **Re-run after this change and after the two earlier commits (live `TO FIX`, review follow-ups):** `tests/Feature/OrderEncode tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake tests/Feature/NightRun`: 1,544 tests, 1 error, the known local-only `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (`storage/app/credentials.json` missing). Everything else is green, including `OrderEncodeFlowTest::test_S_130_1`.
