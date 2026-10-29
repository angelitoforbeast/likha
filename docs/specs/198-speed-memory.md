# Brief 198: the Speed page must not run out of memory late in the day

## Why
On 2026-10-29 the owner's /chatbot/speed failed twice (22:57 and 23:22 Manila) with "Allowed memory size of 536870912 bytes exhausted" at ResponseSpeed.php:435 and :442, inside sequences(). That day had about 152,000 sequence steps due, 73,000 sent and 35,000+ chats. sequences() loads every sequence event of the day with ->get() (detail JSON decoded for all), every step send via stepSends(), passes the whole event list to stepHistory(), and builds a $slow array with one row of strings per sent step (tens of thousands) only to keep the top 20. Volume grows through the day, so the page dies at night.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **Same numbers, less memory:** stream events with cursor()/lazy chunks selecting only the needed columns; decode detail once per row and keep only the small fields used; keep only the top SLOWEST rows with a bounded structure (e.g. a min-heap of 20) instead of collecting all; keep percentile inputs as plain int arrays (or a compact structure) per hour; make stepSends() and stepHistory() hold only what the lookup needs (compact keys/ints, no full row objects), loading by keyset/chunks if large. Apply the same treatment to the chats and replies reads in build() if they hold full rows. | Fix at the root; identical output. |
| 2 | **Proof:** a test with a large fixture (e.g. 150,000 sequence events + matching sends, 40,000 chats) that renders the Speed day and asserts memory_get_peak_usage stays under 192 MB (state the before/after numbers measured on the old and new code), and the existing ResponseSpeedTest/MetaReplySpeedTest/Speed tests pass unchanged so every number is identical. | Shows it holds at the real volume. |
| 3 | Also cap the request: if a day's computation still exceeds a safe budget, the page shows the other sections and a "Sequences section too large to compute now" note instead of a 500 (catch at the section level). | Never a blank page. |
| 4 | No change to what is shown, the cache keys' meaning (bump the version if the cached shape changes), or other screens. | Narrow. |

## Cases (S-198)
P1: .1 the large fixture renders under 192 MB peak with the same totals as before; .2 slowest top 20 identical to the old algorithm on a fixture; .3 existing Speed tests unchanged and passing; .4 a forced failure in the sequences section still renders the page with the note; .5 `tests/Feature/Chatblast` adds no new failures vs 5b99fef7.

## Done when
P1 pass. Result with before/after peak memory and time on the large fixture, the exact changes, deploy notes (view:clear, cache version). End with "198: <done|blocked> — <one line>".
