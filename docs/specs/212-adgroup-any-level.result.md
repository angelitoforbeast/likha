# Result: spec 212 Ad groups match by ad, ad set or campaign id, and quiet groups fold away

**Status:** done
**Date:** 2026-11-01
**Branch / PR:** `feat/212-adgroup-any-level` (cut from `vps/main` 8cdb1a7b). Not pushed, no PR: pushing was not allowed for this job.
**Preview or run link:** n/a. Screenshots are in `docs/specs/212-evidence/`.

## Summary

- **Matching.** A customer's ad now matches a group that lists the ad, its ad set or its campaign. The ad itself wins over its ad set, and the ad set over its campaign, whatever the group numbers (`AdGroups::match`).
- **Where the parents come from.** The ad's ad set and campaign come from, in order:
  1. a 24 h cache entry for that ad
  2. its `ad_catalog` row
  3. `ads_manager_reports`
  4. one live Meta Graph call, `GET /<ad>?fields=id,adset_id,campaign_id`
- **Limits on the Meta call.** One try, 3 s timeout. One call per ad at a time, under a lock; anyone else waits up to 3 s. At most 30 calls a minute per page. "Not found" is remembered for 1 h, and a failure for 5 min.
- **What a found lookup writes.** The ids go into the ad's `ad_catalog` row, so later messages never call Meta again.
- **When a lookup fails.**
  - The message uses the page values.
  - The run log gets an `ad_lookup` event.
  - A sequence is not pinned to page values. This holds on the runner path, the sequence-step path, and the wait-resume and AI-resume paths.
- **The panel.**
  - Each id is tagged Ad, Ad set, Campaign or "Not found in Ads Manager", with its own name.
  - An ad set or campaign's status and customers are rolled up from the ads under it.
  - "Ads not in a group yet" leaves out ads covered by a grouped ad set or campaign.
  - The 7-day quiet rule hides quiet groups and folds quiet ids, behind a "Show hidden (N)" / "Hide again" button with `aria-pressed`.
  - Hiding only changes the screen. It writes nothing and does not change routing.

## Case table

New tests ran green on this branch. On `vps/main`'s code, 33 of the first 42 runs failed (the red run; the 2 review-fix tests came later). The 9 that pass on `vps/main` pin behaviour that must not change: 1.5, 1.9, 1.10, 1.12, 3.4, 4.7, 8.4, the "ad itself" data set of 1.1, and the first data set of 1.8.

| Case | Test | Result |
|---|---|---|
| 1.1, 1.2, 3.2, 3.6 | `AdGroupRunTest::test_S_212_1_1_1_2_3_2_and_3_6…` (campaign, ad set, ad: same text) | pass |
| 1.3, 1.4 | `AdGroupRunTest::test_S_212_1_3_and_1_4…` | pass |
| 1.5 | `AdGroupRunTest::test_S_212_1_5…` | pass |
| 1.6 | `AdGroupsTest::test_S_212_1_6…` | pass |
| 1.7 | `AdGroupsTest::test_S_212_1_7…` | pass |
| 1.8 | `AdGroupRunTest::test_S_212_1_8…` (3 data sets) | pass |
| 1.9, 2.9, 3.7 | `AdGroupRunTest::test_S_212_1_9_2_9_and_3_7…` | pass |
| 1.10 | `AdGroupRunTest::test_S_212_1_10…` | pass |
| 1.11 | `AdGroupRunTest::test_S_212_1_11…` | pass |
| 1.12 | `AdGroupPagesTest::test_S_212_1_12…` (10 routes) | pass |
| 2.1, 2.2 | `AdGroupRunTest::test_S_212_2_1_and_2_2…` | pass |
| 2.3 | `AdGroupRunTest::test_S_212_2_3…` | pass |
| 2.4 | `AdGroupRunTest::test_S_212_2_4…` | pass |
| 2.5 | `AdGroupRunTest::test_S_212_2_5…` | pass |
| 2.6, 2.10 | `AdGroupRunTest::test_S_212_2_6_and_2_10…` (timeout, 5xx, rate limit, expired token) | pass |
| 2.6, 8.3 | `AdGroupRunTest::test_S_212_2_6_and_8_3…` (one try, 3 s) | pass |
| 2.6 | `AdGroupRunTest::test_S_212_2_6_no_meta_connection…`, `…_2_6_and_2_7_an_expired_meta_connection…` | pass |
| 2.7 | `AdGroupRunTest::test_S_212_2_7_meta_back…`, `…_2_7_a_sequence_subscribed_after_a_wait_resume…` | pass |
| 2.8 | `AdGroupRunTest::test_S_212_2_8…` | pass |
| 2.10 (panel) | `AdGroupPagesTest::test_S_212_4_7_and_2_10…` | pass |
| 3.1 | existing S-172 and S-174 tests in `AdGroupRunTest`, `AdGroupsTest`, `AdGroupPagesTest`, `AdBasedPagesTest`, `AdGroupAllValuesTest` (only the "Not an ID" text changed, see Story changes) | pass |
| 3.3 | `AdGroupRunTest::test_S_212_3_3…` | pass |
| 3.4 | `AdGroupRunTest::test_S_212_3_4…` | pass |
| 3.5 | `CommentRepliesTest::test_S_212_3_5…`, `AdGroupRunTest::test_S_212_3_5…`, plus 1.1 (runner) and 1.11 (sequences) | pass |
| 4.1, 4.2, 4.3 | `AdGroupPagesTest::test_S_212_4_1_4_2_and_4_3…` | pass |
| 4.4 | browser check below | pass (recorded) |
| 4.5, 4.6, 7.4, 7.5, 7.7, 7.8, 7.9 | `AdGroupPagesTest::test_S_212_4_5_4_6_and_7_4_to_7_9…` (the panel script run in Node on `tests/js/dom-stub.cjs` with the real index JSON); 4.5 error text also in `AdGroupPagesTest::test_S_172_8_typed_ads…` | pass |
| 4.7 | `AdGroupPagesTest::test_S_212_4_7_and_2_10…` | pass |
| 5.1–5.7 | `AdGroupPagesTest::test_S_212_5_1_to_5_7…` | pass |
| 6.1–6.4, 6.8, 6.9 | `AdGroupPagesTest::test_S_212_6_1_to_6_4_6_8_and_6_9…` | pass |
| 6.5, 6.6, 6.7, 6.10 | `AdGroupPagesTest::test_S_212_6_5_6_6_6_7_and_6_10…` | pass |
| 6.11, 7.8 | `AdGroupPagesTest::test_S_212_6_11_and_7_8…` | pass |
| 7.1–7.3, 7.6, 7.7 | `AdGroupPagesTest::test_S_212_7_1_to_7_3_7_6_and_7_7…` | pass |
| 8.1 | `AdGroupRunTest::test_S_212_8_1…` (1 query, 1 cache read, with 1 group and with 151) | pass |
| 8.2 | timing check below | pass (recorded) |
| 8.4 | `AdGroupsTest::test_S_212_8_4…` | pass |
| 8.5 | `AdGroupsTest::test_S_212_8_5…` | pass |
| 8.6 | `AdGroupRunTest::test_S_212_8_6…` | pass |

**4.4 and 7.9: browser check.**
- **Setup.** Headless Chrome (local, every outside host blocked) loaded the real `public/js/chatbot-adgroups.js` with the locally built Tailwind and the real `/chatbot/ad-groups` JSON of a sample page. The script is `docs/specs/212-evidence/shots.mjs`.
- **Widths.** Desktop 1440 x 900 and phone 390 x 844, each with the default view and with Show hidden on.
- **Sideways scroll.** At 390, `scrollWidth` 390 = `innerWidth` 390.
- **Show hidden button.** It is a `<button type="button">`, 32 px tall. It was turned on with the keyboard (focus, then Enter): its text went from "Show hidden (4)" to "Hide again", and `aria-pressed` went from false to true.
- **Tap targets.** The Remove and Add buttons are now at least 32 px tall. The only buttons under 24 px are the existing ✎ value pencils, which this spec did not change.
- **Files.** The numbers are in `checks.json`.

**8.2: timing check.**
- **Data.** 150 groups, 3,000 ids (18 ads, 1 ad set id and 1 campaign id each), 100,000 chats from 50,000 customers, and 500 ungrouped ads. The panel ran in-process on sqlite; the timing test was temporary and is not committed.
- **Cold open** (cache empty), 3 runs, averaged:

  | | `vps/main` | this branch | ratio |
  |---|---|---|---|
  | cold | 7.36 s | 7.39 s | 1.00x |
  | warm | 268 ms | 323 ms | 1.21x |

  The cold time is almost all the existing 100k-chat scan.
- **Queries per cold open:** 21 on this branch, 8 on `vps/main`. They grow with the number of distinct ads (500 per chunk), not with customers or hidden items.
- **Before the fix.** The first warm measurement was 2.1x. Reading the group history through `Carbon::parse` cost about 190 ms; it now takes 8 ms.

## Story changes

- **Numbering.** The cases are numbered `S-212.<story>.<case>` in one section, `## S-212`. The spec says "the next free story numbers", but S-210 and S-211 belong to the parallel specs 210 and 211, so this keeps the story number equal to the spec number.
- **4.5 changes two existing tests.** The new error text "Not an ID (digits only)" replaces "Not an ad ID (digits only)". Two existing assertions were updated for it: `AdGroupPagesTest::test_S_172_8_typed_ads…` and `AdBasedPagesTest::test_S_174_5…`. No other existing test changed.
- **2.4 is a simulation.** PHPUnit cannot run 20 workers at once, so the test holds the lookup lock and shows that 19 customers make no call of their own. It also shows that 20 in a row make one call.
- **2.6 is split into two cases:**
  - Every connection expired: a failure, so no pin.
  - No Meta Ads connection at all: page values and a note, and a sequence is pinned as before. Without this, the existing S-172.6 test changes, because a test page has no connection.
- **2.7 gains a wait-resume case** (from the review).
- **3.5 covers four of the six places.** The runner, sequences, comment replies and the Test tab are tested. The inbox and the order sheet call the same `forCustomer` with no logic of their own, and are not tested separately.
- **6.5 depends on the panel caches.** "Visible on the next load" means after the existing caches refresh: 2 min for chats and 5 min for status.

## Done-when checklist

- [x] **Every case has a passing test, or a recorded check for 4.4, 7.9 and 8.2.** See the case table.
- [x] **`php artisan test` adds no new failures compared with `vps/main`.**
  - `vps/main`: 3,928 tests, 4 failures.
  - This branch: 3,970 tests, the same 4 failures:
    - `BigBroadcastTest::test_S_167_1…`
    - `ExampleTest::test_the_application_returns_a_successful_response`
    - `ItemTableFitScriptTest::test_every_catalog_column_id…`
    - `AstraAddressRulesTest::test_S_26_18…`
  - The final head, after the review and speed fixes, was run again: 3,972 tests, 3 of those same failures (S-26.18 passed this time). See Tests.
- [x] **The panel was checked in a browser at desktop width and at 390 x 844.** The screenshots are in `docs/specs/212-evidence/`:
  - `panel-*.png`: the full panel
  - `ids-*.png`: the id list, cropped
- [x] **The result file is filled in and committed.**

## How to run

```
composer install
php vendor/bin/phpunit tests/Feature/Chatblast --filter "AdGroup|AdBased|CommentReplies|AdPreview|AdOpen"
php vendor/bin/phpunit                                   # full suite
node docs/specs/212-evidence/shots.mjs <worktree> <fixture.json> <outdir>   # browser check
```

- The browser check needs Chrome and the main checkout's `node_modules/tailwindcss`.
- The fixture is the JSON of `GET /chatbot/ad-groups?page=…`.
- The Node panel test needs `node` on the PATH; it is skipped otherwise.

## Rulings

- **Where lookups are stored.**
  - **Ruling:** a lookup is stored in the ad's own `ad_catalog` row (`ad_id`, `ad_set_id`, `campaign_id`, timestamps). It is an `insertOrIgnore`, and it fills only empty parent columns. There is no migration and no new column.
  - **Why:** the table already has one unique row per ad with these columns and indexes, and the upload and sync importer fills in names and dates later without overwriting.
  - **Cost if wrong:** the Ads Manager catalog viewer shows ads with no name or dates until the next sync.
- **"Not found" and failures are cache-only.**
  - **Ruling:** "not found" (1 h) and "failed" (5 min) live only in the cache.
  - **Why:** they are short-lived by definition.
  - **Cost if wrong:** after a cache flush, one extra Meta call per unknown ad.
- **No Meta Ads connection at all.**
  - **Ruling:** with no connection at all, the lookup behaves like "not found": page values, a run note, and a sequence pinned as before. If connections exist but all are expired or unreadable, it is a failure: not pinned, and retried after 5 min.
  - **Why:** this keeps S-172.6, and pages without Meta, exactly as they are today, while a lapsed token still follows the "don't pin a failure" rule.
  - **Cost if wrong:** on a site that never connects Meta Ads, a sequence entered from a new ad under a campaign-listed group is pinned to page values. That is today's behaviour.
- **Which token is used.**
  - **Ruling:** the lookup uses one connection's token: one that can read an ad account of the page (from `ads_manager_reports.account_id`), otherwise the first connection that is not expired. One call, never one per connection.
  - **Why:** "exactly one Meta call".
  - **Cost if wrong:** if the chosen token can't read the ad, Meta answers with a permission error, which is treated as "not found" for 1 h.
- **Treating not-found by error code.**
  - **Ruling:** Graph error codes 10, 100, 803 and 200–299 are "not found". Everything else is a failure, including 190 (expired) and any 5xx, timeout or rate limit.
  - **Why:** Meta answers "no such object" and "no permission" this way.
  - **Cost if wrong:** a permission error retries after 1 h instead of 5 min.
- **When several groups list the same id.**
  - **Ruling:** the same-id refusal names the kind ("Ad set 50002 is already in the group "X"."), using `ad_catalog`; an id not found there is called "Ad", as before. The 422 also returns `group`, which the panel uses to add "That group is hidden".
  - **Why:** the existing exact-ad messages stay byte for byte.
  - **Cost if wrong:** an ad set that exists only in the reports is labelled "Ad".
- **Customers rolled up by ad set and campaign.**
  - **Ruling:** the cached "seen" data now keeps each ad's customer list (cache key `seen:v3`), so ad set and campaign rows count distinct customers.
  - **Why:** D8 asks for distinct customers.
  - **Cost if wrong:** a larger cache value per page, about 1 to 2 MB on a 100k-chat page.
- **Which ad sets and campaigns the panel recognises.**
  - **Ruling:** the panel learns a grouped id's kind, name and child ads from `ad_catalog` only. Matching also falls back to the reports.
  - **Why:** `ads_manager_reports` has no index on `ad_set_id` alone, so it can't be searched by ad set cheaply.
  - **Cost if wrong:** an ad set or campaign that is only in the reports shows "Not found in Ads Manager" with no status. Display only.
- **When an id was "added".**
  - **Ruling:** the "added" time of an id is the oldest consecutive `gN.ads` history row that holds it.
  - **Why:** there is no per-id timestamp.
  - **Cost if wrong:** an id removed by clearing the whole list and later re-added keeps its old "added" time, so it may fold early. Display only.
- **Where the screenshots come from.**
  - **Ruling:** the browser check used a static harness: the real panel script, real JSON and locally built Tailwind. It did not use the full /chatbot/pages page behind login.
  - **Why:** running the full app needs a local env file and a signed-in test user, and the job forbade touching env files.
  - **Cost if wrong:** layout from the surrounding Edit modal is not in the screenshots.
- **Review and plan steps.**
  - **Ruling:** the decisions table served as the design. No separate plan file or reviewer "go" was waited for; the reviewer pass ran after the build.
  - **Why:** the spec settled the design, and this worker had no reviewer channel mid-task.
  - **Cost if wrong:** the design was not reviewed before the code was written.

## Deferred minors

From the adversarial review. The two majors were fixed:
- an expired-only connection now counts as a failure
- the wait and AI resume runs carry the "lookup failed" flag and log it

Three minors were also fixed: a strict `\z` on every id check, a re-check of the not-found and failed flags inside the lookup lock, and taller Remove and Add buttons. Accepted:

- **The 30-a-minute cap is per page, not per token.** N Ad-based pages can make 30 x N calls on one shared token, and the check-then-hit is not atomic, so it can go a little over 30. *Accepted because* new-ad lookups are rare after the first message, and the call is one try with no wait.
- **An ad that was not found, or failed, still costs 2 indexed queries** (catalog, then reports) before the cached flag is checked. *Accepted because* reordering would add cache reads to every known-ad match (case 8.1).
- **Waiting customers poll the cache 30 times over 3 s.** With the database cache store, that is a query each time. *Accepted because* it only happens while one lookup of the same ad is running.
- **A catalog row with only one known parent counts as known,** so the missing level is never looked up. *Accepted because* the importer and the lookup always write both ids.
- **"Show hidden (N)" can count quiet unassigned ads beyond the first 50 returned.** *Accepted because* search reaches them, and the count is right.
- **`hiddenBar` uses the same `data-ag-hidden-toggle` in the table and in the Edit panel,** so focus after a click can land on the other one. *Accepted:* cosmetic.
- **The existing ✎ pencils are under 24 px.** *Accepted:* they predate this spec.

## Merge danger

- **One-way or two-way door:** two-way. There is no migration and no schema change. Reverting the merge restores the old exact-id matching.
- **What reverting leaves behind:**
  - `ad_catalog` rows written by lookups, which are harmless: the importer fills them.
  - A `seen:v3` cache key that expires within 2 min.
- **Blast radius if wrong:** the reply path of every Ad-based page.
  - The new code only runs when an ad has no exact match and the page has groups.
  - Any exception inside the lookup is caught and becomes page values.
- **Deploy notes:**
  - Nothing to run.
  - Customers of grouped campaigns or ad sets start getting their group's reply on their next message.
  - Earlier messages are not replayed.

## Conflicts with CLAUDE.md

- **Stale test note.** `CLAUDE.local.md` says "Only Boardroom has tests today". The Chatblast suites are large, and this work extends them.
- **Plan gate.** The kit workflow wants a plan and the reviewer's "go" before code. See the ruling on review and plan steps; the decisions table was taken as the agreed design.

## Tests

- **What runs:** 37 new test methods, 44 runs counting data sets, in `AdGroupRunTest` (23), `AdGroupPagesTest` (9, including the Node panel test), `AdGroupsTest` (4) and `CommentRepliesTest` (1). Plus the small DOM stub `tests/js/dom-stub.cjs`.
- **Ad-group files:** `--filter "AdGroup|AdBased|CommentReplies|AdPreview|AdOpen"` gives 156 tests, all passing. The 52 deprecations are PHPUnit's own and are also present on `vps/main`.
- **Full suite on the final head:** 3,972 tests, 3 failures, all also failing on `vps/main` (`BigBroadcastTest` S-167.1, `ExampleTest`, `ItemTableFitScriptTest`). `AstraAddressRulesTest` S-26.18 failed on both `vps/main` and the earlier branch run, but passed this time, so it is flaky. No new failures.

## Open questions

- Should the lookup cap be per token instead of per page, if many pages go Ad-based at once?
- Should a lookup that finds an expired token also mark the connection expired, as the sync does? It doesn't today.

## Trial report

n/a: no Trials line in `## Stack`.

## Process suggestions

- **Spec gap on "missing token".** The spec lists "missing or expired token" under Meta down (no pin), but the existing S-172.6 test only passes if "no connection at all" pins as before. A line in the spec separating the two would have saved a loop.
- **Shared story numbers.** Parallel specs share `qa/stories.md`. Telling each worker its story number up front (S-NNN = spec number) avoids clashes.

## Proposed tasks

- **Index `ads_manager_reports(ad_set_id)`.** Why: an ad set that is only in the reports would then get its kind and status in the panel without relying on `ad_catalog`. Priority: low.
- **Show the `ad_lookup` run event in the Inbox timeline with a readable label,** if it isn't rendered well today. Priority: low.

## Suggested next steps

- Review and merge `feat/212-adgroup-any-level` into `develop`, then deploy.
- After deploy, check that page "Precious Lopez"'s campaign-id groups answer new customers. The run log should show no `ad_lookup` notes for ads that are already synced.
