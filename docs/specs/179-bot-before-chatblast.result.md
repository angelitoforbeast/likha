# Result 179: the bot's replies always go before chatblast on a page's Botcake lane

Status: done. Branch `feat/179-bot-before-chatblast`, based on fe54c227. Not pushed, not deployed.

## What changed
- **Strict priority in the lane** (decision 1): already true since spec 165. A ready reply (priority 0) ranks ahead of every chatblast step in `BotcakeSendPool::lanes()`, including steps already queued. A chatblast job's next step waits behind it, and its write → read → send_flow order is kept by the stepper. That code is unchanged.
- **Replies get their own slots** (decision 2, claim): the old pool gave replies one extra ticket on top of the page's chatblast share and shared the pool's `send_concurrency` with chatblast. With Botcake taking about 1 s to answer, only one reply per page could be in flight, and a pool full of chatblast claimed no replies at all. Now replies and chatblast each have `perPage()` tickets per lane and `send_concurrency` in the pool (`room()`, `fullPages()`, `tick()`).
  - `BotcakeSendQueue::claim` filters chatblast-full pages and reply-full pages independently. Replies are still claimed first (`ORDER BY priority, id`).
  - When the pool is full of chatblast, claim looks only for replies (`$repliesOnly`), so the 09:00 burst doesn't read hundreds of chatblast rows on every tick.
- **Chatblast share** (decision 2, lane): while the bot is busy on a lane, chatblast sends at most once every `interval / chatblast_share`, which is 1/s at the defaults. "Busy" means a reply is held on the lane (waiting, or waiting for Botcake's answer), or a reply was sent within the last two rate intervals. Otherwise chatblast uses the full rate.
- **Config:** `services.botcake.chatblast_share`, env `BOTCAKE_CHATBLAST_SHARE`, default `0.5`, clamped to 0.05–1. Added to `.env.example`. The `send_concurrency` comments and the pool's start line now say replies and chatblast each get that many.
- **Unchanged** (decision 4): rates per page, the shared rate slot (`BotcakeClient::slot`), deadlines and "Not sent in time" rules, chatblast content, and Forbeast pages. No new tables and no migration.

## Simulation (S-179.4): 09:00 burst on one page
Setup: 500 chatblast messages (1,500 calls), and 50 bot replies arriving one every 0.6 s from 5 s. The page runs at 2 requests/s, and the fake Botcake answers each call after 1 s. A reply's wait is measured from when it is ready to when its send_content goes out.

| | Reply wait avg | Reply wait max | All chatblast sent at |
|---|---|---|---|
| Before (fe54c227) | 0.60 s | 1.8 s | 776.1 s |
| After | 0.20 s | 0.4 s | 775.7 s |

- Before, replies queued behind each other: only one could be in flight per page, and each took about 1.5 s.
- After, every reply goes out within one rate interval. Chatblast finishes at the same time, because it gets back every slot the bot doesn't use.
- With a reply arriving every 1 s, chatblast drops to 1 call/s (S-179.3). Two intervals after the bot's last send it is back at 2/s.

## Case table
| Case | Test (`tests/Feature/Chatblast/BotLaneShareTest.php`) | Result |
|---|---|---|
| S-179.1 | `test_S_179_1_three_replies_queued_on_a_lane_busy_with_chatblast_are_the_next_three_sends` | pass (red on fe54c227: a chatblast step `70001` went between the replies) |
| S-179.1b | `test_S_179_1b_a_pool_full_of_chatblast_still_claims_and_sends_the_reply_next` | pass (added after review; on fe54c227 the full pool passes n = 0 to claim, so the reply is never claimed; not run there) |
| S-179.2 | `test_S_179_2_each_chatblast_job_keeps_its_write_read_flow_order_between_replies` | pass (also green before: non-regression) |
| S-179.3 | `test_S_179_3_…` 3 data sets: no bot work, one reply and then none, a reply every 1 s | pass (red before: 15 chatblast calls in 10 s with bot work; the "then none" row was red at the first fix, 15 < 19) |
| S-179.4 | `test_S_179_4_the_0900_burst_500_chatblast_and_50_replies_every_reply_within_one_second` | pass (red before: max wait 1.8 s) |
| S-179.5 | `test_S_179_5_chatblast_completes_under_continuous_bot_traffic` (60 chatblast, a reply every second for 300 s) | pass (also green before: non-regression) |
| S-179.6 | `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` | pass: OK, 1535 tests (1 skipped), deprecation notices only |

Baseline at fe54c227: the same three suites, OK, 1527 tests (1 skipped, the same deprecation notices). No new failures; the 8 new tests are the difference.

## Story changes
- Added S-179 to `qa/stories.md` from the brief's cases, with these changes:
  - **S-179.1b added:** a pool full of chatblast still claims the reply. The reviewer flagged the pool-wide budget as untested.
  - **S-179.3 "bot work" row:** it uses a reply every 1 s (bot work always waiting) instead of every 2 s. Every 2 s leaves real idle gaps, and decision 2 lets chatblast use them.
  - **S-179.3 "one reply, then none" row added:** it pins decision 2's "may use the rest".

## Review
Skeptic reviewer, adversarial depth, on fe54c227..b0f663c6. One major, no blockers. Fixed once, in 06d78be9.
- **Major, fixed:** chatblast stayed at its share for 10 s after every reply. On a busy page that halves the campaign's speed for the whole burst. The window is now two rate intervals, and the new S-179.3 row covers it.
- **Minor, fixed:** a pool full of chatblast ran the claim query over hundreds of chatblast rows on every tick. It now claims replies only.
- **Minor, fixed:** the `send_concurrency` docs.
- **Minor, fixed:** the missing test for the pool-wide budget (S-179.1b).
- **Accepted in `TODO.md`:**
  - Strict priority gives chatblast no calls while bot traffic alone fills the page's rate. This is decision 1, and it conflicts with decision 3's "no chatblast job waits forever" only in that case. The 3 h deadline still applies.
  - "At once" means within one rate interval.
  - There is no test that holds `perPage()` replies on one lane.
  - There is no test with bot traffic at or above the rate.

No browser check: no screen changed.

## Conflicts
Decisions 1 and 3 conflict in one case: when bot traffic alone fills a page's rate, strict priority gives chatblast no calls until it drops. I kept strict priority and recorded this in `TODO.md`. Adding a chatblast floor needs a yes from the reviewer.

## Deploy notes
- No migration and no new table.
- Optional env: `BOTCAKE_CHATBLAST_SHARE` (default 0.5).
- After the code is out, restart the pool so it loads the new code: restart the `botcake:send-pool` supervisor program, then `php artisan queue:restart`. Run `php artisan config:cache` if config is cached.
- Pool concurrency can now reach 2 × `send_concurrency` in flight (replies plus chatblast). Rates per page are unchanged.
- **Incepxion:** the same commits apply unchanged (same files, and no Likha-only code).

179: done — replies have their own pool slots and go first on the lane; chatblast keeps to 50% while the bot is busy. In the 500 + 50 burst the reply wait drops from avg 0.60 s / max 1.8 s to 0.20 s / 0.4 s, and chatblast still finishes on time.
