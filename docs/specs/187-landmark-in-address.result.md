# Result 187: the customer's landmark always reaches the order address

Status: done. Branch `fix/187-landmark-in-address`, based on e919bec5 (live). Not pushed, not deployed.

## What changed

| Where | Change |
|---|---|
| `App\Services\AddressLandmark` (new) | The one landmark rule, moved from Astra's address composer: dedupe of parts, strip of the leading "near / malapit sa / sa may / tapat ng / …" (Astra's list, unchanged), placeholders count as no landmark, `near <landmark>` appended when the cleaned landmark is not already inside the address (lowercase, collapsed spaces, both directions as Astra did), cap at 250. `compose(parts, landmark)` for Astra and `apply(address, landmark)` for the encode. |
| `AstraAddressRules::composeAddress` | Now one line: calls `AddressLandmark::compose` with house number, purok/sitio and address. |
| `OrderEncode\Parse::landmark` (new) | The last `Landmark:` line of the extracted details (CXD), raw. `addressParts` is unchanged. |
| `OrderEncode\Encoder` | After the checker (so the checker's AI prompts, its fill-if-empty address step and the place checks see the same address line as before), the landmark is applied to the address line (D). This covers both the STEP 3 AI line and a line the checker's finalize step wrote. The AI prompt is unchanged. One step is logged: `landmark` with status `landmark_added`, `landmark_kept` (already in the address), `landmark_no_room` (the address line alone is too close to the limit), `no_landmark` (none or a placeholder) or `no_address` (no address line, or "Unknown": the address is not made landmark-only, so a missing address still shows as missing). |

Staff edits: not touched. `LiveOrder::update` and `encodeRow` still skip ADDRESS when `edited_address` is set or a person's line for ADDRESS is in HISTORICAL LOGS (S-187.6). Province, city, barangay, phone, J&T upload and checker, and Validate rules are not touched.

## Placeholder list (no landmark)

Checked after the prefix strip, lowercase, spaces collapsed, trailing `.`/`!` removed:
- Only punctuation or empty: `-`, `--`, `.`, `/`, `''`.
- A whole value in `[ ]` or `( )`: `[Not provided]`, `(Wala pong na-mention)`, `[customer did not give one]`.
- English: `N/A`, `NA`, `N.A.`, `none`, `nothing`, `null`, `nil`, `no`, `unknown`, `no landmark`, and `none / not / nothing / no landmark` + optional `explicitly / yet / specifically` + `provided / mentioned / specified / given / applicable / available / stated / indicated` ("None provided", "Not explicitly provided", "Nothing mentioned"); each may end with "po".
- Tagalog: `wala / walang / wla` + optional `po / pong / pa / pang / naman / talaga` + optional `landmark / nabanggit / binanggit / na-mention / nakalagay / nilagay / binigay / naibigay / sinabi / info / impormasyon / provided / specified / …` ("Wala", "Wala po", "Wala pong nabanggit", "Walang landmark", "wala specified").
- `hindi / di` + optional `pa / po / naman` + `nabanggit / naibigay / binigay / sinabi / na-mention / nakalagay / …` ("Hindi pa naibigay", "hindi po nabanggit").
- A placeholder after a prefix ("malapit sa N/A") is also none.
- Real landmarks that start alike stay: "Hindi kalayuan sa simbahan", "Nayon chapel".

## Length limit: 250

- Astra already capped at 250.
- `Jnt\JntPayloadBuilder` sends `receiver.address` with no cap.
- `macro_output.ADDRESS` is TEXT.
- So 250 is the smaller limit and is kept.

The cap cuts at the last space or comma when the cut would land mid-word (if that keeps at least half), drops a dangling ", near", and never exceeds 250. For the encode, an AI address line is never cut to make room: if the landmark (not even its first word) does not fit, the line stays as it was and the step says `landmark_no_room`.

## Astra differences (asked for by the spec)

- Placeholders are now dropped. Before, only `-` and empty were dropped, and AstraEncoder already blanked N/A and NONE.
- The 250 cap now cuts at a word boundary instead of mid-word.

Otherwise its behaviour is the same, and its tests are unchanged and pass.

## Case table (S-187)

| Case | Test | Result |
|---|---|---|
| .1 | `LandmarkTest::test_S_187_1_the_landmark_is_added_to_the_ai_address_line` ("Pk. 17, Zone 7," + "Malapit sa Brgy. Hall" → "Pk. 17, Zone 7, near Brgy. Hall", step `landmark_added`); `test_S_187_1_the_landmark_is_added_to_an_address_line_written_by_the_checker`; `AddressLandmarkTest::test_S_187_1_…without_a_double_comma` | pass |
| .2 | `LandmarkTest::test_S_187_2_a_landmark_already_in_the_address_is_not_repeated`; `AddressLandmarkTest::test_S_187_2_…any_case_or_spacing…` | pass |
| .3 | `LandmarkTest::test_S_187_3_a_placeholder_landmark_adds_nothing` (13 values incl. N/A, [Not provided], (Wala pong na-mention), Wala pong nabanggit, -, None provided); `test_S_187_3_no_landmark_line_adds_nothing`; `test_S_187_3_no_address_line_is_not_made_into_a_landmark_only_address` (blank, Unknown); `AddressLandmarkTest::test_S_187_3_placeholders_are_no_landmark` (32 values); `test_S_187_3_real_landmarks_are_kept…` | pass |
| .4 | `AddressLandmarkTest::test_S_187_4_a_long_address_with_a_landmark_is_capped_at_a_word_and_never_over_the_limit` | pass |
| .5 | `AddressLandmarkTest::test_S_187_5_astra_composes_the_address_with_the_shared_rule` (6 forms); `tests/Feature/NightRun` unchanged, 326/326 | pass |
| .6 | `LandmarkTest::test_S_187_6_a_staff_edited_address_is_not_changed_by_a_re_encode` | pass |
| .7 | `tests/Feature/OrderEncode tests/Feature/NightRun tests/Feature/Jnt tests/Feature/Orders` | pass |

## Tests

- **Baseline** at e919bec5: 822 tests, 7311 assertions, all pass (25 PHPUnit deprecations).
- **Final** at e148d7a8: 887 tests, 7414 assertions, all pass (the same 25 deprecations).
- New: 65 tests. New failures: none.

## Review

The skeptic reviewer ran once at standard depth.
- **Blocker:** "None provided" (the most common kept value in old data), "None specified", "Not explicitly provided" and "wala specified" were not placeholders. Fixed and tested.
- **Minors fixed:**
  - A stray whitespace change in `Encoder.php` is reverted.
  - An AI line already near or over 250 is no longer cut (`landmark_no_room`).
  - The landmark is no longer cut to a dangling "near".
  - "Unknown" counts as no address.
  - Added tests for `no_address` and for a line written by the checker.
- **Minors accepted, with reasons in `TODO.md`:**
  - Bracketed real landmarks are dropped.
  - Connector words remain after the prefix strip.
  - The contains check does not ignore punctuation.
  - Staff notes typed in the Landmark line are appended.
  - The sheet compare and the backtest now differ on landmark orders.

## Deploy notes

- No migration, no config, no command, no backfill. Only new encodes get the landmark. Old orders stay as they are.
- After deploy, the encode's steps on /order-encode show the `landmark` step for each order.
- The /order-encode sheet compare and `order-encode:backtest` compare against the sheet's AI-only ADDRESS. Orders with a landmark now show there as address differences, so address match rates are not comparable with runs before this deploy. The live-row compare (`FinalComparer`) is not affected.
- Astra picks up the placeholder list and the word-boundary cap on its next run.

## Incepxion

The same code runs incepxion.pro (the same `Encoder`, `AstraAddressRules` and new `AddressLandmark`), so the change applies there on its next deploy, with no settings.

187: done — the landmark from the customer's form is added to the encode's address line by one shared rule that Astra also uses (placeholders skipped, capped at 250, staff edits kept); 887/887 tests pass.
