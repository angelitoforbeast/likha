# Result 171: the chatblast setup check every 10 minutes, with a red banner

Status: done. Branch `feat/171-setup-check-every-10-min`, based on 1799620b.

## What shipped

1. **Schedule** (`routes/console.php`, `config/services.php`): `botcake:setup-check --chatblast` every 10 minutes, Asia/Manila, `between(06:00, 23:00)` and `unlessBetween(08:45, 10:30)`, `withoutOverlapping(30)`, `runInBackground`. The window is `services.botcake.setup_check_window` (`from`, `to`, `skip_from`, `skip_to`), with no env var. The 21:30 `--all` run is unchanged. Both bounds are inclusive: the run fires at 06:00 and 23:00; the last run before the burst is 08:40 and the first after it is 10:40.
2. **Page selection** (`Chatblast::sendingPages()`), using the same rule the sender applies before a real send. A page is checked when it has all of `Chatblast::REQUIRED` active (`botcake.page_id`, `token`, `flow.shipping`, `field.message`) and is on at least one campaign that is On. A campaign with no page list covers every page. Ready is not part of the rule, so a page with an X is still checked and its X can clear. When no campaign is On, no page is checked.
3. **No write when nothing changed** (the quiet mode, used only by `--chatblast`). Before this change, every run added a `setup.check` row (its `at` changes) and a `token_status` row (it carries the time). Now:
   - The token status is written only when it changes (ok ↔ invalid).
   - `setup.check` is skipped when:
     - no flow or field ID was rewritten in this run,
     - the previous check has no "Check failed", and
     - the result is the same apart from `at`.
   - A grey "Check failed" is written once and not again while Botcake keeps failing. "token invalid" is always written when it is new.
   - The options lists, flow names, template and delivery baseline were already written only on change (`PageSettings::set` returns `unchanged`).
   - Ready is unchanged on a skip: `byCheck` compares the old `at` with a "changed" time that did not move.
   - The manual Test, Check all and the nightly run behave as before.
4. **Banner** (`ChatblastSetupCheck::banner()`, `layouts/chatblast.blade.php`). A red bar `data-alert="setup-problems"` sits under the spec 169 banner on every Chatbot and Chatblast screen, for the same roles (`$cbChatbotOpen`). It reads "⚠ <page>: <reason>; … +N more — open Page settings".
   - Each page name links to `/chatblast/page-settings?q=<page>`, which opens Page settings filtered to that page with its row open. The end link goes to the unfiltered Page settings.
   - It shows up to 3 pages, sorted by name, then "+N more".
   - The reasons:
     - the check's X list, as the Check column shows it;
     - "token invalid";
     - "Sent N, none delivered (Botcake)" on the current flow.
   - A grey "Check failed" is not shown.
   - The banner is cached for 60 s, so it goes away within a minute of the next passing check.
   - Text is escaped.

No migration, no new table, no new route. Sending, flows, sequences, the Ready rules and the Setup box names are untouched.

## Botcake calls and run time

- Each page costs 3 calls: `custom_fields` (the token test), `flows` and `message_templates`. A `statistics` call is added only when the flow ID changes.
- All of a page's calls go through its shared lane of 2 requests per second, and pages run one after another.
- For 60 chatblast pages that is about 180 calls per run. At roughly 1.5–3 s per page (lane spacing plus Botcake latency), a run takes about 2–3 minutes. The worst case is the 25 s budget × 60 pages ≈ 25 min, which `withoutOverlapping(30)` covers. A slow run is skipped rather than stacked.
- At 21:30 the 10-minute run and the nightly `--all` run start together. They share each page's lane, so each takes longer. Both write through `PageSettings::set`, which locks the row, and the second writer finds the value unchanged.

## Deploy notes

- No migration.
- Run `php artisan view:clear` (the layout changed).
- If config is cached on the server, run `php artisan config:cache` (new key `services.botcake.setup_check_window`).
- The scheduler picks up the new entry on its next `schedule:run`. Nothing changes in supervisor or the queue.
- The same commits apply to Incepxion: same codebase, no Likha-only file touched.

## Case table

| Case | Test | Result |
|---|---|---|
| S-171.1 | `SetupCheckEveryTenMinutesTest::test_S_171_1…` (08:50, 10:40, 23:30, 05:50, 06:00, 08:40, 21:30) | pass |
| S-171.2 | `SetupCheckEveryTenMinutesTest::test_S_171_2…` | pass |
| S-171.3 | `SetupCheckEveryTenMinutesTest::test_S_171_3…` | pass |
| S-171.4 | `SetupProblemsBannerTest::test_S_171_4…` (3 tests) | pass |
| S-171.5 | `SetupProblemsBannerTest::test_S_171_5…` | pass |
| S-171.6 | `SetupProblemsBannerTest::test_S_171_6…` | pass |
| S-171.7 | `ChatblastSetupCheckTest` (19), `PageProblemsTest` (8), `tests/Feature/PageSettings` (140), full done-when run | pass |
| S-171.8 (P2) | not tested in a browser | not run |

Done-when run: `phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings`.
- Baseline at 1799620b: 1462 tests, OK (1 skipped).
- After this work: 1476 tests, OK (1 skipped, 60 PHPUnit deprecation notices from the `@dataProvider` docblock style, the same kind as before). No new failures.

## 390 px (P2)

Not checked in a browser: there is no running app with data in this session. The banner is the spec 169 bar with `break-words` and inline text, so it wraps inside the width. With both bars shown they stack, each one compact (`py-1.5` on the new one).

## Review

`skeptic-reviewer`, standard depth:
- **1 major, fixed:** link each page to Page settings filtered to it (`?q=`).
- **Minor fixed:** the escaping of Botcake text is now tested.
- **Other minors accepted** with reasons in `TODO.md`. The one staff will notice: the "Checked <time>" in the Check column stays at the last change, not the last 10-minute run, because decision 3 forbids a new row when nothing changed.

The review found no case where a skipped write leaves a page wrongly Ready or wrongly not Ready.

## Story changes

S-171 added to `qa/stories.md` from the brief's cases. Two additions:
- .1 also tests 05:50, 06:00, 08:40 and 21:30 (the window's edges).
- .3 also covers "Botcake fails twice → written once".

## Notes and suggestions for the owner

- The banner lists any page whose latest check has an X, including pages no campaign sends for: the brief says "any page". Such a page is checked only at 21:30, so its X stays until the next night or a Test. If that turns out noisy, the banner could be limited to the sending pages.
- During development, a missing model import made the unchanged run throw, and the error was counted as "no answer". It was caught before review, and test S-171.3 now asserts that the run itself passes.

171: done. The setup check runs every 10 minutes (06:00–23:00 Manila, not 08:45–10:30) on the pages chatblast sends for, writes nothing when nothing changed, and a red banner names each broken page on every Chatbot and Chatblast screen.
