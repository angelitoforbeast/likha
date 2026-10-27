# Brief 169: marketing sees a failing page at once

## Why
On 2026-10-27 Facebook restricted page "Patricia Manalo" from sending at 11:17 ("You are temporarily restricted from sending messages", Botcake HTTP 200, raw code 10 / subcode 1893063 OAuthException "Application does not have permission for this action"); 84+ sends failed before anyone noticed, and follow-ups kept advancing past failed steps. Bot Health (ChatbotHealthController / Services/Chatbot/BotHealth.php, REASONS + LIGHTS) shows site-wide lights only and counts this as "Other". The owner: "gusto ko madali mapansin" — it must be easy to notice.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | New failure reasons in BotHealth's REASON_SQL (and everywhere it's used): `restricted` = "Page restricted by Facebook" (error text "temporarily restricted" or subcode 1893063), `permission` = "Permission error (#2022 / code 10 / #200)", alongside the existing ones (unreachable, botcake, window, timeout, other). | Name the problem. |
| 2 | Health gets a "Pages with problems" table (top of the page): per page, last 60 min — sends, failed, failed % (without "customer not reachable"), the top reason, first/last failure time; red when the page has ≥ 3 `restricted` or `permission` failures in the window, or ≥ 30% failed with ≥ 10 sends; amber at ≥ 10% with ≥ 10 sends. Click → the page's latest 20 failures (time, reason, error text cut to 150 chars, customer link to the Inbox). Thresholds editable with the existing thresholds form. | One look shows which page. |
| 3 | A red banner at the top of every Chatbot/Chatblast screen (the shared layout/nav, ChatblastNav or the layout it uses) while any page is red: "⚠ <page>: <reason> since <time> — open Health". At most 3 pages listed, "+N more". Cached ≤ 60 s so it's cheap. Visible to the roles that see the Chatbot menu. | Owner: easy to notice. |
| 4 | No automatic pause or message sending in this spec (a separate decision). No change to sending, flows or sequences. | Small and safe. |

## Cases (S-169)
P1: .1 a page with 5 "temporarily restricted" failures in the last hour → red row with reason "Page restricted by Facebook", banner shown on Chatbot screens with the page name and since-time; .2 a page with 12 sends, 4 Botcake 500 → red (33%); 12 sends, 2 failed → amber; .3 #551 failures don't count toward failed %; .4 no problems → no banner, table says "No page problems in the last hour"; .5 click a row → its latest failures; .6 non-chatbot roles see no banner; .7 existing Health tests and suites pass. P2: .8 390 px.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` adds no new failures vs 29be5f6a. Result with deploy notes. End with "169: <done|blocked> — <one line>".

## Out of scope
Auto-pause of restricted pages; Messenger/GC alerts; deploying.
