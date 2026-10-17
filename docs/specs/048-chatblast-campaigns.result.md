# Result 048: chatblast campaigns on their own page

## What shipped

Commits on `feat/048-chatblast-campaigns` (from `develop` at 0056d2df):

- `docs: brief for spec 048 chatblast campaigns`
- `docs: plan for spec 048 chatblast campaigns` (`docs/plans/048-chatblast-campaigns.md`)
- `feat: chatblast campaigns with their own page and one sheet of every day's text; build and send per campaign (spec 048)`
- `feat: chatblast auto mode builds and queues campaigns at their send times every five minutes (spec 048)`
- `fix: turning a campaign off frees its skipped rows, auto queues earlier ready rows, statuses outside the usual order beat a stale In Transit copy, stale sheet cells are refused (spec 048)`
- `fix: an unknown J&T status ties with In Transit so the newest copy decides, with tests for the off key, the on-again slot and a stale remove (spec 048)`
- `docs: result and accepted findings for spec 048`

### Tables and migration

One migration, `2026_10_18_400000_create_chatblast_campaigns_table.php`. It is additive and guarded with `Schema::hasTable` / `hasColumn`.

- **New table `chatblast_campaigns`:**
  - `kind`: unique; the key stored in `botcake_messages.kind`.
  - `name`.
  - `statuses`: JSON list of `from_jnts.status` values.
  - `pages`: JSON; NULL means every page.
  - `window_days`.
  - `days`: JSON, days × versions, the same shape as spec 046.
  - `requires_on_delivery`.
  - `mode`: `manual` or `auto`.
  - `send_times`: JSON `HH:MM`, Manila.
  - `active`.
  - `auto_ran_at`.
  - `mode_changed_by`, `mode_changed_at`.
  - `log`: JSON, the last 200 changes, each with who and when (text edits with the old and new text, mode, on/off, settings).
- **`botcake_messages.campaign_id`:** nullable, indexed.
- **Data:** two campaigns are created.
  - "In transit" (`in_transit`): In Transit, window 14.
  - "Delivering" (`delivering`): Delivering, window 7, requires On Delivery.
  - Both are manual, on, with send time 09:00. Their texts come from `app_settings` `chatblast.sequence.<kind>` when saved and valid, otherwise from the old code defaults, which are copied into the migration.
  - Existing rows get their `campaign_id` by kind. `kind` is unchanged, so old rows, dedupe keys and day counts keep working.
  - `app_settings`, `macro_output` and `from_jnts` are not touched. `down()` drops the column and the table.

### How a campaign builds and sends

- **One key per campaign.** A new campaign gets the kind `c<id>`; the two migrated campaigns keep `in_transit` and `delivering`. Everything 046/047 keys on `kind` therefore works per campaign without a second rule:
  - the dedupe key `waybill|kind|day`;
  - one message per waybill per campaign per Manila day;
  - day = sent + 1;
  - stop when the days run out;
  - the stale skip, recheck and re-render.
- **Trigger statuses:** a waybill matches when its furthest `from_jnts` row (the 046 rule for duplicate copies) has one of the campaign's statuses.
  - The "no RTS reason" rule stays for In Transit and Delivering only, as on /jnt status. Statuses such as For Return carry an RTS reason by nature.
  - A status outside the old progress order (Problematic, or anything an upload writes) now ties with In Transit, so between those the newest copy decides. A stale "In Transit" copy no longer wins over a newer Problematic one, and the reverse holds too.
  - When the waybill leaves the statuses, its unsent row is skipped ("Hindi na Problematic/For Return ang waybill (ngayon: …)").
- **Pages:** all, or the chosen list (compared trimmed and case-blind). A page without chatblast settings still builds `no_config`, as before. **Window:** the campaign's own days, by order `ts_date`. `jnt_status` on a row is now the waybill's actual status at build.
- **Requires On Delivery** replaces the old `kind === 'delivering'` everywhere: tracking first at build, the latest scan must be On Delivery at build and at send, a fresh rider before sending. It can only be set when the trigger is exactly Delivering.
- **Off** stops everything for that campaign:
  - build adds nothing for it, and /chatblast's build of that campaign returns 422;
  - `queue()` refuses its rows;
  - a job already queued ends `skipped` ("Naka-off ang campaign");
  - switching off turns its queued rows `skipped` at once;
  - those skipped rows free their dedupe key, so turning the campaign on again builds them anew.
- **Manual:** as today, the buttons on /chatblast. No campaign chosen means "Kunin ang mga bago" builds every campaign that is on.
- **Auto:** `chatblast:auto`, scheduled every 5 minutes. For each campaign that is on and in `auto`:
  - The latest send time `t` of today counts when `t ≤ now < t + 3 h` and there has been no auto run since `t`.
  - It builds that campaign. A build already running means it waits for the next tick.
  - It then claims the slot atomically: `auto_ran_at` is set only if it is still before `t`, and only while the campaign is still on and auto.
  - Then it queues the campaign's `ready` rows inside its window, including rows built earlier that only became ready later.
  - The sender keeps every rule: the per-page daily cap, pause, once per day, status at send, and On Delivery.
  - While paused, nothing runs and the slot is not marked: it runs after Resume within the 3 hours.
  - Switching to auto, or turning an auto campaign back on, does not catch up on send times that already passed.
  - The 3-hour limit keeps a long pause or a dead scheduler from sending in the evening.
- **Switching to auto** asks for confirmation in the page. The API refuses it without `confirm`. It is recorded in `mode_changed_by` / `mode_changed_at`, in the campaign log, and in the Laravel log.

### Scheduler entry and the cron line for Incepxion

`routes/console.php`:

```php
Schedule::command('chatblast:auto')
    ->timezone('Asia/Manila')
    ->everyFiveMinutes()
    ->withoutOverlapping(30);
```

Likha's cron already runs `schedule:run` every minute. Incepxion has no scheduler cron. The release must add this line to the crontab of the user that runs the app, with the app's real path:

```
* * * * * cd /path/to/incepxion && php artisan schedule:run >> /dev/null 2>&1
```

I don't have the server path, so the release fills it in. Adding the line also starts every other scheduled command in `routes/console.php` on Incepxion. Before adding it, check that each of them should run there (night import, holds snapshot, Botcake sync — the sync is gated by `BOTCAKE_SYNC_ENABLED`).

### The campaigns page and the flat sheet

`/chatblast/campaigns`. It is linked from /chatblast ("Campaigns at mga mensahe") and links back. Access is NavAccess `/chatblast`, like /chatblast.

- **Campaigns list:** name, trigger statuses, pages, window, send times, mode (button; switching to auto asks "Gawing AUTO …?"), an on/off checkbox (switching off asks first), today's built / sent / failed, edit and remove. Remove only works for a campaign without messages; otherwise turn it off.
- **Form (new / edit):**
  - name;
  - trigger statuses, as checkboxes of the statuses present in `from_jnts`, cached 10 minutes, plus the campaign's own;
  - pages, as checkboxes of the pages with chatblast settings (none checked means all);
  - window;
  - send times;
  - Requires On Delivery;
  - for a new campaign, the Day 1 · A text.
  - A new campaign is always manual and on.
- **The sheet:** one row per campaign, day and version.
  - Columns: Campaign | Araw ("Day 1") | Bersyon (A, B, …) | Laman | Preview | Naipadala.
  - **Laman** is a textarea that saves on blur or Enter (Shift+Enter adds a new line). It shows Saving… / Saved (N mensahe ang nabago) / the error, and text typed while a save is running is not lost.
  - **Preview** uses a sample order.
  - **Naipadala** counts sent messages of that day and version.
  - A filter by campaign; "+ bersyon" (a copy of A), "+ araw" on the last day (a copy of the last day's A), and "alisin" on every row.
  - Each save sends the text it saw. If another tab changed or moved the cell, the save is refused ("Nabago na ang cell na ito … i-reload").
  - Every change is validated: non-empty, at most 1000 characters, known placeholders, at most 30 days and 10 versions. It is saved under a row lock, logged, and re-renders the unsent rows as before.
- **/chatblast:**
  - the "Uri" filter is now "Campaign" (names);
  - the collapsed editor is gone, which also removes its wrong note about `{rider}`;
  - the Test PSID box stays under "Test PSID";
  - the side panel shows the campaign name;
  - the `/chatblast/sequence` endpoint is removed.

## Tests

| Case | Test | Result |
|---|---|---|
| Migration: the saved In Transit texts and the default Delivering text become two manual, on campaigns with their windows and On Delivery rule; old rows linked, `kind` unchanged; the sequence now reads from the campaign | `ChatblastCampaignMigrationTest::test_the_two_kinds_become_two_manual_campaigns_with_their_texts_and_their_rows` | pass |
| CRUD and validation: a new campaign is `c<id>`, manual, on; no pages means all; refused: empty name, no status, a status not in `from_jnts`, a bad time, window 0, empty text, unknown placeholder, On Delivery with another status; mode is ignored on create | `ChatblastCampaignTest::test_a_new_campaign_is_manual_and_on_and_bad_input_saves_nothing` | pass |
| Auto needs `confirm`; logged (who, when, old/new); bad mode or on/off refused; off skips the queued rows and frees nothing sent; an off row can't be queued; /chatblast build of an off campaign → 422; the campaign's own vanished status still saves | `…::test_switching_to_auto_needs_confirmation_and_is_logged_and_switching_off_stops_the_queued_rows` | pass |
| One cell: text saved trimmed, log has old and new, the unsent row re-rendered, the sent row untouched; refused: empty, unknown placeholder, a missing day or version, a stale `old` | `…::test_one_cell_saves_its_text_logs_the_old_one_and_rewrites_the_unsent_rows` | pass |
| Add day / version (copies), remove a version, the last version removes the day, the last day stays | `…::test_days_and_versions_are_added_and_removed_but_the_last_day_stays` | pass |
| Delete only without messages | `…::test_a_campaign_with_messages_cannot_be_deleted` | pass |
| Data: today's built / sent / failed per campaign (tests and older days not counted); one sheet row per day and version with Naipadala; the page renders with the link back | `…::test_the_data_shows_todays_counts_per_campaign_and_one_sheet_row_per_day_and_version_with_its_sends` | pass |
| Access: a checked role reaches the data; another role gets 404 on every route, and nothing changes | `…::test_only_the_ceo_and_a_checked_role_reach_the_campaigns` | pass |
| Build by trigger statuses (For Return with an RTS reason included, In Transit not); `jnt_status` and `campaign_id` on the row; the waybill leaving the statuses is skipped | `ChatblastCampaignBuildTest::test_a_campaign_takes_the_waybills_in_any_of_its_statuses_and_skips_them_when_they_leave` | pass |
| Pages filter (trimmed, case-blind) and window | `…::test_only_the_chosen_pages_and_orders_inside_the_window_are_taken` | pass |
| Two campaigns on one waybill: one row each per day, their own day count, stop when the days run out | `…::test_two_campaigns_on_one_waybill_each_send_once_a_day_with_their_own_day_count` | pass |
| Off: no build, a queued job is skipped and frees its key, nothing goes to Botcake; on again → a new row | `…::test_an_off_campaign_builds_nothing_and_its_queued_row_is_not_sent` | pass |
| A newer Problematic copy beats a stale In Transit copy, and a newer In Transit copy beats a stale Problematic one | `…::test_a_status_outside_the_usual_order_still_beats_a_stale_in_transit_copy` | pass |
| A new campaign with Requires On Delivery tracks (fake J&T), takes only the On Delivery waybill, with the rider | `ChatblastTrackTest::test_a_new_campaign_that_requires_on_delivery_tracks_and_takes_only_on_delivery_with_the_rider` | pass |
| Auto at 09:00 and 15:00: nothing at 08:59; built and queued at 09:00; not again at 09:05; 15:00 takes the new waybill; Day 2 the next day; another campaign's ready row is not taken | `ChatblastAutoTest::test_an_auto_campaign_builds_and_queues_at_each_send_time_once_a_day` | pass |
| Nothing for off, manual, more than 3 h late, auto switched on after the time; paused and busy are not marked and run later within 3 h | `…::test_nothing_runs_when_off_manual_paused_busy_too_late_or_switched_on_after_the_time` | pass |
| A row built earlier that became ready is queued by the next run | `…::test_a_row_built_on_an_earlier_day_that_became_ready_is_queued_by_the_next_run` | pass |
| The scheduler runs `chatblast:auto` every five minutes | `…::test_the_scheduler_runs_it_every_five_minutes` | pass |
| 046/047 rules for delivering and in_transit (now campaigns) | the existing `ChatblastBuildTest`, `ChatblastSendTest`, `ChatblastSafetyTest`, `ChatblastSequenceTest`, `ChatblastTrackTest`, `ChatblastPageTest` | pass |

Red runs:

- **Red first:**
  - migration: "File does not exist … create_chatblast_campaigns_table.php";
  - auto: "The command "chatblast:auto" does not exist" (twice) and "null is not null" (no schedule entry);
  - fix loop 1:
    - "`'WB-1|in_transit|1'` is not null" (the off skip kept the key);
    - "`[]` is identical to `['WB-1']`" (Problematic lost to In Transit);
    - "`'ready'` is identical to `'queued'`" (auto skipped an earlier row).
- **Written with the code:**
  - The CRUD / sheet tests and the build-by-campaign tests were written in the same slice as the code, not watched red first. The first run of the CRUD tests failed only on test mistakes (`end()` on a cast attribute, key order) and on the missing view.
  - The validation lines added in the fix wave (bad mode or on/off, stale `old`, On Delivery with another status, build of an off campaign, a vanished own status) were also not run red.
- **Test mistakes, corrected:**
  - a test method named `run()` (final in PHPUnit);
  - the array cache lock expired across `travelTo`;
  - `queue()` ran the job synchronously before the test turned the campaign off.
- **Moved tests:** the 046 editor tests that posted to `/chatblast/sequence` now call `Chatblast::setSequence()` (the same validation). /chatblast's buttons test now checks a build of an unknown campaign instead of the removed endpoint.

Full suite (`vendor/bin/phpunit`) on the final tree: `Tests: 1483, Assertions: 17419, Errors: 1, Failures: 1, PHPUnit Deprecations: 11, Skipped: 3`. The only reds are the known `ExampleTest` and, in this fresh worktree, `ImportStartTest`. The Chatblast folder alone: 97 tests, 535 assertions, all pass.

Other checks: `php -l` passes on every changed PHP file, and `artisan view:cache` compiles both views.

## Review

- **One adversarial `skeptic-reviewer` pass on the whole diff, then one scoped re-check** (one fix loop).
- **Majors (all fixed in loop 1):**
  - **Turning a campaign off kept the dedupe key** on the rows it skipped, so turning it back on never built those waybills again. The controller's off path and the sender's off skip now clear it.
  - **Auto queued only rows built today.** A row built earlier that became `ready` later (for example a PSID matched overnight) was never sent by auto, and its key blocked a new row. Auto now queues the campaign's ready rows inside its window; the sender still enforces once a day and the status.
  - **A status outside the old progress order ranked below a stale "In Transit" copy,** so a Problematic campaign never matched and the In Transit campaign kept sending. It first ranked like Delivering. After the re-check warned that an early-stage status written by an upload would then beat a real In Transit, it now ties with In Transit, so the newest copy decides both ways. Both directions are tested.
- **Minors fixed:**
  - a stale sheet cell is refused (`old`);
  - typing during a save is kept and saved after it;
  - Requires On Delivery is only allowed with a Delivering trigger;
  - an auto campaign turned back on doesn't catch up on missed send times;
  - `chatblast:auto` skips quietly before the migration;
  - the `MAX_TOTAL` comment;
  - the auto test now has another campaign's ready row it must leave alone;
  - tests for an invalid mode or on/off, a build of an off campaign, a vanished own status, the off key, the on-again slot, and a stale remove.
- **Accepted in `TODO.md` under 048, with reasons:**
  - the re-render after the lock;
  - delete without a lock;
  - the table guard only in the command;
  - removing a middle day moves the later days up;
  - `setSequence` used only by tests;
  - the 200-entry log;
  - two missing tests (the 3-hour edge, and a campaign changed between build and claim);
  - the `old` guard is optional for other callers.

## Browser check

Not done: there is no running app with the server database here. To check on the server after release:

- Open /chatblast/campaigns. It should show the two campaigns and the sheet with every day's text (5 rows for In transit, 1 for Delivering).
- Edit a cell and press Enter. The cell should show "Saved", and the preview should change.
- On /chatblast, the Campaign filter should list both campaigns.

## After release (server)

1. **`php artisan migrate --force`** creates the table, adds `campaign_id`, and moves the two kinds into campaigns.
2. **`php artisan queue:restart`**, so the `chatblast` worker loads the new sender.
3. **Incepxion:** add the cron line above, after checking the other scheduled commands. Likha needs nothing, because its scheduler cron already runs.
4. Every campaign starts **manual**. Nothing sends by itself until someone switches a campaign to auto and confirms.

## What the brief got wrong, and decisions it left open

- **Statuses with an RTS reason:** the 046 rule "no RTS reason" would have made For Return (and similar) campaigns empty. It now applies to In Transit and Delivering only, as on /jnt status.
- **Problematic in `from_jnts`:** the J&T track (047) never writes Problematic to `from_jnts.status`; only the uploaded reports do. A Problematic campaign therefore follows the uploads, not the auto track.
- **Late and missed send times:** the brief didn't say what happens when a send time passes while paused, off, or with the scheduler down. Auto catches up within 3 hours, and never for a time that passed before the campaign was switched to auto or back on.
- **Requires On Delivery on another status** would skip every row, so it is only allowed when the trigger is exactly Delivering.
- **History:** "its history/version" is the campaign `log` (last 200 changes, old and new text), not a second table.
- **"Kunin ang mga bago" with no campaign chosen** now builds every campaign that is on. The old page defaulted to delivering only.
- **Incepxion cron:** adding `schedule:run` there starts every scheduled command in the repo on that server, not only chatblast (see above).
- **Conflict with `CLAUDE.local.md`:** the kit asks for a "go" on the plan before code. The brief said to write the plan first and set every product decision, so I committed the plan and went on.

## Story changes

None. The brief brought no `qa/stories.md` stories; the cases above come from its Tests list.

## Amendment 1

### Amendment changes (kept in the handoff folder, not committed)

- **A per-page "Flow at template" panel on /chatblast/campaigns.** For every page with Botcake settings it shows:
  - the name of its `flow.shipping`;
  - the approved template the flow uses;
  - the name of its `field.message`;
  - a full preview of what the customer receives.
  - Botcake answers are cached per page for 10 minutes, with a Refresh button. Errors are shown, never the token. The UI notes that Botcake's API does not return a flow's inner steps.
- **"suriin" flag:** a page is flagged when its flow name has no "CHATBLAST" or its template has no `{{1}}`.

### What changed

- **`App\Services\ChatblastFlowInfo`:** three GETs per page, with no retry and 10 s each, under the page token's shared rate key: `/pages/{id}/flows`, `/custom_fields` and `/message_templates`.
  - The flow and the field are found by id anywhere in the answer, because the flows are nested in folders.
  - Templates are read from `components` (type BODY) or from `body`.
  - **The template:** the page setting `botcake template.shipping` when set (a free-text key, so no page-settings change). Otherwise it is the one APPROVED template whose name (compared case-, space- and `_`-blind) contains, or is contained in, the flow name. No match, or more than one, means "hindi matukoy".
  - **The preview:** the template BODY with `{{1}}` replaced by Day 1 · A of the first campaign that is on and sends to that page, rendered with a sample order.
  - **Cache:** 10 minutes per page; an answer with an error is kept 60 s.
- **"suriin" flags:**
  - the flow name has no CHATBLAST (or the flow wasn't found);
  - the template can't be identified;
  - the template has no `{{1}}`;
  - the template isn't APPROVED;
  - the `field.message` isn't in Botcake's custom fields.
  - The last two came from the review: a card with either problem showed "OK".
- **Route `GET /chatblast/campaigns/flow?page=…&refresh=1`:** NavAccess `/chatblast`, its own throttle (30 a minute). It only accepts pages that have chatblast settings; any other page gets 422.
- **The panel:** a collapsed "Flow at template (kada page)" box. On first open it loads the pages one at a time. Each card shows OK or suriin with the reasons, then Flow, Template (name · status · category, and whether it came from the setting or the name), Field, the errors, the time fetched, Refresh, and "Makikita ng customer" with the full preview. Every Botcake string is shown as text.

### Tests (amendment 1)

| Case | Test | Result |
|---|---|---|
| Flow name found in nested folders, template by name (APPROVED, UTILITY, BODY with `{{1}}`), field name, full preview with the In transit Day 1 text, no flags, no token; cached (3 calls), Refresh calls again | `ChatblastFlowInfoTest::test_the_flow_its_template_and_the_field_are_shown_with_the_full_preview_and_cached` | pass |
| A flow without CHATBLAST, a template from the page setting without `{{1}}` and PAUSED: three flags | `…::test_a_flow_without_chatblast_or_a_template_without_the_slot_is_flagged_and_a_page_setting_picks_the_template` | pass |
| Botcake 401: the error "HTTP 401" is shown, flagged, no token | `…::test_a_botcake_error_is_shown_without_the_token_and_flagged` | pass |
| A missing `field.message` is flagged; the sample comes from a campaign that sends to the page | `…::test_a_missing_field_is_flagged_and_the_sample_comes_from_a_campaign_that_sends_to_the_page` | pass |
| A page without chatblast settings gets 422; another role gets 404; no call to Botcake | `…::test_a_page_without_chatblast_settings_or_another_role_gets_nothing` | pass |
| The campaigns page shows the panel | `ChatblastCampaignTest::test_the_data_shows_…` (`assertSee('Flow at template')`) | pass |

- **Red first:** the four first tests failed with "404 is identical to 200 / 422" (no route).
- **Written after the code:** the review's two extra flags and the page filter of the sample were fixed first, then tested, so those tests were not seen red.
- **Mistake, fixed:** `{{ '{{1}}' }}` broke the Blade view. It is now `@{{1}}`.

Full suite on the final tree: `Tests: 1488, Assertions: 17448, Errors: 1, Failures: 1, PHPUnit Deprecations: 11, Skipped: 3`. The only reds are the known `ExampleTest` and `ImportStartTest`. `php -l` passes on the changed PHP files and `view:cache` compiles.

### Review (amendment 1)

- **One standard-depth `skeptic-reviewer` pass: no blocker or major.**
- **Minors fixed:**
  - a field error and a non-approved template from the setting showed "OK";
  - the sample ignored the campaign's pages;
  - a refused 200 read "HTTP 200".
- **Accepted in `TODO.md` under 048, with reasons:**
  - id matching at any depth;
  - Botcake's real answer shapes are not checked here;
  - five missing tests;
  - the sample text is cached with the answers.
- **Not done:** the reviewer asked for `qa/stories.md` cases for the amendment. The brief brought none, as for the main spec, so the cases above come from the amendment's table.

### After release, updated by amendment 1

- **No migration and no new setting.**
- Open the panel for one page. Check that the flow and the template are the right ones, and that the shapes Botcake really returns are read; if a card says "hindi matukoy", set `botcake template.shipping` for that page in /page-settings.
- If the panel is empty or wrong for every page, Botcake's answers have a different shape than assumed, and the parser needs one fix.
