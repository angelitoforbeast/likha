# Brief 190: Chatbot → Live, an animated map of what the bot is doing right now

## Why
The owner wants to see the bot working live, as an animation: messages coming in, the bot (and AI) processing them, replies confirmed by Meta, follow-ups (sequences) going out, and orders saved. A reference the owner liked is a dark "command center" with a central core, three clusters (Inquiries, Follow-up, Orders) and pulses travelling along lines when events happen. Make an original design for Likha (no other product's name or branding).

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **New tab "Live" under Chatbot** (`/chatbot/live`), same access as Speed/Bot Health (403 otherwise). | Where the bot's data is. |
| 2 | **Top: counters for today (Manila)** — Messages received, Bot replies confirmed by Meta (with "sent, not confirmed yet" small), Follow-ups sent (sequence replies), AI answers, Orders saved — each with the count in the last 5 minutes beside it. A small "Live" dot and "last update HH:MM:SS". Page filter (All pages / one page) like Speed. | Numbers at a glance. |
| 3 | **The map**: a central "Likha Bot" core; left cluster **Inquiries** (incoming messages), right-top **Follow-up** (sequences), right-bottom **Orders**; an **AI** ring or node near the core. Each real event animates: a pulse from Inquiries → core for a customer message; core glows while processing; a pulse core → Follow-up for a sequence send; core → Orders for an order saved; a small spark on the AI node for an AI answer; a confirmed reply flashes the core's outer ring. Idle: a slow ambient heartbeat. Buttons: **Pause** (stop animating, keep counting) and **Preview** (play a short demo of every event type). Respect `prefers-reduced-motion` (no travelling pulses, counters still update). | The owner's request: an animation of what the bot processes. |
| 4 | **Data**: one JSON endpoint polled every 5 s (pause when the tab is hidden) that returns the counters and the new events since the last ids the page sent (per source: max id of botcake_chats, chatbot_replies, chatbot_ai_requests, the order source), capped (e.g. ≤ 200 events per poll, then summarised as "+N"). Events carry only type, time, page name and an id — **no customer names or message text** on this screen. Queries by primary key/indexed time only; counters cached ≤ 10 s; measure the endpoint on a production-sized fixture (~35k chats/day, ~60k replies/day) and keep it under 100 ms. | Live but cheap; no personal data on a showcase screen. |
| 5 | Drawn with SVG + CSS/JS (or canvas) — no heavy library, no build step if the app's existing pattern allows; dark theme for this page only; readable at 390 px (the map scales; clusters stack labels). | Fits the app. |
| 6 | No change to sending, flows, other screens. | Safe. |

## Cases (S-190)
P1: .1 the page renders for a role that sees Speed and 403s otherwise; .2 the endpoint returns today's counters equal to fixture counts (messages, confirmed replies, sent-not-confirmed, sequence sends, AI answers, orders) and the 5-minute counts; .3 given last ids, it returns only newer events, in time order, typed correctly, with no names/text; .4 more than the cap → capped list plus a "+N more" count; .5 page filter limits counters and events; .6 existing Chatblast suites pass. P2: .7 390 px renders with no horizontal scroll; .8 reduced-motion shows no travelling pulses.

## Done when
P1 pass; `tests/Feature/Chatblast` adds no new failures vs 6bef798e (record the baseline). Result with the endpoint's queries and measured time, a screenshot or recorded evidence of the page (desktop and 390 px, idle and during Preview), deploy notes, and that it applies to Incepxion. End with "190: <done|blocked> — <one line>".
