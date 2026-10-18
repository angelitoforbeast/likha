# Result 055: the chatblast pages in English

## What shipped

Commits on `feat/055-chatblast-english` (from `main` at 98e1ea66):

- `b4b029f6` docs: brief
- `ea0efd3c` docs: plan (`docs/plans/055-chatblast-english.md`)
- `a4a41eac` feat: the pages, messages, errors, row reasons and schedule summary in English; rows with the old reasons still match
- `7d9ebe50` test: the three pages and a validation error are English; rows with the old reasons behave the same
- `cdb60757` fix: the review's leftovers (close button, the "all" chip, "tries", "(copy)", "Botcake: no answer")
- docs: result and TODO
- `c4367279` fix: recheck leaves old rows whose stored reason is the Tagalog form of the same reason alone (independent review)
- docs: result corrected for that review

No migration, and nothing rewrites stored rows for the language. A stored Tagalog reason is replaced only when the row's state really changes, as before (for example a `no_config` row whose missing settings change), and then by the English text. The first version of this branch did rewrite old `no_psid` / `no_config` rows on the first "Fetch new"; the independent review caught it and it is fixed (see below). Code comments and `Log::` lines are unchanged.

## Pages and strings changed

| File | Strings changed (about) | What |
|---|---|---|
| `resources/views/chatblast/index.blade.php` (/chatblast) | 55 | Title links (Messages, Sequences), filters (From, To, Sequence, All, Search, Show), buttons (Fetch new, Send all ready), table headers (Time, Message, Tries, Sent, …), statuses (no PSID, no config, unsure), the state line, notices, confirmations, the side panel (Sequence, Step, Tries, Last HTTP, Created, Queued, Sent, Send steps, Response, Close), the Test PSID box, the pager |
| `resources/views/chatblast/messages.blade.php` (/chatblast/campaigns, Messages) | 49 | Title and intro, search and filters, placeholders line, "+ New message", the flow panel text, the cards (version, duplicate, remove, "sent (last 30 days)", "Used in", "What the customer sees", "not saved yet"), notices and confirmations |
| `resources/views/chatblast/sequence.blade.php` (/chatblast/sequence, Sequences) | 53 | Title and intro, "+ New sequence", Mon–Sun, the block (Sequence for, on, mode, the AUTO confirmation with the backlog warning, remove sequence), Trigger, Schedule ("Send times", "Every N hours", "Days", "Cap per page / day", "manual: not followed"), Steps (today's built / sent / failed / skipped, "+ step after", "Own times"), notices |
| `app/Http/Controllers/ChatblastController.php` | 9 | JSON errors and the step label ("Day 3 · B") |
| `app/Http/Controllers/ChatblastCampaignController.php` | 29 | Every validation and error message, the stale-page messages ("Reload the page: this changed in another tab."), the overlap warning |
| `app/Http/Controllers/ChatblastMessageController.php` | 14 | Every validation and error message, the "(copy)" name suffix for new duplicates |
| `app/Services/Chatblast.php` | 34 | Validation of days, versions and times; exceptions; the row reasons ("missing {cod}", "no rider info", "the order has no PSID …", "The waybill is no longer In Transit (now: Delivered)", "Page \"X\" is no longer in the sequence", "already sent today by another sequence", "Sequence daily cap reached for the page …") |
| `app/Services/ChatblastSender.php` | 18 | The send reasons ("The sequence is off", "Not sure it was sent (send_flow was called) …", "incomplete text: …", "Page daily cap reached …", "Token invalid: …", the read-back and flow failures) |
| `app/Services/ChatblastSchedule.php` | 12 | Day names Mon–Sun, the range validation, the summary: "Every day, 9:15 AM and 3:00 PM; every hour, 9 AM–9 PM; Day 1 at 10:00 AM", "Manual (times are not followed).", "Off." |
| `app/Services/ChatblastFlowInfo.php` | 11 | The flow panel's checks and errors, the template source ("setting" / "name"), the sample fallback text |

The same terms are used throughout: Messages, Sequences, Step, Version, Trigger, Schedule, Manual / Auto, On / Off, Cap. The /chatblast filter that said "Campaign" now says "Sequence".

**Behaviour is unchanged for old rows.** Four reasons are also compared by code, and each matcher now accepts the English text and the old Tagalog text (`Chatblast::LEGACY_*`):
- the rider reason and the missing-placeholder / incomplete-text reasons (`isTextReason`, which keeps such rows out of the PSID/settings recheck);
- the across-sequences skip and the sequence-cap skip (the build's "taken today" rule).

Nothing else in the code compares a translated string. JavaScript only displays `error` texts, and the flow panel's template source is only shown.

## Left in Tagalog, and why

- **Stored data** stays exactly as it is:
  - message versions and names (for example "In transit · Day 3");
  - campaign days, `botcake_messages.text`, the page template line;
  - change-log entries;
  - the reasons already stored on existing rows.
- **The artisan console output** of `chatblast:build` and `chatblast:auto` is unchanged: it is not on a page, it lands in the cron log, and the brief covers the three pages. Accepted in `TODO.md`.
- **Code comments**, including comments inside the pages' `<script>` blocks (visible only in the page source), and the tests' own description strings, as the brief says.

## Tests

| Case | Test | Result |
|---|---|---|
| /chatblast headings and buttons are English (Fetch new, Send all ready, Retry failed, Messages, Sequences, the empty state, Close) and no old labels remain | `ChatblastEnglishTest::test_the_sending_page_is_in_english` | pass |
| Messages page: heading, "+ New message", "Flow and template (per page)", Placeholders, "sent (last …)", "What the customer sees"; no old labels | `…::test_the_messages_page_is_in_english` | pass |
| Sequences page: heading, "+ New sequence", "Create (manual, on)", Mon, "Every N hours", "Send times", the AUTO confirmation; the summary "Manual (times are not followed). Every day, 9:00 AM" | `…::test_the_sequences_page_is_in_english` | pass |
| Sequence update validation errors are English (Days, Window, Every N hours, Confirm) | `…::test_a_validation_error_from_the_sequence_update_is_in_english` | pass |
| Rows with the old Tagalog reasons still behave the same: the across-sequences and cap skips still block a same-day rebuild, and old and new text reasons are both recognised | `…::test_rows_with_the_old_reasons_still_behave_the_same` | pass |
| Updated expected strings (reasons, summaries, errors, page text) | `ChatblastBuildTest`, `ChatblastCampaignBuildTest`, `ChatblastCampaignTest`, `ChatblastFlowInfoTest`, `ChatblastMessageTest`, `ChatblastSafetyTest`, `ChatblastScheduleTest`, `ChatblastSendTest`, `ChatblastSequenceStepsTest`, `ChatblastSequenceTest`, `ChatblastTrackTest` | pass |

Red runs:
- **The text change itself:** after the translation, 28 existing tests failed on the old Tagalog expectations, and they were updated to the English text.
- **Written after the change:** the five new tests were written after the strings changed, so they were not watched red.
- **Test mistake, corrected:** the Sequences page test first refused "Bawat oras" anywhere on the page. The only one left was a code comment in the script, so it now checks the row label.

Full suite (`vendor/bin/phpunit`) on the final tree: `Tests: 1604, Assertions: 18323, Errors: 1, Failures: 1, PHPUnit Deprecations: 16, Skipped: 3`. The only reds are the known `ExampleTest` and `ImportStartTest`. The Chatblast folder alone: 136 tests, all pass. `php -l` passes on the changed PHP files, `view:cache` compiles, and `node --check` passes on the three page scripts.

## Review

- **One standard-depth `skeptic-reviewer` pass on the whole diff: no blocker or major.** It confirmed:
  - the SQL grouping of the legacy-reason rule is right (`dedupe_key IS NOT NULL OR reason IN (…) OR reason LIKE … OR reason LIKE …`, inside one group);
  - no other code compares a translated string;
  - customer texts and stored data are untouched.
- **Minors fixed:**
  - three Tagalog strings left on /chatblast ("Isara", the "lahat" chip, "subok");
  - new duplicates were named "(kopya)", now "(copy)";
  - "Botcake: HTTP no answer" now reads "Botcake: no answer";
  - the English "incomplete text" reason now has a test.
- **Accepted in `TODO.md`, with reasons:**
  - the console output;
  - a flow-panel result cached just before the release shows "pangalan" for at most 10 minutes;
  - the stale-page messages follow the brief's wording ("in another tab"), although another person may have made the change.

## Browser check

Not done: no running app with the server data here. After release, open the three pages and check that nothing reads in Tagalog except the message texts and stored names.

## After release (server)

Nothing beyond the deploy: no migration, no queue restart needed for the pages. Run `php artisan queue:restart` anyway so the `chatblast` worker writes the new English reasons.

## What the brief got wrong, and decisions it left open

- **"Sequence for In transit" is not a display name built in code.** It is two pieces: the label "Sequence for" next to the editable stored name. The label was already English and the name is data, so neither changed.
- **Row reasons are both UI and data.** They are shown on /chatblast and stored on each row, and four of them are also matched by code. New rows get English; the matchers accept both texts so behaviour is unchanged; old rows keep their stored text.
- **"Campaign" on /chatblast** (the filter and the side panel) became "Sequence", to use the brief's terms.
- **Conflict with `CLAUDE.local.md`:** the kit asks for a "go" on the plan before code. The brief said to write the plan first and set every decision, so I committed the plan and went on.

## Story changes

None. The brief brought no `qa/stories.md` stories; the cases above come from its Tests list.

## Independent review (98e1ea66..932b937b)

No majors. One minor, fixed:

- **`Chatblast::recheck()` compared the new English reason with the stored one,** so every old `no_psid` / `no_config` row (with its Tagalog reason) looked changed. On live Likha that is about 2,700 rows. The first "Fetch new" after release would have updated each one with its own query, inside the web request's 55 s limit, and counted them all as "updated".
- **Fix:** when the status and PSID are the same and the stored reason is the Tagalog form of the same reason, the row counts as unchanged: it is not written and not counted. `Chatblast::legacyReadiness()` maps the two readiness reasons ("missing <keys>" ↔ "walang <keys>", and the no-PSID text ↔ its old text). A row whose state really changed is still written, in English.
- **Regression test:** `ChatblastEnglishTest::test_old_no_config_and_no_psid_rows_with_the_same_reason_in_tagalog_are_not_rewritten_or_counted`.
  - An old `no_psid` row and an old `no_config` row with Tagalog reasons stay untouched (reason and `updated_at`), and "updated" is 0.
  - After the page's settings change, the `no_config` row is written ("missing field.message") and counted.
  - It failed first with "2 is identical to 0".

The Chatblast folder: 137 tests, all pass. Full suite on the final tree: `Tests: 1605, Assertions: 18328, Errors: 1, Failures: 1, PHPUnit Deprecations: 16, Skipped: 3`; the only reds are the known `ExampleTest` and `ImportStartTest`.
