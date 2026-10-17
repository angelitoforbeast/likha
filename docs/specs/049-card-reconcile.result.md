# Result 049: card counter-check of Meta ad payments against the card statement

## What shipped

Commits on `feat/049-card-reconcile` (cut from `develop` at 0056d2df):

- `docs: brief for the card counter-check of Meta ad payments (spec 049)`
- `docs: plan and stories for the card counter-check (spec 049)`
- `feat: read RCBC and other card statement text into lines with card numbers masked (spec 049)`
- `feat: read a card statement screenshot with the OpenAI vision model as strict JSON lines (spec 049)`
- `feat: match card statement lines to Meta payments by amount and date, with repeated amounts tallied per day and a card suggestion (spec 049)`
- `fix: card number mask no longer eats the digits at the end of a FACEBK code or a number after the card (spec 049)`
- `feat: CEO-only card check page: upload a statement, correct the preview, match it to Meta payments, and see what tallies (spec 049)`
- `fix: mask card numbers with wide or NBSP spacing and glued prefixes, keep RCBC lines with a bad date for the preview, count undated ads charges, and turn vision timeouts and extra fields into a message (spec 049)`
- `fix: send preview edits as one JSON field so long statements are not cut by max_input_vars, validate them, and gate the card check link on the same role as the page (spec 049)`
- `fix: mask card numbers with any space between the groups, and refuse a match whose line edits did not arrive instead of dropping them (spec 049)`
- `docs: accepted review findings for spec 049 in TODO` (twice)
- `docs: result for spec 049`

### Tables (migration `2026_10_18_400000_create_card_statements_tables.php`, both guarded with `Schema::hasTable`)

- **`card_statements`:** `card_label` (a Meta `payment_method`, e.g. "Visa ···· 5003"), `bank` (`rcbc`, `generic`, `image`), `source_kind` (`pdf`, `image`), `original_name` (masked), `period_start`, `period_end`, `status` (`preview`, `matched`), `lines_count`, `ads_count`, `card_total`, `meta_total`, `matched_count`, `matched_total`, `no_meta_count`, `no_meta_total`, `no_card_count`, `no_card_total`, `difference_total`, `groups_tallied`, `groups_untallied`, `uploaded_by` (user id), `matched_at`, timestamps.
- **`card_statement_lines`:** `card_statement_id`, `line_no`, `sale_date`, `post_date`, `description` (masked, with the FACEBK code), `amount`, `is_credit`, `is_ads`, `payment_activity_id`, `group_key` (`Y-m-d|amount`), `match_kind` (`sale_date`, `post_date`, `near`, `group`, `none`, `credit`, `not_ads`), `match_days`, timestamps.
- `payment_activity_ads_manager` is only read. No ALTER, no write.

### Parsers

- **PDF:** `pdftotext -enc UTF-8 -layout` when the binary runs (config `services.card_statement.pdftotext`, env `POPPLER_PDFTOTEXT`, default `pdftotext`, the same env the J&T sticker job uses). Otherwise `smalot/pdfparser` (already installed). A PDF with no readable lines (a scan) is refused with a message to upload a screenshot.
- **Layouts** (`app/Services/CardStatement/`, interface `StatementLayout`; a new bank = a new class in `StatementParser::layouts()`):
  - **RCBC:** `MM/DD/YY MM/DD/YY DESCRIPTION AMOUNT`, a trailing `-` is a credit. Dateless amount lines (PREVIOUS STATEMENT BALANCE, totals) are listed as not ads. A line whose date doesn't parse is kept with a null date, so it shows in the preview to correct.
  - **Generic fallback:** a line with a date (`MM/DD/YY`, `MM/DD/YYYY`, `YYYY-MM-DD`, `Sep 09, 2026`, `09 Sep 2026`), a FACEBK/FACEBOOK/META PLATFORMS description and an amount (`-` or `CR` = credit). Other lines are ignored.
  - **Bank choice on upload:** Auto (RCBC when the text says RCBC, else generic), RCBC, Generic.
- **Screenshot (PNG/JPG):** OpenAI chat completions, the image as a base64 data URL (never a public URL), `response_format: json_object`, temperature 0, 90 s timeout. Key: `OPENAI_API_KEY` (`services.openai.key`, already set). Model: `services.card_statement.model`, env `OPENAI_STATEMENT_MODEL`, default `gpt-4o` (the vision model the checklist analysis already uses). The answer must be exactly `{"lines":[{sale_date, post_date, description, amount, is_credit}]}`: any other shape, an extra field, a bad or impossible date, a non-number or negative amount, a non-boolean `is_credit`, more than 500 lines, an HTTP error or a timeout rejects the whole read, and nothing is saved.
- **Ads rule (all paths):** description contains FACEBK, FACEBOOK or META PLATFORMS.
- **Masking (all paths, before anything is saved or shown):** 13–19 contiguous digits, or card-shaped groups (4-4-4-x, Amex 4-6-5) separated by up to 8 of any space (NBSP, thin space), tab, dot or dash, become `···· ` + the last 4. Applied to every description and to the file name. The file itself is never written anywhere: it is read from PHP's upload temp file.

### Matching rules as built (`CardMatcher`, per card)

- Meta records of the chosen card dated from the first sale date − 2 days to the last sale date + 2 days are candidates.
- **Groups:** a (date, amount) where the card has ≥2 ads charges on that sale date, or Meta has ≥2 records on that date. Statement count vs Meta count and both totals; tallied when the counts are equal. Those Meta records belong to the group, never to "wala sa card".
- **One-to-one by exact amount**, each pass over all lines before the next: same sale date, then same post date, then 1–2 days from the sale date (closest first; ties: earlier line, lower Meta id). An exact match is never taken by a near one.
- **Left over:** an ads charge with no record → "Wala sa Meta" (Nasa card pero wala sa Meta); a Meta record dated inside first..last sale date with no line → "Wala sa card" (Nasa Meta pero wala sa card). Credits and non-ads lines are listed, never matched. An ads charge with no date counts as "Wala sa Meta" and in the card total.
- **Per day:** card total (ads charges by sale date) vs Meta total (the card's records in the period), difference = card − Meta.
- **Card suggestion:** the payment method with the most ads charges that have a record of the same amount within ±2 days (ties: alphabetical); preselected when the upload left the card blank.

### The page

- `GET /ads_manager/payment/reconcile`: the upload form (file, card from the Meta payment methods or "hindi sigurado", bank), the list of statements (card, period, bank, lines, matched %, difference total, status), and the **Suriin** box (Meta records dated after today, Manila, newest 50, read-only).
- `POST /ads_manager/payment/reconcile`: reads and saves the statement as a preview.
- `GET /ads_manager/payment/reconcile/{id}`:
  - **Preview:** the suggested card, and every line with editable sale date, post date, amount, credit and ads flags.
  - **Matched:** chips (Tugma N · ₱X, Wala sa card N · ₱X, Wala sa Meta N · ₱X, 100-groups tallied/not, card vs Meta totals), the per-day table with the differences in red, the groups table, the "Nasa Meta pero wala sa card" list, and the line table with status, the Meta transaction id, ad account and date, and status filter buttons. The edit form stays below it ("Itugma ulit", which also picks up new Meta imports).
- `POST /ads_manager/payment/reconcile/{id}/match`: saves the edits and the match. The edits come as one JSON field, so a long statement is not cut by PHP's `max_input_vars`; only lines of that statement are touched.
- **Access:** CEO only (`getNormalizedRole()` on the employee profile), 404 for every other role on all four routes, whatever the nav settings say; no NavAccess, no nav link. A "Card check" link shows on `/ads_manager/payment/records` for the CEO.
- Routes are in a `web, auth, allowed_ip` group.

## Case table

| Case | Test | Result |
|---|---|---|
| S-049.1 | `CardStatementParseTest::test_S_049_1_rcbc_lines_have_dates_amounts_credits_and_non_ads_are_listed` | Pass |
| S-049.2 | `CardStatementParseTest::test_S_049_2_card_numbers_are_masked_to_the_last_four` (18 variants) | Pass |
| S-049.3 | `CardStatementParseTest::test_S_049_3_other_banks_fall_back_to_lines_with_a_date_facebk_and_an_amount`, `CardReconcilePageTest::test_S_049_3_a_pdf_is_read_with_pdftotext_when_it_works_and_a_scan_without_text_is_refused` | Pass |
| S-049.4 | `CardStatementVisionTest::test_S_049_4_strict_json_lines_from_the_vision_model_are_read`, `CardReconcilePageTest::test_S_049_4_a_screenshot_upload_is_read_by_the_vision_model_and_a_bad_read_saves_nothing` | Pass |
| S-049.5 | `CardStatementVisionTest::test_S_049_5_an_answer_that_is_not_strict_json_lines_is_rejected` (14 variants) | Pass |
| S-049.6 | `CardMatcherTest::test_S_049_6_the_card_with_the_most_matching_amounts_is_suggested` | Pass |
| S-049.7 | `CardMatcherTest::test_S_049_7_same_amount_on_the_sale_date_then_on_the_post_date` | Pass |
| S-049.8 | `CardMatcherTest::test_S_049_8_within_two_days_is_near_three_days_is_not` | Pass |
| S-049.9 | `CardMatcherTest::test_S_049_9_one_to_one_closest_date_first_and_exact_before_near` | Pass |
| S-049.10 | `CardMatcherTest::test_S_049_10_repeated_amounts_on_a_day_are_tallied_as_a_group` | Pass |
| S-049.11 | `CardMatcherTest::test_S_049_11_per_day_card_and_meta_totals_with_the_difference` | Pass |
| S-049.12 | `CardMatcherTest::test_S_049_12_meta_records_outside_the_statement_range_are_not_missing_from_the_card` | Pass |
| S-049.13 | `CardReconcilePageTest::test_S_049_13_only_the_ceo_reaches_the_pages_even_with_nav_settings_checked` | Pass |
| S-049.14 | `CardReconcilePageTest::test_S_049_14_the_file_is_not_kept_only_the_parsed_lines` | Pass |
| S-049.15 | `CardReconcilePageTest::test_S_049_15_line_edits_are_saved_and_used_by_the_match` | Pass |
| S-049.16 | `CardReconcilePageTest::test_S_049_16_the_list_the_statement_sheet_and_the_suriin_box` | Pass |

Red runs (first failure per slice): parse `Error: Class "App\Services\CardStatement\StatementParser" not found`; vision `Error: Class "App\Services\CardStatement\StatementVision" not found`; matcher `Error: Class "App\Services\CardStatement\CardMatcher" not found`; page `Session missing error: statement … The selected card label is invalid` (then the FACEBK-code mask bug, `'FACEBK *AB···· 5003'`); fix loop: mask `Card Number 4111  1111  1111  5003` not masked, matcher `test_S_049_11` totals, vision `tinanggap: extra key` and `ConnectionException: cURL error 28`, page `Session is missing expected key [errors]` (edits field ignored).

**Suite** (`php.bat vendor/bin/phpunit`, whole suite, on the final tree): `Tests: 1483, Assertions: 17454, Errors: 1, Failures: 1, PHPUnit Deprecations: 11, Skipped: 3.` The two reds are the known ones: `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` and `Tests\Feature\NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. This slice's files alone: `OK (18 tests, 174 assertions)`. `php -l` clean on every changed PHP file.

## Review

One adversarial review (`skeptic-reviewer`, opus) on the whole diff, one fix loop, a scoped re-check: no blocker or major left.

- **Fixed (majors):**
  - The card-number mask missed double spaces (`pdftotext -layout`), NBSP, dots and glued prefixes ("CARDNO4111…"). A card number in a header line could have been stored and shown. Now masked; 18 variants in S-049.2.
  - The preview posted 5–7 inputs per line, so PHP's `max_input_vars` (1000) would silently cut a statement of about 166+ lines (a FACEBK line saved as not ads, or a statement that could never be matched). Now one JSON field.
  - A vision timeout or connection error gave a 500. Now it is a message and nothing is saved.
- **Fixed (minors on untrusted paths):** masking before cutting the description to 255; extra fields in a vision line rejected; an RCBC line with a bad date kept (null) for the preview; undated ads charges counted in "Wala sa Meta" and the card total; dead query removed; the records-page link gated on the same role as the page; any Unicode space (and up to 8) between card groups; a match with no `edits` field (JS not loaded) refused instead of dropping the edits.
- **Found while fixing:** the vision table test reused the first fake HTTP answer for every case, so most cases weren't really exercised; it now uses a fresh fake per case.
- **Accepted, in `TODO.md` with reasons:** ±2 days from the sale date only; the list's saved totals vs the view's live result until "Itugma ulit"; route binding before the `hasTable` guard; smalot without its own memory cap; server upload and timeout limits; the screenshot goes to OpenAI with the card number in the image; browser behaviour not automated.

## Story changes

- Stories S-049.1–S-049.16 were written from the brief's test list (the brief brings no stories). The one-shot brief left no step to wait for a "go" on the stories or the plan, so the plan (`docs/plans/049-card-reconcile.md`) is the record; this differs from the kit's flow and goes to the reviewer here.
- S-049.1: a dated line whose date doesn't parse is kept with a null date (found in review: the preview exists to correct bad reads).
- S-049.2: the variants grew from 7 to 18 (FACEBK code ending in digits, a number after the card, Amex, double and quadruple spaces, NBSP, thin space, dots, glued prefixes).
- S-049.5: an extra field per line, a null content, more than 500 lines and a connection timeout are rejected too.
- S-049.15: edits are posted as one JSON field; bad or missing edits save nothing; "Itugma ulit" clears an earlier match.

## Browser check

Not run. The session has no running app with the database these pages need, and the brief kept this to tests. The Alpine edit serializer and the filter buttons are not covered by an automated test (in `TODO.md`); the rendered form is checked for the `edits` field and no per-cell inputs.

## Anything the brief got wrong or left open

- **Conflict with the kit:** the kit wants the plan and stories confirmed before code; the brief is one-shot (final message only), so they were written and committed first and are reported here.
- **"Only Meta records inside the statement's date range"** and "±2 days" overlap: a record 1–2 days outside the range can still be a near match (and is then Tugma), but an unmatched one there is never "wala sa card". That is how it is built.
- **"The uploaded file is not kept after parsing (or deleted after 24 hours)":** it is never written at all, so no cleanup job.
- **The vision path sends the full card number to OpenAI in the image.** Only the masked lines are stored. A PDF avoids it.
- **The file name** can hold a card number too; it is masked like the lines.
- **Meta records with a wrong year** (the live 2028-07-05 row) fall outside every statement range, so they never show as "wala sa card"; they show in the Suriin box.

## For deploy

- Run `php artisan migrate --force` (one new migration).
- Optional env: `OPENAI_STATEMENT_MODEL` (default `gpt-4o`), `POPPLER_PDFTOTEXT` (default `pdftotext`; without it smalot reads the PDF). `OPENAI_API_KEY` is already used by the app.
- Check `upload_max_filesize` / `post_max_size` ≥ 10 MB and the gateway timeout ≥ 90 s for screenshots.
- No queue, no schedule, no npm build (no asset change).
