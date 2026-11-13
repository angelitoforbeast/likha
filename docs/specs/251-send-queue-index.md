# 251: An index for the send-queue sweep

## Why
On the second site that runs this code, the MySQL slow log showed the send-queue sweep's redispatch query at about 4 s per run, 16 runs in 15 minutes, all returning 0 rows:
`select * from botcake_send_requests where kind = 'chatbot' and status in ('done','failed') and updated_at <= ? order by id asc limit 500`
(BotcakeSendQueue::sweep(), app/Services/Chatbot/BotcakeSendQueue.php around line 290). botcake_send_requests has about 2.2M rows there (1.58M sequence/resumed, 0.6M chatbot/resumed) and 570K here. Existing indexes: (customer,status,id), (status,next_at), (status,priority,id), created_at, request_id unique. No index fits kind + status + updated_at, so the query walks the table. It runs all day and adds load to a database that is already busy, which slows every page.

## Stories
**S1 (P1)** The sweep's redispatch query uses an index and examines only matching rows.
- Happy: with many `resumed` rows and a few `chatbot` `done`/`failed` rows older than 30 s, the query returns exactly those rows, ordered by id, at most 500 (behaviour unchanged).
- Evidence: a test (MySQL if the suite has one; otherwise state why not) or, at minimum, the migration plus an EXPLAIN reasoning in the result showing which index the query would use. Prefer an index whose leading columns are equality columns (kind, status) followed by updated_at, e.g. (kind, status, updated_at). Check every other query on this table in app/ (sweep, sweepSequence, claim, finish, PageTransfers, ChatbotSequenceLateness, ChatbotSequences) and choose the index so it also helps any of them that filter on kind+status, without adding more than two new indexes in total.
**S2 (P2)** Explain the growth. Prune in sweep() deletes rows older than KEEP_DAYS (2) but excludes kind 'sequence', and limits to 5000 per run; yet 0.6M chatbot/resumed rows exist on the second site. Read sweepSequence() and the prune paths and report: does any code ever remove old sequence rows? why could chatbot rows outlive 2 days (limit, slow delete, schedule)? Report only; do not change prune behaviour in this spec.

## Decisions already made
| Decision | Why |
|---|---|
| Index only; no query rewrite unless the index alone cannot serve it | Smallest change; behaviour stays identical. |
| Migration must be safe on a 2.2M-row table in production: a plain secondary index add (MySQL 8 builds it online); no column changes, no data changes | The database is live and busy. |
| Prune/retention changes are out of scope | Needs the owner's decision on how long send history is kept. |

## Constraints
- Migration name and index name descriptive; `down()` drops it.
- Works on MySQL 8 (production) and whatever driver the test suite uses.
- Threat model: no new input; risk tier medium (production migration on a large, hot table).

## Done when
1. Migration adds the index; `down()` removes it.
2. Tests for sweep() still pass (and a test for S1 behaviour if none exists).
3. Full suite: no new failures versus d02ed7a1 (name the known ones).
4. Result file docs/specs/251-send-queue-index.result.md: the index chosen and why, which queries it serves (file:line), the S2 findings with file:line, estimated build time note (online, no table lock expected), test counts, final sha.
5. Branch pushed to origin.

## Out of scope
Changing retention/prune, other slow queries (chatbot_replies, botcake_messages), the CPP page work, any deploy.
