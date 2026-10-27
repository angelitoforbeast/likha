# Brief 166: moving a page to Forbeast doesn't bounce it back to Botcake

## Why
On 2026-10-26 22:05 and again 2026-10-27 10:08–10:10, `ForbeastWatch::inbound()` switched three pages (Jenny De Guzman, Xavia Aquino, Chelsea Mercado) from the Forbeast gateway back to Botcake with "Botcake chats from 2 customers with no Forbeast copy after 120 seconds", although Forbeast had delivered every message in ~1 s. Traced cause (Xavia): a staff member switched the page Botcake → Forbeast at 10:06:40; customers' messages from 10:05:04–10:05:53 had arrived through Forbeast while the page was still on Botcake and were dropped (HandleGatewayEvent ~105–108, "botcake_page", counted only, not stored); Botcake's copies of the same messages arrive 59–78 s after Meta's webhook to Forbeast, so they came after the switch and were stored as `_standby` chats with no Forbeast twin → after INBOUND_GRACE (120 s) with INBOUND_CUSTOMERS (2) the watch switched the page back. Customers waited up to ~140 s. ForbeastWatch never switches back by itself.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | A standby Botcake chat is NOT evidence of a missing Forbeast copy when the page moved to the gateway less than SETTLE seconds before that chat was received (SETTLE = 180 s, a named constant; covers the 60–80 s Botcake lag plus margin). Use the page's provider-switch time from page_settings history (the active `chatbot.provider` row's created_at, or whatever the switch writes) — no new table or column. Such chats are still handled exactly as standby chats are today (replied if no twin per the existing standby rules) — only the switch decision ignores them. | Owner: pages should stay on Forbeast; the alarm was false. |
| 2 | Everything else in ForbeastWatch stays the same (thresholds, connection/customer error rules, never auto-switching back). | Small, safe fix. |

## Cases (S-166)
P1: .1 page switched to gateway at T; Botcake standby chats from 3 customers received at T+30..T+90 with no twin → after 120 s no switch; .2 standby chats from 2 customers received at T+200 and T+210 with no twin → switch as today; .3 a page on the gateway for hours behaves exactly as today (existing ForbeastWatch tests unchanged); .4 standby chats inside the settle window are still answered per the existing standby rules (no customer left unanswered); .5 existing Chatbot/Botcake suites pass.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Unit` adds no new failures vs e7b09402. Result with deploy notes (no migration). End with "166: <done|blocked> — <one line>".

## Out of scope
Switching pages back (done by hand after deploy); Forbeast; deploying.
