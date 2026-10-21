# Result 086: a fake Botcake for test pages (chat in → flow → reply out)

Branch `feat/086-mock-botcake`, cut from `main` at `69d7c40c`. Nothing was pushed, and no remote host was touched.

## Commits

| Commit | What |
|---|---|
| `b58d4e1e` | docs: the brief (verbatim) |
| `b7c7650e` | feat: `BotcakeMock` inside `BotcakeClient`; `botcake:stress-page`; the k6 scripts and README; tests |
| `22f99bff` | fix: the seeded PSIDs are the ones the k6 scripts send; the mock-page list is kept in memory for 10 s |
| `e36be8e3` | fix (review): the test-page guard, `--latency` / `--error-rate`, the AI counts on MySQL, the reply-time join, forget after commit, the key file |
| (this commit) | docs: result |

## Files changed

| File | Change |
|---|---|
| `app/Services/BotcakeMock.php` (new) | The fake Botcake. It answers only a call whose path page ID starts with `99` **and** belongs to a page with active `botcake.mock = 1` and a stored `99…` page ID. The list of fake pages is cached for 60 s, and kept in each worker's memory for 10 s. It waits `botcake.mock_latency_ms` (default 400, max 5000) on `BotcakeClock`. Answers, in Botcake's shapes:<br>• `send_content`: success.<br>• Field write: stored per page and PSID in the cache; the read-back returns `data[{id, value}]`.<br>• `send_flow`: success, and adds 1 to the flow's statistics.<br>• Customer info: found.<br>• `flows`: one flow named after the Setup box flow name.<br>• `custom_fields`: `message`.<br>• `message_templates`: APPROVED `post_order` with `{{1}}`.<br>• `flows/{id}/statistics`: `{seen, sent, delivery, …}`.<br>• `customer`: an empty list.<br>• Any other call: a 404, logged once per path type.<br>A POST answers 500 at `botcake.mock_error_rate` (0 to 0.5). There is a cache counter per page and call kind. `reset()` starts a new generation, which drops the page's fields, statistics and counters. |
| `app/Services/BotcakeClient.php` | `call()` and `get()` take the mock answer, wrapped as a real `Response`, before the HTTP call. The throttle, retries and failed paths run unchanged. A real page ID costs one regex match, with no cache or database read. |
| `app/Services/PageSettings.php` | `botcake.mock`, `botcake.mock_latency_ms` and `botcake.mock_error_rate` are system keys, so the UI refuses them. Changing them, or `page_id`, clears the mock-page list, now and again after commit. |
| `app/Console/Commands/BotcakeStressPage.php` (new) | `botcake:stress-page`. Defaults to `ZZ STRESS TEST` / `990000000000001`; `--page` and `--page-id` override. |
| `tests/Feature/Chatblast/BotcakeMockTest.php` (new) | 9 tests (below). |
| `tests/stress/` (new) | `common.js`, `intake.js`, `sustained.js`, `flow.js` and `README.md`. Reference only; the suite doesn't run them. |

The options of `botcake:stress-page`:
- `--seed=N --key-file=…`: the page, token `mock`, `botcake.mock=1`, and a random `chat.key` written only to the file (mode 600, never printed). Plus N PSIDs `99` + 14 digits, counting up from 0, which are exactly the ones the k6 scripts send, so no chat needs a verify call (`source = stress_test`).
- `--latency=` and `--error-rate=`.
- `--flow-from=PAGE`: copies that page's active chatbot settings (flow, botfields) and turns the test page On.
- `--stats`.
- `--cleanup`.

Every option refuses a page ID that isn't `99…`, and a page name with any real Botcake page ID, history included. Every option except `--seed` needs an existing test page; `--seed` needs a test page or a name with no settings at all.

## Tests

`vendor/bin/phpunit tests/Feature/Chatblast/BotcakeMockTest.php`: 9 tests, 81 assertions, all pass.

| Case | Test | Result |
|---|---|---|
| Mock calls make no HTTP and have Botcake's shapes: `send_content`, field write and read-back, `send_flow`, customer info, customers, custom fields, `flows`, templates, `statistics`, 404. The whole 082 setup check passes against it, and the counters add up. | `test_S_086_1…` | ✔ pass |
| A real page ID with the flag still calls botcake.io (faked); the page can't set the flag | `test_S_086_2…` | ✔ pass |
| The latency is applied (fake clock): 1.2 s, capped at 5 s, default 0.4 s | `test_S_086_3…` | ✔ pass |
| The error rate gives a 500 and a failed reply (`chatbot_replies` status `failed`, http 500) | `test_S_086_4…` | ✔ pass |
| A chat through `/api/botcake/chat` and the flow, with an AI step (OpenAI faked), ends in a `sent` reply with no Botcake HTTP | `test_S_086_5…` | ✔ pass |
| `--seed`, `--flow-from`, `--stats`, `--cleanup`; each refuses a real page ID or a real page name | `test_S_086_6…` | ✔ pass |
| A real page whose page ID was cleared is refused by every option and untouched; cleanup leaves another test page and a real page alone | `test_S_086_7…` | ✔ pass |
| `--latency` / `--error-rate` and their limits; a `99…` page without the flag calls the real host; removing the flag takes effect at once | `test_S_086_8…` | ✔ pass |
| `--stats` counts AI ok/failed and failed replies | `test_S_086_9…` | ✔ pass |

Red runs:
- **S-086.1 to .6, first run:** `Class "App\Services\BotcakeMock" not found`. After the mock was added, `.6` failed with `The command "botcake:stress-page" does not exist.`
- **Review loop:** S-086.7 failed with `Failed asserting that 0 is identical to 1.` (the real page wasn't refused). S-086.8 failed with `The "--latency" option does not exist.`
- **S-086.9's first red was my own regex**, which didn't allow for Windows line ends; it passed after the fix. The MySQL JSON case can't be shown on sqlite, so the driver-specific accessor is covered by reading the code only.

**Suites:** `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 664 tests, all pass.

## Review

`skeptic-reviewer` ran at standard depth, with care that no real page can be faked. Its findings:
- **No path from a request to a fake answer.** A real page ID never reaches the mock.
- **Real calls are unchanged.**

| Finding | Severity | Outcome |
|---|---|---|
| `--stats` AI counts were always 0 on MySQL: a JSON column prints `"result": "success"` with a space, so the `LIKE` never matched | major | fixed: the driver's JSON accessor |
| No way to set the delay or the error rate (system keys, no CLI) | major | fixed: `--latency`, `--error-rate` |
| A real page whose page ID had been cleared passed the guard, and `--cleanup` would delete its settings and message history | major | fixed: every real page ID in its history refuses it, and a positive test-page check is required (S-086.7) |
| Cleanup scope and flag lifecycle untested; `--stats` values untested | minor | fixed (S-086.7–.9) |
| `whereIn` over every replied chat (MySQL placeholder limit) | minor | fixed: a join and a cursor |
| The mock list was forgotten only inside the transaction | minor | fixed: also after commit |
| The key file was readable for a moment before `chmod` | minor | fixed: chmod first, then the key |
| `--cleanup` also deletes the test page's `chat_verify` rows | minor | kept: those are fake verifications made during the test, on the test page only |
| Each fake call makes a few small cache queries (database cache), which a real call doesn't | minor | noted in the README |
| A copied flow with a Google Sheet step writes real sheet rows | spec gap | warned in the README; skipping sheet writes on fake pages would be a separate decision |
| Re-running `--seed` adds the PSIDs again (no unique index); `chmod` does nothing on Windows | minor | accepted (trusted CLI) |

## Notes for the tester

- Allow the tester's IP first with `botcake:chat-test-ip`, as the README says.
- Seed at least as many PSIDs as the scripts' pool (10,000 for intake and sustained, 1,000 for flow). That way every chat is a known customer and needs no verify call.
- The README walks through: seed → copy a flow (check it has no Sheet step) → optionally set the latency and error rate → run k6 → `--stats` → `--cleanup`.

## After the re-check

The re-check of the fix loop was **clean**. Two of its new minors are fixed in the last commit:
- the page-settings "deactivate" route refuses `botcake.mock`, `botcake.mock_latency_ms` and `botcake.mock_error_rate`, so the stress page can't be locked out of its own `--cleanup`;
- a failed write of the key itself is now an error, not a success with an empty file.

Accepted: a `--page-id` that differs from the page's stored `99…` ID makes `--cleanup` delete chats and chatbot rows by the ID given, but settings by the page name. Only test data is touched.

**Full suite:** `vendor/bin/phpunit` ran 2019 tests with 1 error and 1 failure, both the known ones (`ExampleTest`, `NightRun\ImportStartTest`). No new failures.
