# Result 099: /chatbot/pages checks each page's values, and shows the history of every edit

Branch `feat/099-pages-check-and-history`, cut from `main`. Nothing was pushed, and no server was touched.

## What shipped

| Part | What |
|---|---|
| Check column | `App\Services\Chatbot\ChatbotPageCheck`. The column comes right after FLOW. A page passes with the check icon and "All values pass"; it fails with the X icon and one reason per line; a page with no flow shows "—". Each cell shows when it was checked and has a "Check again" link. The icons are the same SVGs as the Check column on `/chatblast/page-settings`. |
| Missing values | Reads every `{{Name}}` in the flow's **published** graph: send texts and images, AI prompts, condition variables and values, sheet columns and set-field values. It also follows the flows that the flow's sequence steps subscribe to. A botfield named inside a used botfield is used too, transitively. Each used botfield must be non-empty: "Missing: QUANTITY, Prompt 2, Seq 4", in grid order. Customer fields and system variables are never missing. |
| Image links | `App\Services\Chatbot\ImageLinkCheck`. Every image-kind field with a value is checked with a HEAD request; on 403, 405 or 501 it retries with a GET for one byte (Range header). A link passes on 200 or 206 with `image/*`. The timeout is 5 s and redirects are followed at most 3 times, each one re-checked. SSRF guard: http(s) only; no userinfo; every resolved IP must be public; the request is pinned to the checked IP with `CURLOPT_RESOLVE`. Blocked: private, loopback, link-local, reserved, 100.64/10, IPv4-in-IPv6, 6to4 and NAT64. Results are cached 24 h per URL (10 min for "no answer" and "link not allowed"). A run asks at most 20 links within a 20 s budget; the rest show as "N image links not checked yet". Reasons read "Seq Img 3: link broken (404)", "PICTURE: not an image" or "…: link not allowed". |
| Pairs | When the flow (or its sequences) uses both Seq N and Seq Img N fields: "Seq 5 has no Seq Img 5", or "Seq Img 6 has no Seq 6". A field already listed as missing is not reported twice. |
| Unknown variables | A `{{X}}` in any botfield value that is not a system variable, botfield or customer field gives "Prompt 1 uses {{PRICEE}} (unknown)". Customer fields are the fields any flow sets (save to, set field, clear AI fields) plus the keys already stored in `chatbot_customer_values`. |
| Check cache | 10 minutes per page. The cache key includes a fingerprint of the page's settings (highest id and replaced_at, chatbot and botcake) and of the flows, sequences and steps (`updated_at` and count). Any write makes the next read recompute, and `PageSettings` stays unchanged. `/chatbot/data` returns only cached results; the page then asks `GET /chatbot/check` for the rest, three at a time. "Check again" (`POST`) recomputes and asks the image links again. |
| Preview | A "Preview" button on each row and in the Edit modal. It shows PICTURE, Main Flow, Main Flow 2, Fill-Up Form, Loop 3 Form and Seq 1–20, with each Seq Img N above its Seq N. Values are filled the way `FlowRun::vars` fills them, for the sample customer "Juan Dela Cruz". Read-only. |
| History per field | Each field in the Edit modal has a "History" link. It lists every version, newest first: value (3 lines, with "Show all"), who, when (Manila) and Current or Old. Each old version has Restore, which writes through `PageSettings::set` as the user, so the restore is itself a new row. The open Edit field takes the restored value. Restore refuses secrets, ACTIVE, FLOW, system keys and keys of other providers. |
| Changes log | A "Changes" button above the grid. It shows edits across all chatbot pages (provider `chatbot`, plus the Botcake ID and token from the grid), newest first, 50 per page, with "Newer" and "Older". Each entry shows when, who, page, field and old → new (each folded), with "(new)" for a first value. Filters: page, user (only users who made an edit), field and a date range (Manila, both ends inclusive). Secret values always show "(hidden)". |
| Permissions | Every new endpoint uses the page's own check (`/chatblast` nav access, 403 otherwise; guests 401). Restore uses the same check as editing a cell. |

New routes (all under the `/chatbot` group with `auth` and `allowed_ip`, each throttled): `GET /chatbot/check`, `POST /chatbot/check`, `GET /chatbot/preview`, `GET /chatbot/history`, `POST /chatbot/restore`, `GET /chatbot/changes`.

## Case table

| Case | Test | Result |
|---|---|---|
| S-099.1 | `test_S_099_1_missing_botfields_come_from_every_node_kind_and_from_nested_botfields` | Pass |
| S-099.2 | `test_S_099_2_image_links_must_answer_200_with_an_image` (10 data sets: 200 image, 404, not an image, private IP, loopback literal, link-local, mixed public and private IPs, IPv4 inside IPv6, carrier-grade NAT, not http), `test_S_099_2_at_most_20_links_are_asked_per_run_and_the_rest_wait`, `test_S_099_2_redirects_are_rechecked_and_head_falls_back_to_a_ranged_get_and_urls_are_cached` | Pass |
| S-099.3 | `test_S_099_3_a_seq_set_without_its_image_or_the_reverse_is_a_pair_reason_when_the_flow_or_its_sequences_use_both` | Pass |
| S-099.4 | `test_S_099_4_an_unknown_variable_inside_a_botfield_is_a_reason` | Pass |
| S-099.5 | `test_S_099_5_the_check_is_cached_and_recomputed_after_a_settings_or_flow_change` | Pass |
| S-099.6 | `test_S_099_6_a_fields_history_lists_its_versions_newest_first_with_who_and_when` | Pass |
| S-099.7 | `test_S_099_7_restore_writes_the_old_value_back_as_a_new_row_and_refuses_what_a_cell_cannot_take` | Pass |
| S-099.8 | `test_S_099_8_the_changes_log_pairs_old_and_new_filters_paginates_and_hides_secrets` | Pass |
| S-099.9 | `test_S_099_9_every_new_endpoint_needs_chatblast_access` | Pass |
| S-099.10 | `test_S_099_10_preview_fills_the_main_texts_for_a_sample_customer_with_images` | Pass |
| S-099.11 | Browser check below | Pass |

Red runs: before any code, all 11 `ChatbotPageCheckTest` tests failed with `Class "App\Services\Chatbot\ImageLinkCheck" not found`, and the `ChatbotPageHistoryTest` tests failed with `Expected response status code [200] but received 404` (`[401]` for S-099.9). Three of my test fixtures were wrong in the first green run, and I fixed them in the tests:
- a second `user()` with the same email;
- the clock set in UTC while the app runs in Manila;
- a Prompt 1 value that named Prompt 3, which the check rightly reported as missing.

## Test output

- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: **OK**, 778 tests, 5,258 assertions, 1 skipped.
- Full suite: 2,133 tests, 1 error and 1 failure. They are the same two that fail on `main` without this work (baseline run at the start: 2,112 tests, the same 1 error and 1 failure):
  - `Tests\Feature\NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`
  - `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response`

  No new failures.
- `php -l` is clean on every changed PHP file.

## Review

`skeptic-reviewer`, standard depth, on the backend diff (`c936c087..22fe5f3f`). No blockers or majors; it found no secret leak, SSRF bypass or permission gap.
- **Fixed (`52681f7b`), minors on the untrusted path:**
  - a total 20 s budget for the image checks per run, so a page of slow hosts can't make the page-load GET time out;
  - the ranged GET is streamed, so a server that ignores Range is not downloaded;
  - IPv6 IPs are bracketed in the curl pin;
  - 6to4 and NAT64 are refused;
  - "no answer" and "link not allowed" are cached 10 minutes instead of 24 h.
  
  New table rows and tests cover mixed IPs, IPv4-in-IPv6, CGNAT, a non-http scheme and the 20-link cap.
- **Accepted, with reasons in `TODO.md`:** a slow single link after the budget starts, exact page-name pairing and one query per Changes entry, the distinct read of customer keys, the fingerprint ignoring "+ Column", hand-listed run variables in Preview, the IPv6 path untried live, and no JS test runner.

## Browser check

The browser-checker agent has no browser tools in this session, so I used the same approach as 092 and 096. A script outside the repo drove the installed Chrome headless over the DevTools protocol (Node built-ins only).
- **App:** `php artisan serve` on a temporary sqlite database outside the repo, with settings passed as environment variables. No env file was created.
- **Data:** three pages:
  - Alpha Shop: complete;
  - Bravo Store: missing Prompt 1, a broken PICTURE, Seq 5 without Seq Img 5, and `{{PRICEE}}`;
  - Charlie Mart: no flow, 55 QUANTITY edits.
- **Images:** a local static server on 127.0.0.1. The real check refuses loopback by design, so the image results were put in the cache before each width. No outside host was called by the server.

**Result: 22 of 22 checks passed at each width (1366 × 768 and 390 × 844), with no console errors.** Screenshots are in `docs/specs/099-pages-check-and-history/`.

The first full run failed 5 checks, all caused by my setup, not the feature:
- **Image results:** "Check again" re-asked the loopback image links. The SSRF guard refused them, which is correct, and that replaced the cached results Alpha shares.
- **Data between widths:** the second width saw the first width's restored price.
- **Token check:** it looked for the token on page 1 of Changes, but the token rows are the oldest.

The fixes were to the data and the script: a fresh database, the image cache put back before each width, and a field filter for the token.

| # | Check | 1366 | 390 |
|---|---|---|---|
| 1 | The Check column comes right after Flow ("Page, Active, Flow, Check"). Alpha shows the check icon and "All values pass". Bravo shows the X and "Missing: Prompt 1", "PICTURE: link broken (404)", "Seq 5 has no Seq Img 5" and "Main Flow uses {{PRICEE}} (unknown)". Charlie shows "—". | `1366-check.png` | `390-check.png`, `390-check-scrolled.png` |
| 2 | No page-level horizontal or vertical scroll; the table box ends at 752/768 and 828/844 (092 table-fill layout kept). | ✓ | ✓ |
| 3 | Check again on Bravo recomputes and shows the reasons again. | ✓ | ✓ |
| 4 | Preview from the row shows PICTURE, "Hi Juan! 👋 / Our shoes are only ₱…", "Name: Juan Dela Cruz", and Seq 1 with its image. Escape closes it. | `1366-preview.png` | `390-preview.png` (full screen) |
| 5 | The Edit modal has Preview, and every field has a History link. | ✓ | ✓ |
| 6 | History of PRICE lists Current (Ana Reyes) and then the Old versions with who and when, each with Restore. | `1366-history.png` | `390-history.png` |
| 7 | Restore makes the old value Current (a new row on top) and fills the open Edit field. Escape closes History and leaves Edit open. | `1366-restored.png` | `390-restored.png` |
| 8 | Changes: newest first, with old → new. With field "Botcake token", the three entries show "(hidden)" and never the token. | `1366-changes.png` | `390-changes.png` |
| 9 | Field QUANTITY with user Ben Cruz gives "Page 1 of 2 · 55 changes" and 50 entries. Older shows page 2 with 5 entries, the last "(new) → 1". | `1366-changes-filtered.png` | `390-changes-filtered.png` |

At 390 px, only the Page column is sticky, as before, so the Check column is reached by scrolling the table sideways (`390-check-scrolled.png`).

## Story changes

- The spec brought decisions and a "Done when" list but no Given/When/Then stories. I wrote S-099.1 to S-099.11 from that list into `qa/stories.md`, without the story-writer agent, because each case maps one-to-one to a done-when item. Please confirm them.
- S-099.2 adds cases beyond the spec, from the review: "one private IP among public ones", IPv4-in-IPv6, carrier-grade NAT and a non-http scheme are refused, and the 20-link cap leaves the rest "not checked yet".

## Notes and conflicts

- **The 097 grouping helper is not on `main`, so the check has its own split.** System variables come from `ChatbotFlowController::RUN_VARS`; botfields are column keys and labels; customer fields are as above. Names are normalized with `ChatbotVars::norm`, as `ChatbotVars::merge` does. When 097 lands, the two can share one helper.
- **`PageSettings` is not changed.** The Check cache is invalidated through the fingerprint key instead of a forget call in `set`, so it doesn't overlap with spec 098's cache in `PageSettings::get`.
- **`editor.blade.php` is not touched.**
- **Plan gate:** there was no reviewer in this session to say "go" on the plan, so I took the brief's "Start now" as the go. The plan is in `docs/plans/099-pages-check-and-history.md`.
- **No Changelog file** exists in the repo, so there is no changelog entry.

## Merge danger

- **Low.** No migration, no new setting and no new dependency.
- `routes/web.php` gains six lines at the end of the `/chatbot` group. `ChatbotController` gains methods and one field (`check`) in each `/chatbot/data` row. The view changes only `chatbot/index.blade.php`.
- Spec 098 (`PageSettings`) and spec 097 (`editor.blade.php`) don't touch these files, so a conflict is unlikely, except in `routes/web.php` if either adds routes in the same place.
- **Deploy:**
  - The server now makes outgoing HEAD/GET requests to image links typed in botfields. They come from the Check column on page load (cached 24 h per link) and from "Check again".
  - The host needs outbound HTTP(S) for those links. Without it, they show as "link broken" or "no answer".

## Commits

- `c936c087` docs: spec 099 pages check and history
- `22fe5f3f` feat: page check, preview, field history, restore and changes log for /chatbot/pages (spec 099)
- `52681f7b` fix: time budget, streamed GET, IPv6 pin and shorter cache for network failures in the image link check (spec 099)
- `c1a656f3` feat: Check column, Preview, field History with Restore and Changes list on /chatbot/pages (spec 099)
- the last commit: docs: result, stories, TODO and browser screenshots for spec 099
