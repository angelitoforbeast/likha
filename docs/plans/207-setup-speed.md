# Plan 207: Connections tests and the Check column without the wait

Spec: `docs/specs/207-setup-speed.md`. Stories S-207.1 to S-207.8 go into `qa/stories.md`.

## Design

### Botcake setup calls (decisions 1-3)

- `BotcakeClient::reads(array $reads, string $token)`: several GETs at once through `Http::pool`. Each read keeps
  `call()`'s retry rule with the client's own `retries`, `timeout` and `maxRetryAfter` (so `once()` stays one try,
  as today); a read that needs a retry is sent again in the next round after the longest wait of that round. The
  whole burst takes ONE rate slot (one `throttle()` per round), and the test page mock (BotcakeMock) answers without
  HTTP as today.
- `BotcakeClient::forSetup(string $token)`: a clone whose rate key is `setup:<sha1(token)>` instead of the send
  lane's `sha1(token)`. Staff setup actions (Test, Save and test, Fix chat block, On/Off) use it, so a busy page's
  sends never delay them and they never take the sends' slots. Retries (429, 5xx, no answer) stay as today.
- `BotcakeClient::tokenFieldsFrom(array $r)`: the `tokenAndFields()` answer (and its exceptions and messages) from one
  read's result, so the token read can sit in the burst. `tokenAndFields()` keeps its own path.
- `ChatbotConnections`:
  - `testBotcake`: one burst of custom_fields, default_reply/content, default_reply, keywords and flows, then the
    same stamps in the same order (token_status, chat_block, keywords) and the same JSON.
  - `saveBotcake`: one burst of custom_fields, default_reply/content (skipped with confirm), keywords and flows;
    then the writes in order (content, then publish) through `chatBlock()`, then the keywords stamp. Same JSON.
  - `fixChatBlock`: the one takeover read, then the writes, all on the setup key.
  - `blockStateFrom()`, `publishedFrom()` and `keywordsFrom()` read the burst's results; `blockState()` stays for
    `guard()` and the console command.
- `BotcakeChatBlock::turnOn/turnOff` and `ChatbotConnections::chatBlock/guard` take an optional client; the
  controller's On/Off passes the setup client. "+ Page" and the console commands keep today's client.

### Check column (decisions 4-6)

- The check cache key is built from the page's own inputs: its settings print (as today), the print of its flow's
  dependencies (the flow, every flow reached through subscribed sequences, those sequences and all their steps, and
  the steps that use the flow), its own content answers (`chatbot_content_answers.page_name`), and one digest of the
  names every check treats as known (save_to / ops / fields of all flows, stored customer keys) plus the botfield
  columns. The flow dependency prints and the known-names digest are computed once per change of the flow tables
  (cached under the tables' MAX(updated_at)/COUNT), so editing flow A changes only the keys of pages whose flow
  reaches A.
- The check result is kept for an hour (10 minutes while some image links are still unchecked), since its key now
  changes with its inputs; Check again still forces a fresh run.
- `/chatbot/data` keeps returning each page's cached check (with `checked_at`) or null; the page re-checks only the
  null ones, at most 2 at a time, the rows on screen first, then the rows the filters show, then the rest
  (`checkQueue`, a page helper between `check-queue:start/end` markers, run in node by the test).

## Tasks

| # | Task | Cases | Side | Risk | Parallel-safe |
|---|---|---|---|---|---|
| 1 | Burst reads and the setup rate key in `BotcakeClient`; Test, Save and test, Fix chat block and On/Off use them | S-207.1, .2, .3, .4 | backend | medium | yes (no shared files with 2-3) |
| 2 | Per-page check key and the longer result lifetime in `ChatbotPageCheck` | S-207.6, .7, .8 | backend | medium | yes |
| 3 | `/chatbot/data` with warm and stale checks; the page's check queue (2 at a time, on-screen first) | S-207.5, .8 | backend + frontend | medium | after 2 |
| 4 | Skeptic review of decisions 3 and 5, fixes, full tests/Feature run, result | all | both | medium | last |

One developer (this session) does the tasks in order; no parallel workers, since tasks 2 and 3 share
`ChatbotPageCheck` and the test helpers.

## Tests

- `tests/Feature/Chatblast/ChatbotSetupSpeedTest.php`: Botcake fakes where every call takes 500 ms of wall time
  (the fake answers with a promise that resolves 500 ms after the request was sent, so pooled requests overlap and
  serial ones add up) and a clock whose `now()` follows the wall time plus the recorded sleeps, so pacing waits count
  in the elapsed time without really sleeping. Expected stored statuses and JSON are today's, recorded from a run
  of the base code on the same fakes.
- `tests/Feature/Chatblast/ChatbotCheckCacheTest.php`: warm and stale checks in `/chatbot/data` (117 pages), the
  per-flow invalidation, today's results on seeded pages, Check again, and the page's queue in node.
