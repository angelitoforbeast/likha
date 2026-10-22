# Result 106: the page check also catches the wrong item, the wrong price and the wrong ad video

Branch `feat/106-page-content-check`, cut from `main` (50d3e1f1). Nothing was pushed and no server was touched. No env file was read or created.

## What shipped

| Part | What |
|---|---|
| Expected item and price | `App\Services\Chatbot\PageExpectedItem`. (1) **orders**: the most common ITEM_NAME + COD pair of the page's `macro_output` rows with `ts_date` today or the 2 days before (Manila). COD `"₱1,299.00"` reads as 1299, and a COD of 0 or blank counts as no price. One grouped query covers all pages, and page names are matched in PHP, so `" page  uno "` counts. (2) **ads**: the latest non-blank `ads_manager_reports.item_name` (no price). (3) **item_settings**: the latest `page_item_settings` row already in effect (`item_name`, `mode_cod_int`). The source is part of the answer, and the cell shows it: "Item: SEAT COVER ₱299 (orders, 3 days)", "(ads)" or "(item settings)". With no expected item, the content check is skipped. |
| Text check | `App\Services\Chatbot\PageContentCheck`, run on Main Flow, Main Flow 2 and Fill-Up Form. Each `{{Botfield}}` in the text is filled from the page's botfields first, so a price kept in another botfield is read. Customer fields are left as they are. The AI returns `found_item`, `same_product`, `confidence` and `prices[{item_price, shipping, insurance, total}]` as strict JSON, and the answer is checked in PHP. `same_product` true passes, so synonyms and variants pass. "Another item" at confidence 70 or more is an X: "Main Flow: FLEXIBLE CLIP PHONE HOLDER ≠ orders: SEAT COVER (90%)". Below 70 it is an amber warning: "Fill-Up Form: maybe LED STRIP, orders: SEAT COVER (55%)". |
| Price, like with like | No AI here; this uses the prices the text answer extracted. It runs only when the expected price is known and the item did not already fail. A stated total is compared with the COD. A stated item price plus the shipping and insurance the text states is compared with the COD, so item price = COD − shipping − insurance. The price passes when any offer matches within ₱1. Otherwise it is an X: "Main Flow: price ₱199 ≠ orders ₱299 COD" or "Main Flow 2: price ₱199 + ₱50 shipping = ₱249 ≠ orders ₱299 COD". |
| Image check | PICTURE, then Seq Img 1–20, at most 5 links per page (http/https only). The image URL is sent to OpenAI vision with `detail: low`; our server never downloads it. An X reads "PICTURE: looks like LED STRIP, orders: SEAT COVER". |
| Ad video check | The analysis's item_name and summary, run through the same text check: "Ad video 'xyz.mp4': NIGHT LAMP ≠ SEAT COVER". No new video analysis. The link is explained below. |
| Cache and cost | New table `chatbot_content_answers`, one row per AI answer. The unique `cache_key` is sha1 of kind, the reference and the expected item. For text, the reference is the botfield's version id plus a hash of the filled text. For images it is a hash of the URL. For videos it is the analysis id plus its `updated_at`. A page view never calls the AI: `/chatbot/check` and `/chatbot/data` read stored answers only, and targets without one show "N content checks not done yet". Each row keeps `model`, `tokens_in`, `tokens_out` and `cost_usd`. Cost is tokens × `services.openai.ai_checker_prices`, the same as the AI Checker; `gpt-4o-mini` was added at $0.15 / $0.60 per 1M. A failed call or an answer that isn't valid JSON stores nothing and stays "not done yet". |
| Check column | `ChatbotPageCheck` appends the content X reasons to `reasons` and adds `warnings` (amber) and `content {expected, findings, unchecked}` to the JSON. `status` keeps its values (ok, x, none). Its fingerprint now also covers `chatbot_content_answers`, so a finished run shows on the next read. The cell shows a green check only when there are no warnings. Warnings get an amber triangle, under the red list or on their own. The cell also shows the item compared, with its source. "Check again" also queues the content run for that page. |
| Job, command, schedule | `App\Jobs\RunChatbotContentCheck`: queue `default`, unique per page or for all, `tries` 1, timeout 1800 s, and a 1300 s budget checked between pages. `chatbot:content-check [--page=] [--sync] [--json]` queues the job, or runs it in this process with `--sync`. `--json` prints `{"pages":[{page, expected, findings[{kind, field, level, reason}], unchecked}]}` from the stored answers; no new AI calls unless `--sync` is also given. It runs nightly at 02:40 Manila (`routes/console.php`). |
| Read-only | No page value is ever written. |
| Config | `services.openai.content_check_model` (env `CHATBOT_CONTENT_MODEL`, default `gpt-4o-mini`), added to `.env.example`. The default needs nothing set. |

## How ad videos link to ads

**No ad links to a video.** `gpt_video_analyses` stores no ad id, creative id or page. `ads_manager_reports` and `ad_campaign_creatives` store no video id or file name, and the Facebook ad's video id isn't stored anywhere in the app.

The only link that exists is `gpt_ad_generations.video_analysis_id`, together with `page_filter`: the comma-separated pages chosen when ad copy was written from that video on `/gpt-ad-generator`. The video check uses that link only, for up to the 3 newest such videos per page, with exact page-name matches after splitting on commas. It means "a video that ad copy was written from for this page". It does not prove the video is in a live ad. A page with no such generation gets no video reason and no AI call.

## Model and estimated cost

- **Model:** `gpt-4o-mini` for text and vision (one model, configurable).
- **Per call (estimate):**
  - text: about 1,800 tokens in and 120 out, about $0.00034;
  - image at `detail: low`: about 3,100 tokens in (gpt-4o-mini counts a low-detail image as about 2,833 tokens) and 60 out, about $0.0005;
  - video summary: about 600 tokens in, about $0.00016.
- **First full run:** at most 3 texts, 5 images and 3 videos per page, so at most about $0.004 per page. For 60 pages that is at most about **$0.24 (about ₱14 at ₱58/$)**. A typical page has 3 texts and 1–2 pictures, about **$0.10 (₱6)** for all pages.
- **Nightly runs after that:** only new or changed values, or a page whose top order item changed, are asked again. Usually that is cents.
- Exact figures are in `chatbot_content_answers.cost_usd`.

## Migration (its release needs the owner's yes)

`database/migrations/2026_11_02_106000_create_chatbot_content_answers_table.php` creates one new table and is guarded with `Schema::hasTable`. Nothing else in the schema changes. Until it runs, the content check reads nothing and asks nothing, and the Check column works as before.

## Case table

| Case | Test | Result |
|---|---|---|
| S-106.1 | `test_S_106_1_the_expected_item_and_price_are_the_most_common_pair_of_the_last_3_days_of_orders` | Pass |
| S-106.2 | `test_S_106_2_without_recent_orders_the_latest_ads_item_then_the_item_settings_are_used_and_with_none_nothing_is_asked` | Pass |
| S-106.3 | `test_S_106_3_another_item_is_an_x_a_synonym_passes_and_low_confidence_is_an_amber_warning` | Pass |
| S-106.4 | `test_S_106_4_the_price_compares_like_with_like_with_the_order_cod` (10 data sets) | Pass |
| S-106.5 | `test_S_106_5_at_most_5_images_are_asked_picture_first_and_another_item_is_a_reason` | Pass |
| S-106.6 | `test_S_106_6_a_video_linked_to_the_page_through_its_ad_copy_is_compared_and_an_unlinked_page_asks_nothing` | Pass |
| S-106.7 | `test_S_106_7_an_answer_is_reused_for_the_same_version_and_expected_item_and_a_page_view_never_asks` | Pass |
| S-106.8 | `test_S_106_8_the_command_queues_the_job_runs_it_with_sync_nightly_and_check_again_queues_one_page` | Pass |
| S-106.9 | `test_S_106_9_the_check_json_and_the_command_json_carry_the_content_findings` | Pass |
| S-106.10 | `test_S_106_10_each_answer_logs_model_tokens_and_cost_and_a_failed_answer_stores_nothing` | Pass |
| S-106.11 | Browser check below | Pass |

`tests/Feature/Chatblast/ChatbotContentCheckTest.php`: **OK, 19 tests, 65 assertions.** OpenAI is faked with `Http::fake`, answering by the text or link in each question.

**Red runs:**
- Before any code, all 19 tests failed with `File does not exist at path …/2026_11_02_106000_create_chatbot_content_answers_table.php`, the new table about to be made. One earlier run hit a fatal error because a test helper was named `run()`, which PHPUnit marks as final; I renamed it to `content()`.
- For the review fixes: S-106.1 failed with `'price' => null` expected but `0` actual, and S-106.10 with `3 is identical to 2`.

## Test output

- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: **OK, 855 tests, 5,600 assertions, 1 skipped.**
  - The first run had 1 failure: `ChatbotWaitSkipTest::test_S_076_3_a_changed_flow_or_page_drops_the_wait` with data set "page Off". That test passed alone twice, and the whole set passed on the next run. The test is about chatbot waits, which this work doesn't touch, so I read it as a timing flake. It isn't in the baseline either.
- Full suite: 2,232 tests, 1 error and 1 failure. The baseline on `main` before this work had 2,213 tests with the same 1 error and 1 failure, so there are no new failures:
  - `Tests\Feature\NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`
  - `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response`
- `php -l` is clean on every changed PHP file.

## Review

`skeptic-reviewer`, standard depth, on `b278a8ae..1032ae1e`: **no blockers or majors.**
- **Fixed in `2aea393a`:**
  - A COD of 0 made the expected price ₱0, which flagged every stated price. 0 or blank now counts as no price, with a test.
  - An AI answer with `same_product: "true"` as a string was dropped and asked again every night. Now `"true"` and `"false"` are accepted, with a test.
  - The job's time budget was checked only between pages, and one page can take about 440 s. The budget is now 1300 s, under the 1800 s timeout.
- **Accepted with reasons in `TODO.md`:**
  - a two-number COD text;
  - double spaces in the ads and item settings fallbacks;
  - the prompt marker that a seller's text could close;
  - an image replaced behind the same URL;
  - untested pgsql/mysql branches, tie-break, text cut and budget.

## Browser check

The browser-checker has no browser tools in this session, so I used the approach of specs 099–104. A throwaway PHPUnit test outside the repo seeded three pages through the real services, ran the content check with the AI faked, and saved `/chatbot/pages` along with the JSON of `/chatbot/data` and `/chatbot/check`. The seeded pages were:
- an X page: Main Flow wrong item, Main Flow 2 wrong price, PICTURE wrong item, a wrong ad video, plus one warning;
- a warnings-only page;
- a page with "content checks not done yet" whose expected item comes from ads.

A script outside the repo, using Node built-ins only, opened the saved page in headless Chrome over DevTools with the app's built CSS, answered those two endpoints from the saved JSON, and set the viewport to exactly 1366 and 390 px. The monospace JSON line at the bottom of each screenshot is that script's own measurement, not part of the page.

**Result: pass.** There is no page-level horizontal scroll at either width (page width 1366/1366 and 390/390; 0 elements wider than the viewport outside a scroll box), and no console errors.

| Check | 1366 | 390 | Evidence |
|---|---|---|---|
| Content X reasons in red with the X icon, one per line, wrapping inside the column | pass | pass | `106-page-content-check/1366-check.png`, `390-check-scrolled.png` |
| Warnings in amber with a triangle icon, below the red list or alone (no green check when warnings exist) | pass | pass | same |
| "3 content checks not done yet" in grey, and the item line with its source ("orders, 3 days", "ads") | pass | pass | same |
| At 390 the table scrolls inside its box, and the Check column sits beside the sticky page column | — | pass | `390-check.png`, `390-check-scrolled.png` |

## Story changes

- The spec brought no stories, so I wrote S-106 into `qa/stories.md` straight from its "Done when", without the story-writer round, on the instruction to start at once.
- S-106.10 was extended in the fix loop: a string `"true"` from the AI is accepted and stored, so it is not asked again every night.
- S-106.1 was extended: a COD of 0 means no price.

## Deviations and notes

- **Cache key:** the decision says the key is the value's version id. The key is the version id **plus** a hash of the text after its `{{Botfield}}` values are filled in. Without the hash, a change to a botfield the Main Flow names (for example `{{PRICE}}`) would keep a stale answer.
- **Check again** (POST) now also queues the content run for that page. The AI still never runs inside the request.
- The plan was committed before the go; the reviewer's start instruction was taken as the go. Later, the migration's file name in the plan changed from `…100000…` to `…106000…` so it can't clash with spec 105's migrations.
- Ordering by 3-day orders means a page whose top item flips between two items from day to day gets asked again on each flip. It is cheap, but it is visible in `cost_usd`.
- `public/build` was copied from the main checkout, untracked and gitignored, only to render the page for the browser check. `npm run build` wasn't needed: no JS or CSS asset changed, only an inline script in the Blade view, and the amber classes were already in the built CSS.

## Suggestions for the owner

- To check the video a live ad actually uses, the app would need to store the ad's creative video id (or the uploaded file's hash) at ad upload time. That would replace the ad-copy link.

## Commits

- `b278a8ae` docs: brief, plan and stories for the page content check (spec 106)
- `1032ae1e` feat: page content check for item, price, pictures and ad videos (spec 106)
- `2aea393a` fix: zero COD has no price, accept a string yes or no from the AI, shorter job budget (spec 106)
- `83cfaf83` feat: Check column shows content warnings in amber, the item compared and checks not done yet (spec 106)
- the last commit: docs: result, stories and accepted findings for the page content check (spec 106)
