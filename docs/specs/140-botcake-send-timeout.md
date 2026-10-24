# Brief 140: chatbot replies wait up to 30 s for Botcake instead of 15 s

## Why
Chatbot replies are sent to Botcake with a 15-second HTTP timeout (`app/Services/Chatbot/BotcakeProvider.php` uses `BotcakeClient::shared(sha1($token), 15)`). The first message of a flow (with an image) often takes Botcake 8–11 s and sometimes more, so it fails as "Botcake did not answer (HTTP 0)" and the customer gets no reply. Data 2026-10-24: Likha first-message node n10 averaged 3.9 s, max 15 s, 6 of 1,521 failed at exactly 15 s; on incepxion.pro the page Daily Selection took 8–11 s when it worked and 2 of 4 first messages failed at 15 s (`botcake_send_requests`, called_at → done_at). The owner: "oo habaan mo".

## Decisions
| Decision | Why |
|---|---|
| The chatbot reply send timeout to Botcake becomes **30 s** (connect timeout stays 10 s), as a config value `services.botcake.send_timeout` (default 30) read by `BotcakeProvider`, not a new hard-coded number. | Long enough for slow first messages; configurable. |
| Every path a chatbot reply send goes through must allow it: the async send path (`async_sends` / send concurrency, the botcake-send worker and its job / claim / deadline timeouts) must not cut a call off before 30 s, and nothing may then mark the request failed while the HTTP call is still running. Find these in the code (`botcake_send_requests` claim/deadline logic, the sender job's `$timeout`, any Guzzle pool timeout) and adjust only what is needed; list each in the result. If a worker's supervisor `--timeout` must change, do not change the server: write the exact change in the result. | The longer wait must actually take effect end to end. |
| No retry is added on timeout (a timed-out send may still have been delivered by Botcake; a retry could double-send). Chatblast sends and other Botcake calls keep their current timeouts. | No duplicate messages; small change. |

## Done when
- A test shows a chatbot reply send uses a 30 s timeout (and the config value overrides it), and a test or reasoning in the result shows the async/claim path does not fail a send before ~30 s.
- `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/OrderEncode` adds no new failures.
- Result: files and lines changed, every timeout in the send path with old and new values, any server worker change needed, commits. End with "140: <done|blocked> — <one line>".

## Out of scope
Retries; image caching; chatblast timeouts; deploying.
