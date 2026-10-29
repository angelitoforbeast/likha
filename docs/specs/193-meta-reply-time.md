# Brief 193: Speed screen shows the real reply time on Meta's clock

## Why
The owner's measure is: customer sends → our reply is sent by Meta. The Speed screen's "Reply time" starts when the message reaches Likha and ends when Likha hands the reply to Forbeast; "pushed → sent at Meta" is a second number on another clock. Neither includes Meta → Forbeast → Likha (about 2.6 s). Forbeast now computes the real number (its spec 029) and serves it to the site.

## The Forbeast API (contract, from Forbeast spec 029)
`GET {GATEWAY_URL}/v1/site/reply-speed?date=YYYY-MM-DD[&page_id=…]` with the site key. JSON: `{date, page_id|null, timezone:"Asia/Manila", hours:[{hour, messages, bot_answered, person_answered, unanswered, avg_s, median_s, p90_s, max_s, meta_to_forbeast_avg_s}], total:{same fields}, generated_at}`. Hours with no data: `messages: 0`, null times; today's future hours omitted. Another site's page → 404. Build against this contract with faked HTTP; do not wait for Forbeast.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | A **new first section** on /chatbot/speed: "Reply time at Meta (customer sent → our reply sent, Meta's clock)", with the table by hour (messages, bot answered, by a person, unanswered, average, median, p90, max, Meta → Forbeast average) and a Day total row, and its chart (average solid, median dashed, p90; the previous day as the thin dotted reference, same style as spec 185). It follows the day and Page filters. Bot filter Botcake → the section says "Botcake gives no Meta times". | The owner's main number goes first. |
| 2 | Only Forbeast pages are in it; a line under the title says so and how many of the day's chats went through Botcake (from the existing numbers). | Honest scope. |
| 3 | Fetched server-side through the ForbeastSite client: 5 s timeout, cached (today 2 min, past 30 min, versioned keys like the other day data), the previous day fetched the same way. Forbeast not linked (no GATEWAY_URL or site key, e.g. Incepxion today) → the section shows "Not linked to Forbeast" and nothing is called. Forbeast slow, down or erroring → the rest of the page renders normally and the section says "Forbeast did not answer (time)"; never a 500. | The page never breaks on Forbeast. |
| 4 | Existing sections, numbers and charts unchanged. | Safe. |

## Cases (S-193)
P1: .1 with a faked API day, the table and chart show its hours and Day total; .2 the Page filter passes page_id; .3 the previous day reference is drawn; .4 no Forbeast link → "Not linked to Forbeast", no HTTP call; .5 Forbeast timeout/500/404 → the page renders with the message; .6 cached (a second load makes no HTTP call); .7 existing Speed tests pass unchanged; .8 at 390 px width no horizontal page scroll.

## Done when
P1 pass; `tests/Feature/Chatblast` adds no new failures vs 79189d57 (record the baseline). Result with rendered evidence (desktop and 390 px) of the new section with fake data, deploy notes, and that it applies to Incepxion (shows "Not linked"). End with "193: <done|blocked> — <one line>".
