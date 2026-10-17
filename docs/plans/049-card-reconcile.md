# Plan 049: card counter-check of Meta ad payments

Spec: `docs/specs/049-card-reconcile.md`. Threat model: untrusted = the uploaded statement (PDF bytes, image bytes, every line read from them), the vision model's answer, and all request input on the new routes (card label, bank, line edits, filters, ids); trusted = the Meta records in `payment_activity_ads_manager` (imported by our own automation), config, migrations. Nothing here writes to `payment_activity_ads_manager`.

## Design decisions the spec leaves open

- **Tables** (new, one migration): `card_statements` (`card_label`, `bank`, `source_kind` pdf/image, `original_name` masked, `period_start`, `period_end`, `status` preview/matched, `lines_count`, `ads_count`, `card_total`, `matched_count`, `matched_total`, `no_meta_count`, `no_meta_total`, `no_card_count`, `no_card_total`, `meta_total`, `difference_total`, `groups_tallied`, `groups_untallied`, `uploaded_by` user id, `matched_at`, timestamps) and `card_statement_lines` (`card_statement_id`, `line_no`, `sale_date`, `post_date`, `description`, `amount`, `is_credit`, `is_ads`, `payment_activity_id`, `group_key`, `match_kind`, `match_days`, timestamps). Guarded with `Schema::hasTable`.
- **The file is never stored**: it is read from PHP's upload temp file, parsed, and the temp file is gone at the end of the request. No disk write at all, so no 24-hour cleanup job.
- **Masking** (`CardNumberMask`): any run of 13–19 digits, also with single spaces or dashes between the groups, becomes `···· 1234`. Applied to every description, to the original file name, and to the vision answer before anything is saved or shown.
- **Layouts** (`app/Services/CardStatement/`): an interface with `detects(text)` and `parse(text)`; `RcbcLayout` (two `MM/DD/YY` dates, description, amount, `-` for credits; dateless amount lines such as PREVIOUS STATEMENT BALANCE are listed as not ads) and `GenericLayout` (any line with a date, a FACEBK/FACEBOOK/META description and an amount). Bank choice on upload: auto (RCBC when the text says RCBC, else generic), RCBC, generic.
- **PDF text**: `pdftotext -layout` when the binary works (`POPPLER_PDFTOTEXT`, as the J&T sticker job), else `smalot/pdfparser`. A PDF with no text lines (a scan) → the page says to upload a screenshot instead.
- **Image**: OpenAI chat completions, image as a base64 data URL, `response_format: json_object`, model `services.openai.statement_model` (`OPENAI_STATEMENT_MODEL`, default `gpt-4o`, the vision model the checklist analysis uses), key `services.openai.key`. The answer must be `{"lines":[{sale_date, post_date, description, amount, is_credit}]}`; any other shape, a bad date or a non-number amount rejects the whole read (nothing saved). Lines are then classified as ads by the same FACEBK rule as the text layouts.
- **Ads rule**: description contains `FACEBK`, `FACEBOOK` or `META PLATFORMS` (case-insensitive).
- **Matching** (`CardMatcher`, pure PHP over arrays): ads charge lines vs Meta records of the chosen card dated period−2..period+2 days.
  1. Group keys: (date, amount) where the statement has ≥2 ads charges of that amount on that sale date or Meta has ≥2 records of that amount on that date. Those lines get `match_kind = group` and the key; the Meta records with that key are taken by the group. A group is tallied when the counts are equal.
  2. Then one-to-one by exact amount, in passes over all lines: same sale date, then same post date, then 1..2 days from the sale date, closest first (ties: earlier line, lower Meta id).
  3. Left over: line `none` (Nasa card pero wala sa Meta); a Meta record unmatched and dated inside the period (first..last sale date) → Nasa Meta pero wala sa card. Credits (`credit`) and non-ads lines (`not_ads`) are listed, never matched.
  4. Per-day table: by date in the period, card total (ads charges by sale date) vs Meta total (the card's Meta records), difference = card − Meta.
- **Card suggestion**: per payment method in Meta records dated period±2, how many ads charge amounts equal a record of that method within ±2 days; the most wins (ties: alphabetical). Shown in the preview, preselected when the upload left the card blank.
- **Flow**: upload → statement saved as `preview` with its lines → preview with editable sale date, post date, amount, credit and ads flags per line, card select with the suggestion → "Itugma" saves edits and matches (status `matched`, totals stored) → statement view. "Itugma ulit" re-runs it (new Meta rows come in daily). The card label must be one of the payment methods in Meta records.
- **Suriin box**: Meta records dated after today (Manila), newest 50, read-only.
- **Access**: `getNormalizedRole() === 'CEO'` in the controller, else 404. Routes in a `web, auth, allowed_ip` group. A "Card check" link on the payment records page for the CEO only (no nav link, so nav settings can't show it to others).

## Stories

The brief brings its test list but no stories; the stories in `qa/stories.md` (slice 049) are written from that list. The one-shot brief gives no chance to wait for a "go", so this plan is the record and goes with the result.

## Tasks

| # | Task | Side | Risk | Covers |
|---|---|---|---|---|
| 1 | Migration, masking, RCBC + generic layouts, PDF text | backend | high | S-049.1–049.3 |
| 2 | Vision reader (fake HTTP) | backend | high | S-049.4, 049.5 |
| 3 | Matcher, card suggestion | backend | medium | S-049.6–049.12 |
| 4 | Controller, routes, upload/preview/match/show, CEO only, file not kept, suriin | backend | high | S-049.13–049.16 |
| 5 | Views: index (upload, list, suriin), preview, statement sheet; link on records page | frontend | medium | S-049.16 (render) |

Tasks share the service folder, so they run in order. One adversarial review on the whole diff before the result.
