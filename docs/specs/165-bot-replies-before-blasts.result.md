# Result 165: a big broadcast never slows the bot's replies

Status: done. Branch `fix/165-bot-replies-before-blasts`, cut from `87cb0e1e` (live Likha).

## What changed
- **Pick in the page lane (decision 1).** `BotcakeSendPool::lanes()`: a ready bot reply (priority 0) is always the page's next send. The ">10 s waited goes first" promotion now applies only among chatblast jobs, with the same order as before (an older in-transit job beats a newer Delivering one only after waiting 10 s).
- **Claim (decision 2).** A page holds `perPage()` tickets as before, but a bot reply may take one more place (`room()`). The claim SQL (`BotcakeSendQueue::claim`, new `$fullForReplies`) still lists priority-0 rows of a page that is full of chatblast, until that page is also full for replies. In-flight chatblast calls are not touched.
- **Unchanged (decisions 3 and 4):** the per-page rate (2/s, shared through the same cache slot), `send_concurrency`, chatblast deadlines, retries, sequences and the chatbot flow.
- **Global cap, not changed (by choice):** when the whole pool is held by chatblast, a reply waits for the next free slot and is claimed first (ORDER BY priority). The reviewer judged this is not a major in the incident's shape; noted in `TODO.md`.

## Case table
| Case | Test | Result |
|---|---|---|
| S-165.1 | `BotRepliesBeforeBlastsTest::test_S_165_1_a_fresh_reply_goes_before_200_chatblast_jobs_that_all_waited_over_10_s` | pass (red on 87cb0e1e: "the reply is the next send of its page", got chatblast 7001) |
| S-165.2 | `test_S_165_2_among_chatblast_the_over_10_s_promotion_works_as_before` (2 rows) | pass, before and after (characterisation of today's order) |
| S-165.3 | `test_S_165_3_a_page_whose_pool_share_is_full_of_chatblast_still_gets_its_new_reply_claimed_and_sent_first` | pass (red on 87cb0e1e: "the reply is claimed", expected 'sending', got 'pending') |
| S-165.4 | `assertRate` inside .1, .3 and .5 | pass |
| S-165.5 | `test_S_165_5_with_1000_chatblast_on_5_pages_every_reply_goes_out_within_1_s` (6.6 s run) | pass (red on 87cb0e1e: replies 4–6 s late) |
| S-165.6 | `tests/Feature/Chatblast tests/Feature/Botcake tests/Unit` | pass: 1308 tests, 14723 assertions, 1 skipped, 0 failures (baseline 87cb0e1e: 1303, all pass) |

## Story changes
- S-089.13 (`BotcakeAsyncSendTest`): its second half now sets `send_concurrency` to 9 instead of 8. 8 was exactly Page Uno's chatblast share (6) + 1 + 1. Under decision 2, Uno's pending reply takes its one extra place and fills a pool of 8. What the test guards is unchanged: another page's newer send is not hidden behind a backlog. At 9 slots it still fails if pages full for replies aren't excluded from the claim SQL (checked). The story text is unchanged.
- S-165.4 is checked inside .1, .3 and .5 rather than in its own test.

## Review
`skeptic-reviewer`, standard depth: no blockers or majors. 3 minors were accepted with reasons in `TODO.md` (a test with the whole pool full, a table row with two promoted chatblast jobs, a possible one-tick wait for another page's reply). The new test uses `@dataProvider` docblocks like the existing tests, so the deprecation count went from 48 to 49.

## Deploy notes
- No migration and no config change.
- Restart `botcake:send-pool` after the deploy (supervisor) so the new code is loaded.
- The same commits apply to Incepxion (same codebase and the same files, untouched since 87cb0e1e).

165: done — bot replies are the next send of their page and are claimed even on a page full of chatblast; the 2/s page rate holds.
