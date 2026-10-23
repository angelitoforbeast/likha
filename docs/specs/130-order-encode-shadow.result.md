# Result: spec 130, Likha encodes and checks the chatbot's orders itself, first in shadow

Status: done, not pushed. Branch `feat/130-order-encode-shadow` (cut from `7a7ef4b2`). Shadow works end to end against faked OpenAI and faked Google; live mode is built and off. Nothing was run against a real sheet or a real OpenAI key.

## What shipped

| Task | Tier | What |
|---|---|---|
| 1 Port of the two scripts | medium | `App\Services\OrderEncode\*`: one service, step by step. `Parse` (EXTRACTED DETAILS, item/price), `Macro` (P1 build, FILTER, points, remarks), `Locations` (the checker's LocationData maps), `Checker` (phone, R, S, T/U/V, the GPT chains, W, name/address finalize), `Gpt` (prompts and system texts word for word, Responses API then chat fallback), `Encoder` (the order of both scripts), `Text` (the small functions: normalisers, fuzzy match, list checks), `Input`, `Steps` |
| 2 Storage, job, flow hook | medium | `order_encodes` table, `EncodeOrder` job (queue `encode`), `FlowHook` (called from `FlowEngine::sheet`), `Runner`, `LiveOrder`, `Settings`, `LocationStore`, page-settings key validation |
| 3 Commands and schedule | medium | `order-encode:sync-locations`, `order-encode:compare`, `Comparer`, `Sheets` (read-only Google reads through the chatbot's existing client) |
| 4 Compare page | medium | `GET /order-encode` (`OrderEncodeController`, `resources/views/order-encode/index.blade.php`) |
| 5 Docs, deploy reference, stories | low | `docs/order-encode.md`, `deploy/supervisor/likha-encode.conf`, `qa/stories.md` S-130, `TODO.md` section 130 |

## Files

New: `app/Services/OrderEncode/{Checker,Comparer,Encoder,FlowHook,Gpt,Input,LiveOrder,LocationStore,Locations,Macro,Parse,Runner,Settings,Sheets,Steps,Text}.php`, `app/Jobs/EncodeOrder.php`, `app/Models/OrderEncode.php`, `app/Console/Commands/{OrderEncodeCompare,OrderEncodeSyncLocations}.php`, `app/Http/Controllers/OrderEncodeController.php`, `resources/views/order-encode/index.blade.php`, `config/order_encode.php`, `database/migrations/2026_11_04_100000_create_order_encodes_table.php`, `deploy/supervisor/likha-encode.conf`, `docs/order-encode.md`, `tests/Feature/OrderEncode/*` (13 files).

Changed (small, additive): `app/Services/Chatbot/FlowEngine.php` (+15 lines in `sheet()`), `app/Services/Chatbot/BotcakeOutbox.php` (the append result now also carries `range`), `app/Services/PageSettings.php` (+7: the `order_encode` key rule), `config/services.php` (+2: `openai.order_encode_key`), `.env.example` (+2: `ORDER_ENCODE_OPENAI_KEY=`), `routes/web.php` (+3), `routes/console.php` (+10), `qa/stories.md`, `TODO.md`.

Not touched (checked with `git diff --stat 7a7ef4b2..HEAD`): Astra*, MacroChecker, NightAstra*, Checker1*, `jnt_address.txt`, `ImportLikhaFromGoogleSheet`, `ImportMacroFromGoogleSheet`.

## Migration

`database/migrations/2026_11_04_100000_create_order_encodes_table.php`: one new table `order_encodes`, additive, guarded with `Schema::hasTable`. Unique `(spreadsheet_id, tab, row_number)` (row_number is NULL for live orders, which have no sheet row), index `(page, created_at)`, index `compare_state`. The owner approves it at release; run by hand with `php artisan migrate --force`. Until it runs, the compare command does nothing and `/order-encode` is a 404, and a shadow/live hook that cannot create its row leaves the flow as it was.

## Settings

- `page_settings`, provider `order_encode` (only these three keys are accepted; `mode` must be off/shadow/live, the two sheets a Google Sheets link or ID): `mode` (default off), `compare_sheet` (AFTER MACRO), `macro_sheet`.
- `app_settings`: `order_encode_model_extract` (default `gpt-4o-mini`), `order_encode_model_checker` (default `gpt-5.2`).
- Key: `config('services.openai.order_encode_key')` = env `ORDER_ENCODE_OPENAI_KEY`, else `OPENAI_API_KEY`. Not logged, not stored (the stored raw answers and errors are redacted, exact key and any `sk-…`).
- Prices for the cost estimate: `config/order_encode.php`.
- The provider `order_encode` is **not** hidden from the Page settings grid: it adds three columns, and that grid is the only place where the owner can flip a page (Page | Key | Value).

## Queue and Supervisor

Queue `encode` on the `database` connection, job `EncodeOrder` (timeout 600, tries 2, backoff 60). A deploy must add the Supervisor program in `deploy/supervisor/likha-encode.conf`:
`php /var/www/likha/artisan queue:work database --queue=encode --sleep=3 --tries=2 --timeout=600 --max-time=3600`, 2 processes, `stopwaitsecs=620`. No other worker may take the queue. Then `php artisan config:clear`.

## Scheduled commands

- `order-encode:sync-locations`: daily 04:10 Asia/Manila, also by hand.
- `order-encode:compare`: every 10 minutes (`withoutOverlapping(30)`).
Both do nothing when no page is in `shadow`/`live` (sync) or no row is due (compare).

## Input mapping

The Sheet step writes the cells in the order of its `columns` setting. Order encode only takes the Sheet step whose tab is **All Orders** (any other Sheet step of the flow is left alone). The job maps the cells to the script's ENCODER B..I cells: by the header names of the tab when at least 3 are recognised (`Date/Timestamp`, `Page`, `Name/FB Name`, `Phone/Phone Number`, `All User Input`, `Shop Details`, `Extracted Details/CXD`, `Price/COD`), otherwise by column order **A date · B page · C name · D phone · E all user input · F shop details · G extracted details · H price** (I = status, not used). The leading apostrophe the step puts on variable cells is removed (Google eats it). The `steps` log has an `input` step listing the fields that came out blank. The row number comes from the append result range (`'All Orders'!A23823:I23823` gives 23823); without a range the order is not encoded. DATABASE K/L/M are built as `storeAndTransferEncoderValues` builds them (`FB NAME: …\nPHONE NUMBER: …\n<input>\n<shop>`, shop details, extracted details).

## Script quirks reproduced (with the one-line fix)

1. **R reads the old column offsets** (page = H = the item, price = I, qty = J = the page name, but the checker expects the quantity there), so with a `page:` line in the extracted details the quantity never matches (R ≤ 3 points); with no `page:` line R is ✅ at once. Fix: read page from J, quantity from the `qty x item` in H, price from I.
2. **R score 5 is labelled "Quantity and Price"** while every other label names what is missing (it should be "Price"). Fix: relabel case 5.
3. **`Macro()` reads the "points now" cell from column 24 (X), commented as V**; X is blank on a fresh row, so the `pointsNow === 7` shortcut never fires. Fix: read the real points cell or drop the shortcut.
4. **Points are written to X then overwritten by the ✅/❌ icon; price lives in V.** Harmless today, same cause as 3.
5. **No match = `#N/A` flows into DATABASE E/F/G** (`R1/S1/T1` are `#N/A`), so the checker then treats "#N/A" as the province/city/barangay. Fix: write blanks.
6. **GPT-filled fields (step 5) are not validated or cleaned**: the phone is not checked for 09…, the province/city/barangay are not uppercased or stripped of "city/brgy" words, and an empty or "N/A" answer is written over the cell. Fix: run the same cleaning as the parsed values.
7. **The address-line (step 3) HTTP error becomes an empty address silently** (no log in the sheet). Reproduced; the step log records `EMPTY_RESPONSE` with the raw body.
8. **A filled N (any encode remark, including ❌ or MULTIPLE SUGGESTED ADDRESS) forces S = "Full Address"**, so every order with a remark skips the fuzzy classification and spends the 3-call chain. Fix: only force it for the remarks that mean a real address problem, or classify anyway.
9. **The city check strips the province name from the city** (`normalizeCityForCheck`): "Cebu City" in province Cebu is checked as just "cebu", which the province word alone satisfies. Fix: match the whole city name.
10. **The fuzzy match compares equal-length windows only**, so insertions and deletions in a misspelling are not tolerated, and a name under 3 characters (e.g. barangay "5") never matches. Fix: a proper approximate substring match, exact match for short names.
11. **W "Province" is written and then replaced by ✅ one second later**, so a verified province mismatch counts as ✅. Fix: leave "Province".
12. **S = "Province" has no GPT chain** (only the W verify runs). Fix: add a province chain or leave as is on purpose.
13. **The early `return` paths of the chains skip the W verify** (city not in the list, barangay list empty…), leaving W blank. Fix: run the verify on every path.
14. **The name/address strip leaves stray commas** when several places are removed ("Blk 5, Lahug, Cebu City" becomes "Blk 5, ,"). Fix: collapse repeated commas.
15. **DATABASE phone becomes a number in the sheet** (leading 0 dropped), and the phone check expects 10 digits; it only colours the cell, nothing is stored. Reproduced as `phone_ok` in the outputs. Fix: store the phone as text and check 11 digits.
16. **`temperature: 0` is sent to both APIs for every model**; a model that refuses it makes every checker call fail (the script then reports `ERR_…` per row). Copied as is. Fix: omit temperature for models that do not take it.
17. **Step 5 asks the GPT only with name + phone + all user input** (not the shop details), one call per blank field, written even when empty.

## Not reproduced exactly (and why)

- Sheet mechanics dropped as the spec says: the K1 pointer and "check column" marking, the even-row stepping, locks, sleeps, time limits, the three parallel copies, the re-run branches of T/U/V (an order is processed once), background colours (only the phone flag is kept as `phone_ok`).
- **Regex whitespace:** JS `\s` also matches NBSP and other Unicode spaces; the PHP regexes without `/u` match ASCII whitespace (the JS-style `trim()` is unicode-aware).
- **FILTER `SEARCH` wildcards** (`?`, `*`, `~`) in the query are not emulated; a word is a plain case-insensitive substring. Only a value with those characters differs.
- **A city or barangay inserted into a `RegExp` is escaped** (`preg_quote`) where the script does not escape it (it would throw or treat `(` as a group); only GPT-filled values can contain such characters.
- **Sheets number coercion** is emulated for numbers only (`0917…` becomes `917…`, `1,299.00` becomes `1299`), not for dates or booleans.
- **`localeCompare("en")`** (ICU) is approximated by an ASCII-folded, case-insensitive compare, which only changes the order of the allowed-list text in a prompt.
- **A GPT connection error in steps 3 and 5** is logged and the row goes on; in the script it aborts the whole row (nothing written). A non-JSON error body is not an exception here.
- **`candidates` is 0 on no match** where the sheet's `COUNTA` would count the `#N/A` as 1 (cannot change the result).
- **Live mode:** the `#N/A` values of quirk 5 go into `macro_output` as the imports would bring them.
- **The location lists:** I could not read the real sheets, so I cannot say whether the macro sheet's LocationData and AFTER MACRO's are identical. `order-encode:sync-locations` prints "identical" or "NOT identical" on its first real run: please look at that line.
- **Real-world formats not verifiable offline:** the exact layout of the real All Orders tab (the compare relies on the header names, else A..H), whether DATABASE column A is text or a date (the row check accepts both), and the real `gpt-5.2` behaviour with `temperature: 0`.

## Estimated GPT calls per order

A clean order (the address check passes, name and address filled): **1** (address line 1). Each blank field of name/phone/address/province/city/barangay adds 1 (up to 6). An address that needs fixing adds 1 to 3 pick-from-list calls plus 1 verify (so about 3 to 5 for a typical fix, 7 to 12 at the worst with every field blank). A name/address finalize call is added only when the name or the address is still blank. If the Responses API call fails and the chat fallback runs, each checker call costs 2 HTTP requests (counted in `requests`). Typical expectation: 1 to 3 calls per order; the page shows the measured calls and cost per order.

## Cases

All S-130 cases are in `qa/stories.md`. Final run of `tests/Feature/OrderEncode`: **162 tests, 363 assertions, all pass.** Cases S-130.1 to .59 map one-to-one to the tests named `test_S_130_<n>_…` in `tests/Feature/OrderEncode/` (the port cases .40 to .51 in `TextAndParseTest`, `MacroTest`, `CheckerTest`).

| Cases | Test file | Result |
|---|---|---|
| S-130.1–.6, .52, .53 (flow hook, live fallback, tab scope) | `OrderEncodeFlowTest` | pass |
| S-130.7, .8 (config) | `ConfigTest` | pass |
| S-130.9–.18, .54, .55, .58, .59 (job, shadow/live, key, errors) | `EncodeOrderTest` | pass |
| S-130.19–.28, .56, .57 (compare) | `CompareTest` | pass |
| S-130.29–.32 (sync, schedule) | `SyncLocationsTest` | pass |
| S-130.33–.38 (page) | `ComparePageTest` | pass |
| S-130.40–.44 (parsing, normalisers, fuzzy, input) | `TextAndParseTest` | pass |
| S-130.45 (macro) | `MacroTest` | pass |
| S-130.46–.51 (checker) | `CheckerTest` | pass |

Required suites, one run on the final tree: `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake tests/Feature/NightRun tests/Feature/OrderEncode` gave 1,432 tests, **1 error**: `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`, "file …storage\app/credentials.json does not exist" (the known local-only failure, a missing Google credentials file; unrelated to this spec). Everything else passed.

## Red runs (first failure line per slice)

The port (`Text`, `Parse`, `Macro`, `Checker`, `Encoder`, `Gpt`) and `Runner`/`LiveOrder`/`EncodeOrder` were written from the two scripts before their tests (the scripts are the specification), so there is no red run for them: their tests passed on the first full run after I had checked each expected value by hand against the script (five of my first expectations were wrong and were corrected from the script, not the code). The later slices were test-first:

- Flow hook: `OrderEncodeFlowTest::test_S_130_1…` "The expected [App\Jobs\EncodeOrder] job was pushed 0 times instead of 1 times."
- Config keys: `ConfigTest::test_S_130_7…` "Failed asserting that null is not null."
- Compare and sync: `CompareTest::test_S_130_19…` `The command "order-encode:compare" does not exist.`
- Page: `ComparePageTest::test_S_130_33…` "Expected response status code [200] but received 404."
- Review fixes: S-130.52–.58, all 11 new test cases failed before the fix (e.g. S-130.54 "the job must fail so that it is retried" was not reached because the row was already `done`; S-130.53 "Failed asserting that 0 is identical to 1").

## Review

`skeptic-reviewer`, standard depth, on `7a7ef4b2..HEAD`: no blockers, 3 majors, all on the live path (off by default), 9 minors. Fix loop 1 (commit `aafdab44`, test first):

- Major: the hook acted on any Sheet node of a live/shadow page. Fixed: only the tab "All Orders".
- Major: the job was marked `done` before the live `macro_output` write, so a failed write was never retried. Fixed: `done` is set last.
- Major: a live page that was not ready, or whose encode ended `failed`, lost the order (no sheet row, no macro row). Fixed: not ready, or any failure, writes the row to the sheet as before. Left in `TODO.md` with the reason: if the `encode` worker is not running at all, nothing re-drives a live order; no page is live in this spec, and the Supervisor program is a go-live prerequisite.
- Minors fixed: `Schema::hasTable` guards (command and page), a formula date (`=TODAY()`) no longer causes `row_mismatch`, a step that lists blank input fields, a dead condition removed. The other minors are accepted in `TODO.md` section 130 with reasons.

Re-check of the fix (same reviewer, scoped): all three majors confirmed fixed, no new blocker or major, 2 minors, both on the live path after a partial failure (a retry could insert a second `macro_output` row, and a last failure could also write the sheet although the macro row existed). Fixed test first (S-130.59): the retry reuses `macro_output_id`, and `fail()` marks the order done without writing the sheet when that row exists. Accepted with the reviewer's note: the tab check does not look at the spreadsheet id, so another spreadsheet with a tab called "All Orders" on a live/shadow page would also be encoded (matches the spec wording).

## Story changes

The spec brought no stories; I drafted S-130 myself from the spec's "Done when" list (the reviewer was not reachable from this session to confirm them). No existing story was changed.

## Process note

This session had no way to ask the reviewer for the plan "go"; the decisions table of the spec was taken as the agreed design.

## Merge danger

- The migration must be run by hand before the first shadow order is useful (`order_encodes`); without it the hook swallows the error and the flow is unchanged.
- `FlowEngine::sheet` is on the hot path of every order; the added code runs only for tab "All Orders" on a page with mode `shadow`/`live` and is wrapped so it cannot fail the flow. A page with no `order_encode` setting costs one cached page-settings lookup.
- `BotcakeOutbox::appendRow` now returns an extra `range` key; nothing else reads it.
- `PageSettings::validate` now refuses unknown keys for provider `order_encode` only.
- Deploy order: migrate, Supervisor program, `config:clear`, set `compare_sheet` and `macro_sheet`, run `order-encode:sync-locations`, then `mode = shadow` on one page.

## Commits

```
e06ec775 docs: spec 130 order encode shadow with the reference scripts
979fc6e5 feat: order encode service ported from the two sheet scripts, with unit tests (spec 130)
746d9ef8 feat: order encode job, shadow and live hook in the sheet step, config keys and order_encodes table (spec 130)
6658f657 feat: order-encode:compare and order-encode:sync-locations commands with schedule (spec 130)
4d770c18 feat: /order-encode compare page, read-only, with counts, cost and side-by-side values (spec 130)
3ab19386 docs: order encode guide, supervisor reference, stories for spec 130
aafdab44 fix: order encode only takes the All Orders step, live falls back to the sheet when not ready or failed, done only after the macro row exists (spec 130)
```
(plus the commit that adds this result.)
