# Spec 235: CBB is Chatbot Builder only, and the auto-switch notices Meta errors it doesn't know

**Project:** Likha (the same code runs incepxion.pro). **Shape:** bug + change · **Weight:** bounded · **Risk tier:** high (it decides when live pages switch bots)

## Why

**Part A, false "Main flow double".** In app/Services/Chatbot/ChatEchoes.php, `CBB = ['chatbot_builder', 'botcake']`. CBB means Chatbot Builder (app 573598697063667), a separate tool. Botcake (app 556376998159104) is the carrier of our own Likha bot on pages that reply via Botcake: Likha's FlowEngine sends through Botcake's API, so Meta stamps those messages with Botcake's app id.

So on every Botcake-route page, each Likha main-flow reply is counted twice, once as "CBB" and once as the Likha bot. The banner then shows "Main flow double … answered by both CBB and the Likha bot" on about 29 pages every hour.

A live check on 3 pages (Xavia Aquino, Carla Mercado, Faye Navarro) found 4 Botcake-stamped echoes for 4 Likha sends per new customer, within 0 to 1 s, and no customer who got two answers. Echoes have been stored since 2026-11-04 15:00. The file's header comment ("Botcake does not forward its echo") is also wrong: it does.

**Part B, unknown Meta errors.** In app/Services/Chatbot/ForbeastWatch.php, `afterResult()` switches a gateway page to Botcake at once only for `CONNECTION_CODES = [190, 200, 613, 4, 32]` (2022 excluded), and ignores `CUSTOMER_CODES = [551, 10]` and every other code. A Meta error code it doesn't know is ignored even when most of a page's sends fail with it.

`#10` also means two different things:
- with subcode 2018278 it is "outside of allowed window", a customer cause;
- with subcode 1893063 it is "temporarily restricted", a page cause. On Likha all 723 #10 errors in the last 24 h were subcode 1893063.

Today 1893063 is skipped as a customer code.

## Decisions already made (settled with the owner; don't reopen them unless something is actually broken)
| # | Decision |
|---|---|
| A1 | `CBB` is `chatbot_builder` only. A Botcake-stamped echo is never counted as CBB. On a Botcake-route page it is the Likha bot's own reply; on a gateway page it is shown as "Botcake" as today, and never counted as CBB. |
| A2 | The "Main flow double", "leftover" and Health "who answered" numbers follow A1. A real double (Chatbot Builder plus the Likha bot) still shows. |
| A3 | Fix the wrong header comment. |
| B1 | Unknown codes: a Meta error code (or code and subcode) on a gateway page that is not in CUSTOMER, CONNECTION or any other known list is counted per page in a sliding 10-minute window, separately for RESPONSE sends (answers to a customer who just wrote: messaging_type RESPONSE, or no sequence and an inbound within the window) and for UPDATE sends (sequences, Chatblast, follow-ups). |
| B2 | RESPONSE: at least 10 RESPONSE sends in the window and 30% or more failed with unknown codes → switch the page to Botcake (condition `unknown_error`, reason "Forbeast send failed at Meta with an unknown error #X"), switched back by the existing healthy rule. |
| B3 | UPDATE: at least 10 UPDATE sends and 70% or more failed with unknown codes → no switch, only an orange banner line "New Meta error #X on <page>: N of M updates failed in 10 min". |
| B4 | Whenever an unknown code is first seen on a site, a white (info) banner line names it once a day, so a new kind of failure is visible even below the thresholds. |
| B5 | `#10` with subcode 1893063 is a page restriction, not a customer cause: it is not skipped as a customer code, but it never switches either, since the restriction is the same on Botcake. The existing "Page restricted by Facebook" line covers it. `#10` with subcode 2018278 (or the "outside of allowed window" text) stays a customer cause. |
| B6 | Thresholds go in config (`services.chatbot.watch` or the existing ForbeastWatch limits), with env defaults 10 / 30% / 70%. |

## Stories
Add these to qa/stories.md under `## Slice 235 – CBB and unknown codes`, with one failing test per case first, named with its case ID.
- S-235.1.1 happy: a Botcake-route page, a new customer, 4 Likha sends and 4 Botcake-stamped echoes → no "Main flow double".
- S-235.1.2 happy: the same plus a Chatbot Builder echo → "Main flow double" shows.
- S-235.1.3 edge: a gateway page with a Botcake-stamped echo → labelled Botcake in the Inbox and Health, never counted as CBB.
- S-235.2.1 happy: 12 RESPONSE sends, 4 failed with unknown #9999 → switched, with the reason naming #9999.
- S-235.2.2 negative: 12 RESPONSE sends, 2 failed with #9999 → no switch.
- S-235.2.3 negative: 20 UPDATE sends, 15 failed with #551 → no switch, no line (a customer code).
- S-235.2.4 happy: 20 UPDATE sends, 15 failed with #9999 → an orange line, no switch.
- S-235.2.5 edge: fewer than 10 sends → nothing.
- S-235.3.1 happy: #10 subcode 1893063 → no switch, not skipped as a customer code, and counted for "Page restricted".
- S-235.3.2 happy: #10 subcode 2018278 → customer cause, as today.
- S-235.4.1 happy: the first #9999 of the day → one white info line, and no second one that day.

## Constraints
- High tier. The window counting must be cheap (cache counters like the existing ForbeastWatch counts), with no query per send.
- Run the ForbeastWatch, ChatEchoes, ProblemsPanel and Health tests often and the full suite last. Known pre-existing failures: ExampleTest and ItemTableFitScriptTest; BigBroadcastTest and AstraAddressRulesTest S-26.18 are flaky.

## Budget
At most 2 tries at the same step. Size: medium.

## Done when
- [ ] The S-235 cases pass, each with a test named after it.
- [ ] The full suite adds no new failures.
- [ ] qa/stories.md has slice 235.
- [ ] The result has Merge danger and how to turn off B2 and B3 quickly by config.

## Out of scope
Changing the existing 190/200 rules, BotcakeOutbox sending, and deploying.
