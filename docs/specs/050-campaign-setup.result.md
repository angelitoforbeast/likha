# Result 050: chatblast messages and sequences, Botcake-style, with a flexible schedule

Amendment 1 replaced the brief's page layout: the per-campaign setup page and the list-as-cards were dropped. Instead
there are two pages modelled on Botcake: **Mga mensahe** (`/chatblast/campaigns`, like Botcake flows) and **Mga
sequence** (`/chatblast/sequence`, like Botcake sequences). Everything on each is visible at once and edited in place.

## What shipped

Commits on `feat/050-campaign-setup` (from `develop` at 835d89ea):

- `f1a14af4` docs: brief
- `2ecf77eb` docs: plan
- `bdf5a975` feat: weekdays, per-step send times and a per-page daily cap on campaigns
- `e0f02215` feat: auto runs follow weekdays, send times and per-step times; the campaign cap at send
- `b7a66ade` docs: plan for amendment 1
- `4cfe79b0` feat: messages table; each live day becomes a message with exactly its texts
- `0aa54e0d` feat: the messages library and the sequences page, edited in place
- `d1bc36b6` fix: review findings
- docs: result and accepted findings

### Migrations

Two migrations, both additive and guarded (`hasTable` / `hasColumn`):

- **`2026_10_19_100000_add_schedule_to_chatblast_campaigns`** adds three columns to `chatblast_campaigns`:
  - `weekdays` (JSON, ISO 1 = Lun … 7 = Lin; NULL = every day);
  - `step_times` (JSON, one entry per step: NULL = the sequence's times, or a list of `HH:MM`);
  - `daily_cap` (NULL = none).
- **`2026_10_19_100100_create_chatblast_messages_table`:**
  - creates `chatblast_messages` (`name` unique, `versions` JSON, `log` JSON);
  - adds `chatblast_campaigns.step_messages` (the message id of each step);
  - in one transaction, turns each day of each campaign into a message "<campaign> · Day N" with exactly that day's versions. On live that is "In transit · Day 1…10" and "Delivering · Day 1…5", each with its 2 versions.
  - The test checks ₱, curly quotes and newlines survive exactly.
  - It runs once only (it converts only campaigns without `step_messages`).
- **Unchanged:** `days`, `statuses`, `pages`, `send_times`, `mode` and `active`, and every `botcake_messages` row (kind, day, version).

**How the texts stay in step.** The campaign's `days` remains the resolved texts of its step messages. It is rewritten in the same transaction whenever a step changes (`Chatblast::editSteps`, which locks the sequence and its messages) or a message changes (`Chatblast::editMessage`, which then re-syncs every sequence using it). So every build and send rule from 046–048 reads `days` exactly as before.

### Mga mensahe (`/chatblast/campaigns`)

- **Each message is a card** with:
  - its name, editable in place;
  - where it is used ("In transit Day 3", …);
  - its total sends;
  - buttons for + bersyon (a copy of A), kopyahin ("<name> (kopya)") and alisin (refused while a sequence uses it).
- **Each version** has a textarea that saves on blur or Enter (Shift+Enter adds a line), with Saving… / Saved (N mensahe ang nabago) / error marks.
  - It sends the text it saw, and a changed version is refused (stale guard).
  - Text typed during a save is kept.
  - Each version also shows its own send count and a remove button (the last version stays).
- **Live preview** with a sample order. Choose a page in "Preview sa page" and the page's template line (from Botcake, cached 10 minutes) wraps it, with `{{1}}` replaced.
- **Placeholder chips** insert into the last text box.
- **Search** by name or text, and **filter** by sequence (or "Hindi ginagamit").
- **"+ Bagong mensahe"** (name and version A).
- **The "Flow at template" panel** (048-1) moved here.
- Every change is validated: non-empty, ≤ 1000 characters, known placeholders, ≤ 10 versions, and every sequence using the message must still fit. It is logged with who, when, and the old and new text.

### Mga sequence (`/chatblast/sequence`)

- **One block per sequence, all on one page**, titled "Sequence for <name>" (name editable). Each block shows:
  - on/off;
  - the mode (switching to auto keeps the 048 confirmation, with the ready count and the backlog warning);
  - remove;
  - the plain-language summary ("Araw-araw, 9:15 AM at 3:00 PM; Day 1 tuwing 10:00 AM"), with the next run or "mano-mano";
  - today's built / sent / failed / skipped.
- **Trigger:** J&T status checkboxes (from `from_jnts`), page checkboxes with a search box (none = all), the window, and Requires On Delivery.
- **Schedule** (greyed when manual): send times as chips (add / remove), weekdays Lun–Lin, and the cap per page per day.
- **Steps:** a vertical chain Day 1 → Day N. Each step has:
  - a message picker, with the message's versions shown inline;
  - its own time chips (empty = the sequence's times);
  - today's built / sent / failed / skipped;
  - ↑ / ↓, "+ step pagkatapos" (same message, then pick another) and alisin (the last step stays).
  - Every step action sends what the page saw (the step count and that step's message), and a stale page is refused.
- **"+ Bagong sequence":** name, statuses, window and the Day 1 message. It starts manual and on.
- **The overlap warning** (048-2) lives here.
- **Every field saves on its own** (change / blur / Enter) with its own mark, and is logged.

/chatblast now links to both pages.

### How auto runs pick times, weekdays and per-step times

`chatblast:auto` (Incepxion: cron every 5 minutes; Likha: the scheduler):

- **A sequence's times:** its send times plus every step's own times.
- **A time `t` is due** when:
  - today is one of the chosen weekdays (none chosen = every day);
  - `t ≤ now < t + 3 h`;
  - `t` is later than the last auto run (`auto_ran_at`).
  - `auto_ran_at` is also stamped on switching to auto, on turning the sequence back on, and when an edit adds a time or weekday that already passed today, so those never fire late.
- **Which steps are due:** a step whose own times (or else the sequence's times) contain a due time.
- **The run** builds rows for the due steps only, then claims the slot. The claim stamps `auto_ran_at` with the latest due time, so a time that comes due during the build still runs. It then queues the ready rows of the due steps that were built today after the switch to auto.
  - A step with its own time is therefore built and sent at that time only, and each time runs once per day.
- **Unchanged from 048:**
  - pause, off and manual mean nothing runs;
  - the backlog rule;
  - the 3-hour limit;
  - one message per waybill per status per day;
  - the stop rules;
  - the J&T check for delivering;
  - the page cap.
- **The sequence's `daily_cap`** limits its own messages per page per Manila day, on top of the page cap. A capped row is `skipped` and frees its key, so it is sent again the next day.

## Tests

| Case | Test | Result |
|---|---|---|
| Schedule columns keep both live-like campaigns exactly (10×2, 5×2); defaults NULL | `ChatblastCampaignMigrationTest::test_the_schedule_columns_keep_the_live_campaigns_exactly_and_default_to_every_day` | pass |
| Each live day becomes a message "<campaign> · Day N" with exactly its texts (₱, quotes, newlines); `days` and rows unchanged; runs once | `…::test_each_day_of_the_live_campaigns_becomes_a_message_with_exactly_its_texts` | pass |
| Auto only on the chosen weekdays (Sunday no, Monday yes) | `ChatblastScheduleTest::test_auto_runs_only_on_the_chosen_weekdays` | pass |
| A step's own time: 09:00 builds and sends Day 1 only; 15:00 Day 2; not again at 15:05; 18:00 the second campaign time | `…::test_a_step_with_its_own_time_is_built_and_sent_at_that_time_only` | pass |
| Next run and summary follow mode, weekdays, times and step times; null when manual or off | `…::test_the_next_run_and_the_summary_follow_mode_weekdays_times_and_step_times` | pass |
| The campaign cap per page skips the rest of the day, frees the key, and the next day builds again | `…::test_the_campaign_cap_per_page_skips_the_rest_of_the_day` | pass |
| One version saves in place (trimmed, logged with old/new); both sequences using it follow; unsent rows rewritten, sent untouched; bad input refused | `ChatblastMessageTest::test_one_version_saves_in_place_logs_the_old_text_and_every_sequence_using_it_follows` | pass |
| Versions removed (stale refused, the last stays); a used message can't be deleted | `…::test_versions_are_removed_but_one_stays_and_a_used_message_cannot_be_deleted` | pass |
| Create (validation, unique name), rename, duplicate, delete when unused; log | `…::test_messages_are_created_renamed_duplicated_and_deleted_when_unused` | pass |
| Data: where each message is used, sends per version (tests and other steps not counted) | `…::test_the_data_shows_where_each_message_is_used_and_its_sends_per_version` | pass |
| Access: another role gets 404 on every message route, nothing changes | `…::test_another_role_cannot_see_or_change_the_messages` | pass |
| Weekdays and cap save one by one, validated, logged | `ChatblastSequenceStepsTest::test_the_schedule_fields_save_one_by_one_with_validation_and_a_log` | pass |
| Steps: own times, add after, pick a message, move (with its times), remove; bad input refused; the last step stays; log | `…::test_steps_are_picked_added_removed_and_moved_with_their_own_times` | pass |
| A step change from a stale page is refused and nothing changes | `…::test_a_step_change_from_a_stale_page_is_refused` | pass |
| Moving a step rewrites unsent rows; a new past step time doesn't fire; a weekday change with no new time today changes nothing | `…::test_moving_a_step_rewrites_the_unsent_rows_and_a_new_past_time_does_not_fire` | pass |
| Data per sequence: steps with message, times and today's counts; summary; next run; message list | `…::test_the_data_gives_each_sequence_its_steps_summary_next_run_and_the_message_list` | pass |
| Sequences: create (from text or an existing message), validation, auto confirmation and log, off, send times stamp, overlaps, delete, per-step counts, both pages render, access | `ChatblastCampaignTest` (6 tests, moved from 048 to the new routes) | pass |
| 046–048 rules (build, send, safety, track, auto, flow panel) | the existing Chatblast tests | pass |

Red runs and test notes:
- **Red first:**
  - the setup tests (before the amendment) failed with 404 / 405 (no routes) and "null is identical to [1, 3, 5]";
  - fix loop: the cap test failed on the dedupe key, and the stale test with "Walang ganitong step" instead of the stale message.
- **Written before the code but run only after, so not watched red:**
  - the schedule tests;
  - the two migration tests;
  - the message and sequence-steps tests rebuilt for the amendment.
- **Test mistakes, corrected:**
  - an expected per-step "sent 2" that only held because the count used `updated_at`. The code now counts sent by `sent_at`, and the expectation is 1.
  - a step time equal to the campaign's own 09:00, which was correctly not stamped.
- **Removed:** the setup-page test (the page was dropped) and the 048 sheet / day-text endpoint tests. Their cases moved to the message and sequence tests.

Full suite (`vendor/bin/phpunit`) on the final tree: `Tests: 1536, Assertions: 17928, Errors: 1, Failures: 1, PHPUnit Deprecations: 11, Skipped: 3`. The only reds are the known `ExampleTest` and `ImportStartTest`. The Chatblast folder alone: 122 tests, all pass. `php -l` passes on every changed PHP file, `view:cache` compiles, and `node --check` passes on both page scripts.

## Review

- **One adversarial `skeptic-reviewer` pass on the whole diff, then a scoped re-check** (one fix loop).
- **Majors (fixed):**
  - **M1:** the new campaign cap skip kept the dedupe key, so a capped waybill never got that day or any later one. It now frees the key.
  - **M2:** the step endpoints addressed steps by position with no check of what the page saw. A stale tab could remove or move the wrong step. Each step action now sends the step count and that step's message, and a mismatch is refused.
- **Minors fixed:**
  - the auto claim stamps the due time, not the time the build finished;
  - `editSteps` locks the step messages, which also closes delete-versus-assign;
  - one validator for times;
  - a unique-name race returns 422;
  - the conversion runs in one transaction;
  - a stale test name.
- **Accepted in `TODO.md` under 050, with reasons:**
  - an added past time can swallow an earlier due slot;
  - the re-sync of a shared message across sequences is not atomic;
  - send counts after a message swap;
  - no `hasColumn` guard between deploy and migrate;
  - the campaign cap is not locked;
  - the stale guard is optional for hand-made requests;
  - four missing tests.

## Browser check

Not done: there is no running app with the server data here. To check after release:

- Open /chatblast/campaigns. It should list 15 messages ("In transit · Day 1" … "Delivering · Day 5") with their 2 versions each, texts as before. Edit one and press Enter; it should show "Saved".
- Open /chatblast/sequence. It should show two blocks, each with its steps pointing to those messages.

## After release (server)

1. **Run `php artisan migrate --force` right after the deploy, on Likha and Incepxion.** Until then the chatblast pages, sends and `chatblast:auto` fail. Rows end `failed`, which is safe to resend.
2. **Run `php artisan queue:restart`.**
3. **Cron:** Incepxion keeps `*/5 * * * * cd /var/www/incepxion.pro && php artisan chatblast:auto >> /dev/null 2>&1` as `www-data`. Likha needs nothing.
4. **Check the texts:** compare a few messages' texts with the old sheet. The migration test pins exactness, but a look at live data is cheap.

## What the brief got wrong, and decisions it left open

- **The layout:** amendment 1 replaced the brief's list-of-cards page and the per-campaign setup page with the two Botcake-style pages.
- **The setup work done before the amendment and reworked:**
  - the setup page's endpoints became the sequence endpoints;
  - its per-day text editing moved to messages;
  - its tests were rewritten.
  - The schedule columns, the auto logic and the campaign cap carried over unchanged.
- **"Per-page daily cap override":** read as a cap on this sequence's own messages per page per day, on top of the page cap. It never raises the page cap.
- **Steps with their own time:** a run at a step's time builds rows for that step only. Otherwise rows of other steps would be built after their time had passed and, under 048's backlog rule, never be sent by auto.
- **The sequence title** "Sequence for <name>" is how the page shows the name. The stored names stay "In transit" and "Delivering", so the message names keep matching them.
- **Message names are unique,** so a picker and a search are never ambiguous.
- **`campaign.days` is kept** as the resolved texts rather than read through the messages on every build, so 046–048 code is untouched.
- **Conflict with `CLAUDE.local.md`:** the kit asks for a "go" on the plan before code. The brief said to write the plan first and set every product decision, so I committed the plan and went on.

## Story changes

None. The brief brought no `qa/stories.md` stories; the cases above come from its Tests list and amendment 1.

## Amendment 2

### Amendment changes (kept in the handoff folder, not committed)

1. **"Bawat oras":** a start, an end and every N hours (default 1), stored as a range beside `send_times`. Each expanded time runs under every send-time rule. The summary reads "Bawat oras, 9 AM-9 PM".
2. **Per-day rule unchanged:** In transit and then Delivering on the same day is allowed; nothing else gets a second message.
3. **The migration does not change the live send times.** Mira sets the hourly ranges after release.

### What changed

- **Migration `2026_10_19_100200_add_send_range_to_chatblast_campaigns`:** one nullable JSON column, `send_range` (`{"start":"09:00","end":"21:00","every":1}`). Live rows get NULL; `send_times` is untouched (tested).
- **`ChatblastSchedule`:**
  - `campaignTimes()` = `send_times` plus the expanded range, sorted, no duplicates.
  - `rangeTimes()` expands from start to end within one day, every N hours. The end counts only when it lands on the step (5 AM to 4 PM every 2 hours gives 5, 7, … 3 PM). It returns nothing for an invalid range or a step under an hour.
  - `invalidRange()`: start and end must be `HH:MM`, the end after the start (no crossing midnight), and every a whole number from 1 to 12.
  - A step without its own times uses the sequence's times including the range. Due times, the next run, the auto run and the "new past time doesn't fire" stamp all go through the same times, so weekdays, the 3-hour limit, pause, off, the caps, the backlog rule and one message per waybill per status per day apply unchanged.
- **Summary:**
  - "Araw-araw, Bawat oras, 9 AM-9 PM";
  - "Bawat 2 oras, 5 AM-3 PM" (shows the last real time);
  - with a list as well: "8:00 AM at 10:30 AM; Bawat oras, 9:30 AM-11:30 AM".
- **Sequence update (`send_range`):** a missing or null `every` means 1, a whole-number string is accepted, anything else is refused, and `null` clears the range. The change is logged as a settings field.
- **Page:** a "Bawat oras" row in each sequence's schedule: on/off, every N hours, from and to. It saves on change with its own mark, and turning it off clears the range.

### Tests (amendment 2)

| Case | Test | Result |
|---|---|---|
| Expansion with an inclusive end (13 times for 9–21), every 2 hours, start minutes kept, merged with the list; summary text | `ChatblastScheduleTest::test_an_hourly_range_expands_with_an_inclusive_end_and_reads_as_bawat_oras` | pass |
| Auto fires hourly inside the range only (nothing at 08:59, 09:00 / 10:00 / 11:00 yes, 10:05 no, 12:00 and 13:00 no) | `…::test_auto_fires_hourly_inside_the_range_only_and_once_per_hour` | pass |
| A late tick inside the range runs once for the missed hours and stamps the latest | `…::test_a_late_tick_inside_the_range_runs_once_for_the_hours_it_missed` | pass |
| In transit sent, then Delivering the same day is allowed; a second Delivering sequence is skipped | `…::test_a_waybill_messaged_in_transit_can_get_the_delivering_message_the_same_day_but_nothing_more` | pass |
| Range saves and clears, with validation; a range added at 14:10 doesn't fire 14:00, and the next run is 15:00 | `ChatblastSequenceStepsTest::test_the_hourly_range_saves_validated_and_clears` | pass |
| The migration leaves the live send times as they are (`send_range` NULL) | `ChatblastCampaignMigrationTest::test_the_schedule_columns_keep_the_live_campaigns_exactly_and_default_to_every_day` | pass |

Red runs:
- **First run:** the expansion, hourly-auto and save tests failed for the right reasons (no range support).
- **Fix loop:** "null is saved as 1" and the summary end ("4 PM" instead of "3 PM").
- **Already green when written:** the In transit → Delivering test and the late-tick test. They pin 048 behaviour that already held.

**The full suite was being cut off, now fixed.** The suite had stopped at "Maximum execution time of 55 seconds exceeded": the build and flow endpoints call `set_time_limit(55)` inside the single PHPUnit process, and on Windows that counts wall-clock time. The chatblast test base now resets the limit after each test (test-only).

Full suite on the final tree: `Tests: 1541, Assertions: 17971, Errors: 1, Failures: 1, PHPUnit Deprecations: 11, Skipped: 3`. The only reds are the known `ExampleTest` and `ImportStartTest`. The Chatblast folder alone: 127 tests, all pass.

### Review (amendment 2)

- **One scoped `skeptic-reviewer` pass, then a re-check:** no blocker or major remains.
- **Major (fixed):** an explicit `every: null` passed validation and the expansion looped forever. A hand-made request could have killed the sequences page and stalled `chatblast:auto`. `every` now must be a whole number (missing or null means 1), and the expansion refuses a zero step on its own.
- **Minors fixed:**
  - the summary shows the last real time;
  - tests for a range added mid-day, a late tick, `every` over 12, and a numeric string.
- **Accepted in `TODO.md` under 048/050, with reasons:**
  - rows skipped in a way that frees their key are rebuilt and skipped again each range hour;
  - no `hasColumn` guard for `send_range`.

### After release, updated by amendment 2

- **`php artisan migrate --force` right after the deploy.** The new column is read by the sequences page and `chatblast:auto`, which fail until it exists.
- **Live times are unchanged.** To set the owner's practice, on each site's /chatblast/sequence:
  - Delivering: "Bawat 1 oras, mula 05:00 hanggang 16:00".
  - In transit: "Bawat 1 oras, mula 09:00 hanggang 21:00".
  - Remove the old 09:00 from the list if it is no longer wanted.
  - Then switch to auto (the confirmation shows the ready count; the backlog is not sent).
