# Result 184: one clickable problems panel per page

Status: done. Branch `feat/184-problems-panel`, based on 9b5b4580 (live 858f3d89 + specs 182 and 183).

## What shipped

1. **One banner** (`layouts/chatblast.blade.php`, every Chatbot and Chatblast screen, `data-alert="problems"`). It replaces the failing-pages banner (169) and the setup banner (171).
   - **Collapsed by default**, one line: "⚠ N pages with problems (U urgent) ▾". Red when at least one page is urgent, grey when every page is ⚪ only.
   - **Click** opens the list in place (no page change). The open or closed state is kept per browser in `localStorage` (`cb-problems-open`), read and written inside `try/catch`; without storage the panel stays closed.
   - **The list:** one row per page, most urgent first, then every current problem of that page as a short line with its colour and its links.
   - **No problems:** no banner.
   - **Who sees it:** the roles with the Chatbot menu (`NavAccess::allows(role, '/chatblast')`), the same check as before.
2. **Service** `App\Services\Chatbot\ProblemsPanel::panel()`. It reuses the existing sources:
   - `BotHealth::problems()` (169, now with `send_light`, `send_reason`, `send_since`: the light, reason and start of the sends alone, so a page that is red only because of unanswered customers does not get a sends line),
   - `ChatEchoes::health()` (183),
   - `ChatblastSetupCheck::pages()` (171; the full list behind the banner, same cache key),
   - the `chatbot.auto_switch` rows of ForbeastWatch (175/183),
   - `ResponseSpeed::pageSeconds()` (182's first-reply measure, per page, for any time window),
   - `Chatblast::sendingPages()` (171's rule for the pages chatblast sends to).
   Every source is wrapped on its own: one that throws is reported and leaves the others shown. If the whole panel throws, the screen shows no banner.
3. **History link** (`chatbot/index.blade.php`): `/chatbot/pages?changes=<page>&field=auto_switch` opens the Changes dialog filtered to that page and the auto-switch rows.

## Severity rules

| Line | Colour | Rule (source) | Links |
|---|---|---|---|
| "<reason>: F of S sends failed since HH:MM" | 🔴 when the page is red for sends, 🟠 when amber | 169's page rules and thresholds, last 60 min | Health (`?page_id=…#page-problems`), Inbox (`?page_id=…`) |
| "N customers not answered in 5 min" | 🔴 | 183: ≥ `unanswered_customers` (3) customers, last 60 min | Inbox on the latest such customer, Health (`#who-answered`) |
| "Moved to Botcake at HH:MM: <reason>" / "Held on Forbeast at HH:MM: <why>" / "Back on Forbeast at HH:MM: …" | 🟠 | ForbeastWatch events of the last 6 h; the latest of each kind per page | History (Changes, filtered) |
| "Slow: median first reply M s in the last hour (usual U s at this hour)" | 🟠 | new; see below | Speed (`?page_id=…`) |
| "N double replies in the last 60 min (A + B)" | 🟠 | 183, any double reply | Inbox on the latest example, Health |
| "Setup: <X>" | 🟠 | 171's X, token invalid or none delivered, on a page chatblast sends to | Page settings (`?q=<page>`) |
| "Not sending, setup incomplete: <X>" | ⚪ | the same, on a page no On campaign sends to | Page settings |
| "No chats in the last 30 min (usually N an hour)" | ⚪ | new; see below | Inbox, Speed |

- **Urgent page:** a page with at least one 🔴 or 🟠 line. ⚪ pages are counted in N but not in U.
- **Order:** pages by their worst colour, then by the number of 🔴 lines, 🟠 lines and all lines, then by name. Lines in a page by colour, in the table's order.
- **Slow** (new): the page's median first-reply time for messages that came in the last 60 min (the Speed screen's measure: on bot only, ignored attachments and Botcake standby copies left out, answered by now) is ≥ `slow_seconds` (60), or ≥ `slow_factor` (3) × its usual. The usual is the median of the same 60-minute window on each of the previous 7 days, pooled. Both need at least `slow_min_replies` (5) answered messages, so one slow customer does not mark a page. A usual of 0 s never triggers the factor rule.
- **No chats** (new): a page whose bot is not Off (and not a test page) that averaged ≥ `no_chats_per_hour` (20) chats in the same 60-minute window over the previous 7 days, and had no chat (standby copies left out) in the last `no_chats_minutes` (30).
- **Setup 🟡:** the brief's 🟡 is never shown on its own: per decision 2 it becomes 🟠 on a sending page and ⚪ otherwise.

## Config keys (also in .env.example)

| Key | Env | Default |
|---|---|---|
| services.chatbot.problems.slow_factor | CHATBOT_SLOW_FACTOR | 3 |
| services.chatbot.problems.slow_seconds | CHATBOT_SLOW_SECONDS | 60 |
| services.chatbot.problems.slow_min_replies | CHATBOT_SLOW_MIN_REPLIES | 5 |
| services.chatbot.problems.no_chats_per_hour | CHATBOT_NO_CHATS_PER_HOUR | 20 |
| services.chatbot.problems.no_chats_minutes | CHATBOT_NO_CHATS_MINUTES | 30 |

No existing threshold changed (169, 171, 183 keep theirs).

## Cache and cost

- The panel's own parts (watcher events, slow, no chats, sending pages) are one cache entry, 60 s. The reused sources keep their own 60 s caches (169 problems, 183 echo health, 171 setup list), so no part is older than 60 s. A cached screen runs no query for the banner.
- The usual (7 days × one hour of chats and replies) is cached 15 minutes per 10-minute slot. Per minute, the uncached work is one hour of chats with their replies (the Speed screen's two indexed queries) and the `page_id` of the chats of the last 30 min (a plain indexed read, counted in PHP: the Chats page's guard test forbids COUNT/GROUP BY/extra DISTINCT on `botcake_chats`).
- No new table, migration, route or job. Sending, flows, chatblast and the watcher are untouched.

## Case table

| Case | Test | Result |
|---|---|---|
| S-184.1 | `ProblemsPanelTest::test_S_184_1_the_collapsed_line_counts_pages_and_urgent_ones_and_the_list_is_ordered_by_severity` | pass |
| S-184.2 | `test_S_184_2_a_page_with_a_setup_x_unanswered_customers_and_a_watcher_move_shows_all_three_with_links` | pass |
| S-184.3 | `test_S_184_3_a_setup_x_on_a_page_chatblast_does_not_send_to_is_white_not_orange` | pass |
| S-184.4 | `test_S_184_4_a_page_much_slower_than_its_usual_at_this_hour_is_slow` (70 s vs 4 s, 12 s = 3×, 11 s not slow, 60 s vs 50 s) | pass |
| S-184.5 | `test_S_184_5_no_chats_fires_only_for_a_bot_on_page_with_its_usual_volume_at_this_hour` | pass |
| S-184.6 | `test_S_184_6_no_problems_means_no_banner_and_it_is_cached_up_to_a_minute` | pass |
| S-184.7 | `test_S_184_7_roles_without_the_chatbot_menu_see_nothing` | pass |
| S-184.8 | `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` | pass: `Tests: 1576, Skipped: 1`, OK, 67 PHPUnit deprecations (baseline 1565, OK, 66; the 11 new tests and one more deprecation notice from the new data provider). No new failures |
| S-184.9 (P2) | `test_S_184_9_the_collapsed_line_stays_one_line_and_the_list_scrolls_on_a_phone` (markup: `truncate`, `max-h-[60vh] overflow-y-auto`, `break-words`) | pass; not run in a browser |

- **Red run:** before the code, 10 of the 11 tests failed because there was no panel (`Trying to access array offset on null`: the helper found no `data-alert="problems"`). S-184.7 passed, because it asserts that no banner is shown.
- **Baseline at 9b5b4580** (only the brief committed): `Tests: 1565, Skipped: 1`, OK, 66 PHPUnit deprecations.
- **First run after the feature:** 1 failure, `BotcakeChatTest::test_S_059_18` (no COUNT/GROUP BY on `botcake_chats`). The usual chat count and the last-30-min read now pluck `page_id` and count in PHP.

## Existing tests changed

Decision 1 replaces the 169 and 171 banners, so their banner tests now read the panel:
- `PageProblemsTest`: S-169.1 expects the panel line ("… 🔴 Page restricted by Facebook: 5 of 5 sends failed since 11:17 Health Inbox") and the Health link with `?page_id=1001`; S-169.6 checks the page name in the panel; S-169.9 becomes "every failing page is listed" (no "+N more" any more) and keeps the 60 s cache check.
- `SetupProblemsBannerTest`: S-171.4 expects "🟠 Setup: …" lines with the filtered Page settings link; "at most three … +N more" becomes "every page is listed"; S-171.6 checks the red page comes before the setup page in the one banner. The escaping test is unchanged and still passes.

## Story changes

- S-184 added to `qa/stories.md` from the brief's cases. S-184.4 has four data sets (the 3× and 60 s boundaries); S-184.6 also checks the 60 s cache; S-184.1 also checks that the old banners are gone and that the state uses `localStorage` inside `try/catch`.

## Review

`skeptic-reviewer`, standard depth, on 8565070d..9a62daee: **no blocker, no major.** Minors:
- **Fixed:** the slow rule now skips test pages (as the no-chats rule and the echo health do); `ChatblastSetupCheck::banner()` keeps its original body (only `pages()` was added); the result's query description was corrected.
- **Accepted in `TODO.md`:** amber 169 pages shown as 🟠 urgent; the History link matches the page by exact name; no cache lock on the 10-minute usual; `pageSeconds` repeats the Speed screen's chat rule (second copy); the two old `banner()` methods have no screen caller now; a list of thin untested branches (double-replies line, held/back labels, amber line, a failing source, slow edges, order tie-breakers, the JavaScript deep link); 390 px checked in markup only.

No browser check: this session has no browser on a running app with data.

## Conflicts

None between CLAUDE.local.md and the spec. Deviations:
- The brief served as the plan's "go"; no separate plan round (the brief's decisions are fixed).
- Amber (169) failing pages show as 🟠 (the brief names 🔴 for failing sends without an amber level); red ones are 🔴.
- 🟡 is never shown: decision 2 turns setup into 🟠 (sending) or ⚪ (not sending).
- The slow rule needs at least 5 answered messages in each window (`slow_min_replies`), which the brief does not mention; without it one slow customer would mark a page.

## Deploy notes

- No migration, no new table, no route, no queue or scheduler change. Files: `app/Services/Chatbot/ProblemsPanel.php` (new), `BotHealth.php`, `ResponseSpeed.php`, `ChatblastSetupCheck.php`, `config/services.php`, `.env.example`, `resources/views/layouts/chatblast.blade.php`, `resources/views/chatbot/index.blade.php`.
- Run `php artisan view:clear` (layout changed), and `php artisan config:cache` if config is cached (new `services.chatbot.problems` keys; the defaults apply without any env).
- No `npm run build` (Tailwind comes from the CDN in this layout; no Vite asset changed).
- After deploy, open any Chatbot screen once and note the time of the first (uncached) load; the usual for the hour is then cached 15 minutes.
- Old cached 169 problem rows have no `send_light` for up to 60 s after deploy: during that minute the panel shows no failing-sends lines (the line is skipped, nothing breaks).
- **Incepxion:** applies unchanged; same codebase and files, nothing Likha-only.

184: done — one collapsed "⚠ N pages with problems (U urgent)" banner on every Chatbot/Chatblast screen opens in place to a per-page list of every current problem (failing sends, unanswered, watcher moves, slow, double replies, setup, no chats) with colours and links; no new table, no threshold change.
