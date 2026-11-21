# 311 · Result

## Plan
1. Base: full suite on a466e1bd before any code change (count below under Evidence).
2. One placeholder rule for addresses: `AddressLandmark::isPlaceholderAddress()`, next to the landmark rule it extends. It
   covers the landmark placeholders (N/A, none, wala pong nabanggit, "-"...), every text listed in the spec (n/a, "no
   address line 1 or landmark provided.", "no address line provided.", "no address line found.", "---", "not provided",
   "[not provided]", "[missing]", "(Wala pong namention)") and their close variants. A value in ( ) or [ ] is a placeholder
   only when its inside is one or says no/none/wala/missing/not, so "(Purok 7)" and "(P7) Pido St." stay real.
   `clean()` (the landmark rule) is not changed.
3. New `App\Services\OrderEncode\FormAddress` (one file, easy to cherry-pick): reads the form's House Number, Purok/Sitio,
   Address, Brgy, City and Province lines (last line of each label, optional "- ", like `Parse`), names the shape, and
   composes ADDRESS with `AddressLandmark::compose`: placeholder parts dropped, the form's own barangay, city and province
   taken off the ends of each part, the Purok/Sitio part dropped when another part already names the same purok (Purok 5 =
   Prk. 5 = Pk 5 = P-5 = Purok V), cap 250. `keepPurok()` adds a real Purok/Sitio to an AI line that lacks it.
4. `Encoder`:
   - STEP 3: a parts form (it has a House Number or Purok/Sitio line) with at least one real part → ADDRESS composed in code,
     no AI call (`address_line1` = `FORM_PARTS`). Otherwise the AI step as today, and a placeholder answer becomes empty.
   - STEP 5 (fill_missing): a placeholder answer for Address Line 1 becomes empty.
   - After the checker: a placeholder in D becomes empty; on the AI path a real Purok/Sitio missing from D is added.
   - Landmark: form case uses `AddressLandmark::compose(parts, landmark)` (Astra's way); AI case as today; "no_address" uses
     the placeholder rule instead of only "unknown".
5. `Checker::finalizeNameAddr`: a placeholder ADDRESS is not filled (so it rebuilds from the chat), and a placeholder answer
   is never written.
6. Tests first for every H/N/E case (FormAddress unit, placeholder unit, encoder runs with a fake AI, checker with a fake AI),
   then the code, then the full suite against the base, then this file, then push.

## What shipped

Commits on `feat/311-purok-in-address` (base a466e1bd): 828e862d (spec + plan), 73820374 (tests, red: 87 errors, 41 failures
of 144), c71e97f8 (code), then this result.

| Where | Change |
|---|---|
| `App\Services\OrderEncode\FormAddress` (new) | Reads the form's House Number, Purok/Sitio, Address, Brgy, City and Province lines and names the shape (table below). `compose()` builds ADDRESS from the real parts with `AddressLandmark::compose` (Astra's composer): placeholder parts dropped, the form's own barangay/city/province taken off the end of each part, the Purok/Sitio part dropped when another part already names the same purok, cap 250. `purok()` gives the form's real Purok/Sitio. `keepPurok()` puts it in front of an AI line that lacks it. `hasPurok()` is the purok match. |
| `App\Services\AddressLandmark::isPlaceholderAddress()` (new) | The one placeholder rule for addresses (list below). `clean()`, `apply()` and `compose()` are unchanged, so the landmark rule and Astra's composition do not move. |
| `OrderEncode\Encoder` | STEP 3, STEP 5, after the checker, the landmark step (PA-3 list below). |
| `OrderEncode\Checker::finalizeNameAddr` | `addrFilled` is "not a placeholder" (was: not empty and not "unknown"); an answer is written only when it is not a placeholder (was: not empty and not "unknown"). |

### Form shapes and how each is read
Labels are read like `Parse`: one label per line, optional "- " in front, any case, the last line of a label wins.

| Shape | How it is recognised | How ADDRESS is made |
|---|---|---|
| **parts** (Astra-style block, or the flow's form with the same labels) | a `House Number:` (also House No / House # / House/Lot) or a `Purok/Sitio:` (also Purok / Sitio / Purok or Sitio) line | In code, no AI call: House Number, Purok/Sitio, Address, each without placeholders and without the form's barangay/city/province at its end, through `AddressLandmark::compose`; the landmark by `compose(parts, landmark)` after the checker. Step `address_line1` = `FORM_PARTS`. |
| parts, but every part a placeholder | as above | The AI, as today. |
| **one_line** (old form) | an `Address:` (also Complete/Full Address, Address Line 1) line, no House Number or Purok/Sitio line | The AI, as today; landmark by `apply`, as today. |
| **none** | none of these lines (free text, or only Brgy/City/Province) | The AI, as today. |

On the AI path a placeholder answer of STEP 3 or STEP 5 becomes empty (status `PLACEHOLDER`), so the next step tries (fill_missing, then the checker's finalize, which reads the whole chat).

### Purok match (how "Prk. 5" = "Purok 5")
Both texts are lowercased, ñ = n, punctuation becomes a space. Each purok in the form value is a number or a name taken after
`purok` / `prk` / `pk` (optional `no` / `number`), or after a bare `p` only with a number (`P7`, `P-7`, so "Phase 5" is not
purok 5). Numbers lose leading zeros and roman numerals I–XX become digits. A sitio is the one or two words after `sitio` /
`so`. The purok is "already there" when every number/name of the form value is found in the line; a value with no
purok/sitio word ("Zone 3") must appear as whole words. Matches: Prk. 5, Prk 5, Pk. 5, P-5, P5, Purok No. 5, Purok 05,
Purok V, PUROK 5, Prk. Sampaguita = Purok Sampaguita, So. Malipayon = Sitio Malipayon, (P7) = Purok 7. Not a match: Prk. 15,
Phase 5.

### Places taken off a part
Only at the end of a part, only as whole words: the form's Brgy, City (without "City") and Province, with an optional
"Brgy./Barangay/City of" in front and "City/Province" after, repeated ("Rizal St, Brgy. Lahug, Cebu City, Cebu" → "Rizal
St"; "Purok 5 Rizal St Lahug Cebu City" → "Purok 5 Rizal St"). "Rizal St" in Rizal and "Malahug" in Lahug stay. A number-only
or very short barangay ("Barangay 5") is taken off only with "Brgy."/"Barangay" in front, so "Purok 5" stays.

### Placeholder list (`isPlaceholderAddress`)
Checked lowercase, spaces collapsed, trailing `.`/`!` removed:
- Empty or only punctuation: '', `-`, `--`, `---`, `.`, `/`.
- AddressLandmark's landmark placeholders: N/A, NA, N.A., none, nothing, null, nil, no, unknown, no landmark, "none / not /
  nothing provided / mentioned / specified / given / applicable / available / stated / indicated" (optional explicitly /
  yet / specifically, optional "po"), wala / walang / wla (+ po / pong / pa / naman / talaga, + nabanggit / na-mention /
  namention / nakalagay / binigay / sinabi / info / provided…), hindi / di (pa / po) + nabanggit / naibigay / na-mention…
- New for addresses: "no / none / without / wala / walang + (complete / exact / specific / full) + address / addr / street /
  house number / purok / sitio + (line / details / info) + (1) + (or / and / & / "/" + landmark / street / purok / address)
  + (was / is) + (provided / found / mentioned / specified / given / available / stated / indicated / detected /
  extracted / nabanggit…)" — covers "no address line 1 or landmark provided.", "no address line provided.", "no address line
  found.", "No address", "walang address", "No house number or street"; "address / address line 1 / street / purok
  (:) (is / was) (not) provided / found / … / missing / unknown / none / n/a" — covers "Address not provided", "Address:
  N/A"; "missing", "not found", "not available", "not applicable", "to follow", "tbd", "tba".
- A whole value in ( ) or [ ]: a placeholder only when its inside is one, or says not / no (not "No. 5") / none / wala /
  walang / hindi / missing / unknown / n/a / didn't. So "[not provided]", "[missing]", "(Wala pong namention)", "[customer did
  not give one]", "(none)", "[N/A]" are placeholders, and "(Purok 7)", "[Blk 5 Lot 3]", "(P7) Pido St." are real. (The
  landmark rule still treats any bracketed value as no landmark; that rule is not changed.)

### PA-3: every place the encode writes ADDRESS
The only path from the encode to `macro_output.ADDRESS` is `Encoder::run` → `outputs['address_line']` (= DATABASE D), read by
`LiveOrder::values`/`encodeRow` and the backtest command. Inside `Encoder::run` D is written in these places, all covered:
1. STEP 3 `address_line1` (cell L → D): parts form → composed in code; AI answer → placeholder becomes empty.
2. STEP 5 `fill_missing` for Address Line 1 (L): placeholder answer becomes empty.
3. `Checker::finalizeNameAddr` (D): placeholder D is not filled; placeholder answer is not written.
4. After the checker: a placeholder left in D is cleared (step `address_placeholder` = `CLEARED`; a guard, the steps above
   already stop every placeholder); on the AI path the form's real Purok/Sitio is added when D lacks it (step `purok`).
5. The landmark step (D): parts form → `FormAddress::compose(parts, landmark)`; AI line → `apply` as today; "no_address"
   now uses the placeholder rule.
Not changed: Astra (`AstraEncoder`, `AstraAddressRules::composeAddress`) and `MacroChecker` (the older AI checker engine run on
existing rows; it is not the encode — see Open).

## Evidence per done-when item

1. **A test for every H, N and E case passes.** 3 new files, 23 test methods, 144 tests with data sets, 258 assertions, all OK
   (`vendor/bin/phpunit` on the three files). Red before the code (73820374): 87 errors, 41 failures.

   | Case | Test |
   |---|---|
   | PA-1 H 12 / Purok 5 / Rizal St / malapit sa chapel → "12, Purok 5, Rizal St, near chapel", no AI call for the address | `PurokInAddressTest::test_S_311_PA1_H_the_form_parts_are_composed_in_code_without_the_ai` (asserts ADDRESS, outputs, no `Extract Address Line 1` request, `FORM_PARTS`, `landmark_added`, `SKIP:ALREADY_FILLED`, proceed); `FormAddressTest::test_S_311_PA1_H_the_parts_are_composed_with_the_landmark` (same as `AddressLandmark::compose` of the parts) |
   | PA-1 H "Prk. 5" in the line and Purok/Sitio "Purok 5" → once | `FormAddressTest::test_S_311_PA1_H_a_purok_already_in_the_line_appears_once` (14 spellings, `keepPurok` = kept), `..._a_different_or_missing_purok_is_added_in_front` (4), `PurokInAddressTest::test_S_311_PA1_H_a_purok_already_in_the_address_part_appears_once` (full encode: "12, Prk. 5, Rizal St") |
   | PA-1 N placeholder Purok/Sitio ("N/A", "-", "(Wala pong na-mention)", "none") → nothing added | `PurokInAddressTest::test_S_311_PA1_N_a_placeholder_purok_adds_nothing` (4, full encode → "12, Rizal St", no purok step); `FormAddressTest::test_S_311_PA1_N_a_placeholder_purok_adds_nothing` (9); `..._all_parts_placeholders_go_to_the_ai_as_today` |
   | PA-1 E form without a Purok/Sitio line → same as today | `PurokInAddressTest::test_S_311_PA1_E_a_form_without_a_purok_line_is_the_same_as_today` (one-line Address, with landmark, no address line: today's values "Blk 5 Lot 3" / "Blk 5 Lot 3, near chapel", one AI call, `WROTE_ADDRESS_LINE1`); `FormAddressTest::test_S_311_PA1_E_...` (shape one_line / none); every existing OrderEncode test (their forms are the one-line shape) still passes unchanged |
   | PA-1 no duplicates, no barangay/city/province, cap 250 | `FormAddressTest::test_S_311_PA1_the_form_places_are_taken_off_and_the_cap_holds`, `..._no_duplicate_parts` |
   | PA-2 one rule covering the spec's list and the landmark placeholders | `FormAddressTest::test_S_311_PA2_every_listed_placeholder_is_not_an_address` (44 values), `..._the_landmark_rule_is_unchanged` |
   | PA-2 the encoder never writes a placeholder; it writes empty | `PurokInAddressTest::test_S_311_PA2_the_encoder_never_writes_a_placeholder_into_address` (10: STEP 3, fill_missing and the checker all answer the placeholder → ADDRESS '') |
   | PA-2 H ADDRESS "n/a" with a purok and a street in the chat → the checker writes the real line (fake AI) | `PlaceholderAddressCheckerTest::test_S_311_PA2_H_a_placeholder_address_is_rebuilt_from_the_chat` (11 placeholders in D → "Purok 3, Mabini St", `WROTE_NAMEADDR`, 1 request); `PurokInAddressTest::test_S_311_PA2_H_a_placeholder_from_the_ai_lets_the_checker_rebuild_the_address_from_the_chat` (full encode, STEP 3 says "n/a" → `PLACEHOLDER` → checker writes it); `..._a_placeholder_answer_is_never_written` |
   | PA-2 N "(P7) Pido St." is not a placeholder | `FormAddressTest::test_S_311_PA2_N_a_real_address_is_not_a_placeholder` (14 real values), `PurokInAddressTest::test_S_311_PA2_N_a_real_address_starting_with_a_bracket_is_kept` |
   | PA-2 N real ADDRESS + filled name → SKIP:ALREADY_FILLED, no extra AI call | `PlaceholderAddressCheckerTest::test_S_311_PA2_N_a_real_address_and_a_name_skip_with_no_ai_call` (3 addresses, 0 requests); existing `CheckerTest::test_S_130_47_a_row_that_matches_the_chat_is_a_check_and_needs_no_gpt` |
   | PA-3 every write place | `PurokInAddressTest::test_S_311_PA3_fill_missing_still_fills_an_empty_address_line` + the placeholder tests above (STEP 3, fill_missing, checker) + the landmark cases in the table |

   Folders: `tests/Feature/OrderEncode` + `tests/Feature/NightRun` (Astra block) on the tip: 1002 tests, 7634 assertions, OK.

   **Full suite vs base** (Herd PHP 8.4, `-d memory_limit=4G`, `vendor/bin/phpunit`)

   | Run | Tests | Errors | Failures | Skipped |
   |---|---|---|---|---|
   | Base a466e1bd | 5985 | 0 | 3 | 15 |
   | Tip c71e97f8 | 6129 | 0 | 3 | 15 |

   - +144 tests are the three new files (23 methods with their data sets). No new failures.
   - The 3 failures are the same on both runs, all known: `ExampleTest::test_the_application_returns_a_successful_response`,
     `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`,
     `SharedPagesBasePinTest::test_S_42_4_the_shared_owner_pages_render_byte_for_byte_as_at_the_base`. The base ran before any
     code change in this worktree (only docs were committed during it).

2. **Before/after table** (`PurokInAddressTest::test_S_311_before_and_after`, 18 invented forms; Bungad, Quezon City, Metro
   Manila). "AI says" is the fake STEP 3 answer, written the way the live AI drops the purok; "Before" is what today's encode
   writes for it (the AI line plus the landmark by `apply`); "After" is asserted by the test.

   | # | Form (shape) | AI says | Before | After |
   |---|---|---|---|---|
   | 1 | Astra block, no "- ": House 12, Purok/Sitio Purok 5, Address Rizal St, Landmark malapit sa chapel | Rizal St | Rizal St, near chapel | 12, Purok 5, Rizal St, near chapel |
   | 2 | parts: 12 / Purok 5 / Rizal St | 12 Rizal St | 12 Rizal St | 12, Purok 5, Rizal St |
   | 3 | parts: - / Purok 3 / - | (Wala pong namention) | (Wala pong namention) | Purok 3 |
   | 4 | parts: N/A / Sitio Malipayon / Mabini St | Mabini St | Mabini St | Sitio Malipayon, Mabini St |
   | 5 | parts: 12 / Purok 5 / Prk. 5, Rizal St | Prk. 5, Rizal St | Prk. 5, Rizal St | 12, Prk. 5, Rizal St |
   | 6 | parts: - / Purok 2 / Narra St, Brgy. Bungad, Quezon City | Narra St | Narra St | Purok 2, Narra St |
   | 7 | parts: 12 / N/A / Rizal St | 12 Rizal St | 12 Rizal St | 12, Rizal St |
   | 8 | parts: 12 / (Wala pong na-mention) / Rizal St, Landmark tapat ng plaza | 12 Rizal St | 12 Rizal St, near plaza | 12, Rizal St, near plaza |
   | 9 | parts: 8543 / - / 8543 OB Junction | 8543 OB Junction | 8543 OB Junction | 8543 OB Junction |
   | 10 | parts: - / Purok 5 / Purok V, Rizal St | Rizal St | Rizal St | Purok V, Rizal St |
   | 11 | one_line: Address Blk 5 Lot 3 Bungad QC | Blk 5 Lot 3 | Blk 5 Lot 3 | Blk 5 Lot 3 |
   | 12 | one_line: Address Blk 5 Lot 3, Landmark near Jollibee | Blk 5 Lot 3 | Blk 5 Lot 3, near Jollibee | Blk 5 Lot 3, near Jollibee |
   | 13 | one_line: Address Bungad QC | n/a | n/a | (empty) |
   | 14 | one_line: Address Bungad QC | no address line provided. | no address line provided. | (empty) |
   | 15 | none: free text only | Blk 9 | Blk 9 | Blk 9 |
   | 16 | parts, all placeholders: - / - / N/A | [missing] | [missing] | (empty) |
   | 17 | one_line: Address (P7) Pido St. | (P7) Pido St. | (P7) Pido St. | (P7) Pido St. |
   | 18 | parts: Blk 2 Lot 4 / Purok Sampaguita / Camia St, Landmark Brgy. Hall | Blk 2 Lot 4 Camia St | Blk 2 Lot 4 Camia St, near Brgy. Hall | Blk 2 Lot 4, Purok Sampaguita, Camia St, near Brgy. Hall |

   In rows 13, 14 and 16 the empty ADDRESS comes after fill_missing and the checker's finalize also found nothing (the fake
   answers empty there); with a real chat the checker now gets its turn (PA-2 H). Rows 9, 11, 12, 15 and 17 are the paths
   without a purok: unchanged.
3. **Pushed:** `feat/311-purok-in-address` (see the report for the head). No merge, no deploy.

## Story changes
None. PA-1, PA-2 and PA-3 shipped as written.

## Rulings
- **Which forms are "parts".** A form is composed in code when it has a House Number or Purok/Sitio line and at least one
  real value among House Number, Purok/Sitio and Address. A form with only an `Address:` line is the old one-line form and
  stays with the AI, because that value often holds the barangay, city and province run together. A parts form whose Address
  part holds them is cleaned in code (only at the end of the part, only the form's own places, whole words).
- **The AI-path purok add is a guard.** With that routing a real Purok/Sitio line always makes the form "parts", so the AI
  line is never used together with a real form purok in today's shapes. The add (`FormAddress::keepPurok`, step `purok`) is
  still applied on the AI path as the spec asks, so a later shape that sends a purok form to the AI keeps it. It is tested at
  the unit level (`keepPurok` / `hasPurok`, 18 cases); no encode test reaches it.
- **Duplicates follow Astra's composer.** `AddressLandmark::compose` drops a part found inside another part, so House Number
  "5" with Purok/Sitio "Purok 5" gives "Purok 5" (Astra does the same today). The purok match adds the spelling cases on top
  ("Purok 5" vs "Prk. 5").
- **Landmark in the parts case** is `compose(parts, landmark)` (Astra's way), with the status from `apply` (added / kept /
  no_room). The two differ only when a part lies inside the landmark (Astra then drops the part).
- **Placeholder in brackets.** The landmark rule treats any bracketed value as a note. For an address that would turn
  "(Purok 7)" into empty, so the address rule looks inside the brackets (see the list).
- **A placeholder from STEP 3 now leads to one fill_missing call** for Address Line 1 (as an empty answer always did), then
  the checker's finalize. That is up to two more AI calls on those rows only; rows with a real address or a parts form make
  the same or fewer calls (a parts form skips the STEP 3 call).
- **Pint** is not enforced: the changed files already fail `pint --test` at the base (one-line `if` style), so the new code
  keeps the files' own style.

## Merge danger
- **Files a cherry-pick touches (both sites):** `app/Services/AddressLandmark.php` (one new method and two constants, nothing
  existing changed), `app/Services/OrderEncode/FormAddress.php` (new), `app/Services/OrderEncode/Encoder.php`,
  `app/Services/OrderEncode/Checker.php` (two lines in `finalizeNameAddr`, one `use`), and the tests
  `tests/Feature/OrderEncode/FormAddressTest.php`, `PurokInAddressTest.php`, `PlaceholderAddressCheckerTest.php` (new; they
  use `LiveRowTestCase`, `Fixtures` and `Steps::requests()`, which must exist on Incepxion's line), plus the two docs.
  No migration, no config, no route.
- **Incepxion:** cherry-pick 73820374 and c71e97f8 (and the docs). Conflicts can come only if Incepxion's `Encoder.php`
  STEP 3 / fill_missing / landmark blocks or `AddressLandmark.php` differ from Likha's (spec 187 must be there: `AddressLandmark`,
  `Parse::landmark`, `Encoder::landmark`). Run the three new test files and `tests/Feature/OrderEncode` there.
- **Effect when live (both sites):** every new order whose extracted details have a House Number or Purok/Sitio line gets its
  ADDRESS from the form (and J&T label from that ADDRESS); placeholder ADDRESS values become empty and are then filled by the
  checker or left empty. Existing rows are not touched; a re-encode of an open row writes the new value unless staff edited
  ADDRESS (the edited_* and HISTORICAL LOGS guards are unchanged), and an empty result never overwrites a filled ADDRESS
  (`encodeRow` keeps the old value).
- **Revert:** `git revert c71e97f8` (code only; the tests then fail, so revert 73820374 too) on each site's line. No data to
  undo: rows written in between keep their composed address, which is a valid address line.

## Open
- `MacroChecker` (the older AI checker engine) still treats only "" and "unknown" as no address when it fills ADDRESS on
  existing rows. It is not the encode, so it is left as is; it could use `isPlaceholderAddress` in a later spec.
- The real shape of the flow's extracted details is not in the repo (it comes from the page's AI node). The labels read are
  the Astra block's labels with common variants; if live forms use another label for the purok (e.g. "Zone:"), it is the
  one-line or none shape and stays with the AI.
