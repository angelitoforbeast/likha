# 306 result: /chatbot/speed opens in under 2 seconds, and can never hold a worker for a minute

Base: live 528326cc. Branch `feat/306-speed-page-fast`.

## Plan

Base 528326cc: full suite once (clean `git archive` of that commit + the same `vendor/`, Herd PHP, `-d memory_limit=4G`) before any code change. A local MySQL 8.0.42 on port 3408 (data folder in the session scratchpad, deleted at the end) with invented data the size of a busy day: 120 pages (one with 35% of the traffic, half on a gateway, half on Botcake, 10 without a flow), ~300k chats and ~1M replies per full day (bot replies with some failed tries, ~600k sequence steps, manual replies), AI requests, chatblast rows; 2026-11-16 and 2026-11-17 full, 2026-11-18 until 15:00.

SP-1: time `ResponseSpeed::output()` of the base code on that MySQL (today, today + big page, + small page, + bot, a past day, a past day + big page), every query grouped by its shape with its time and EXPLAIN; PHP time apart.

What the code already shows: the default view of today and yesterday is pre-built by `chatbot:speed-warm` every 2 minutes, but every other view (a page, a bot, any older day, and the previous day each chart needs) is computed in the request: one full read of the day's chats and replies (plus the next 24 h of replies), the AI requests, and the "Sequence steps" volume, whose page filter turns an index-only count into one row lookup per sequence step of the day. A cache miss of the default view does the same.

SP-2 (expected shape, to be confirmed by SP-1):
- One pass per day computes every filter at once. The day's chats, replies and AI requests are read once (the same queries as today's default warm, no page filter); every chat and reply is added to the buckets of its page and bot, so the job writes the finished numbers of the default view, each bot, each page and each page + bot (same `build` arithmetic, so the same numbers). Stored in the cache (no table): one entry per day and page (all bot variants) plus an index entry per day (built_at, final, the pages written, a blank for a page without data); the Not sent lists per filter as today.
- Sequence steps per page: counted per hour (`sequence_id IN (...)` on the `(sequence_id, sent_at)` index, grouped by page); a closed hour is counted once and kept, so a run reads only the current hour's steps.
- Chatblast per page: the same day read grouped by `page_name` too.
- `chatbot:speed-warm` (the existing job, every 2 minutes) runs it: today every run, yesterday every 20 minutes until it is final, the day before yesterday once, and up to 2 days that pages asked for (a "wanted" list like `/chatbot/results`, spec 278). Its Sequences part is unchanged.
- The page (`/chatbot/speed`, and `not-sent`) only reads the cache: the day and the previous day (for the charts) present → the numbers with "as of HH:MM"; missing → a short "computing, this page refreshes in a minute" state that adds the day(s) to the wanted list and reloads itself after a minute. No live computing of the main numbers.

SP-3: the page's remaining queries (page list, cache store) run under a 302-style session `max_execution_time` (reset to DEFAULT in `finally`); a MySQL timeout answers at once with a "taking too long" state. The Sequences endpoint's live computing (filtered views, already limited to 20 s between hourly reads) also gets the session limit for the time left, so one slow SELECT stops at the budget.

Tests (new file in `tests/Feature/Chatblast`): every filter (each page, an unknown page, each bot, page + bot, today, yesterday, an older day) gives exactly the same output, charts and Not sent list as a frozen copy of the base `ResponseSpeed` on a mixed fixture; the page reads no chat or reply table; computing state and wanted list; closed sequence hours read once; the timeout states. Existing speed tests: warm first where they read the page (listed in Evidence).

Order: plan commit; base suite; MySQL seed and SP-1 profile; one-pass build + storage + warm; page and computing state; SP-3; tests; existing tests; full suite vs base; MySQL timings after; result; push.

## What shipped

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
