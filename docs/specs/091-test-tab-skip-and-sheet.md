Small-medium task: spec 091, about 1.5–2 hours. Start now. Two other workers run at the same time: spec 090 (flow editor sheet step, Replies, ChatbotSheet updatedRange) and spec 092 (/chatbot/pages paste and scroll). Avoid resources/views/chatbot/index.blade.php; for the sheet result in Test, show the range text and add the Open row link only if 090 code exists.

**Branch.** Run `git checkout -b fix/091-test-tab-skip-and-sheet main`, using the newest `main`.

**Rules**
- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/091-test-tab-skip-and-sheet.md` and the result as `docs/specs/091-test-tab-skip-and-sheet.result.md`.
- Headless browser check at 1366 and 390 px, with screenshots in `docs/specs/091-test-tab-skip-and-sheet/`.
- Budget: 2 attempts per failing step.

# Brief 091: the chatbot Test tab runs the skip timer, remembers the conversation for AI steps, and can write the sheet row for real

## Why

On `/chatbot/test` (`ChatbotTabsController::testSend`, `FlowEngine` with `$run->test = true`):

- **The skip timer never fires.** A send step with "Wait for the reply" and "Skip if no reply after 1 minute" (e.g. n101 in "Main Flow (from CBB)") parks a `chatbot_waits` row only when `!$run->test` (FlowEngine around line 242). So the test customer waits forever and never takes the "skipped" port.
- **The Google Sheet step never writes in a test.** `TestOutbox` shows the row as "Sheet row (not written)". The owner, testing the n43 sheet step, reported "di ata gumagana yung upload to gsheet".
  - The live config is fine: the link is set, the tab "All Orders" exists in "LDT Order (June 2026) 2.0", and the service account can read the spreadsheet.
  - No live customer has reached n43 yet.

The owner, 2026-10-20: "ayusin sa test tab syempre, test nga diba".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **The real skip timer in Test.** When the test customer waits at a step with a skip time, the Test tab shows "Skips in mm:ss if no reply" with a live countdown. When it reaches 0, the run continues on the "skipped" port, using the same code path as live (`ChatbotRunner::skip` logic or a test equivalent with `TestOutbox`). The new sends appear in the Test chat without a reload, through a short poll while a timer is pending. A reply before the timer cancels it, as live. | Test behaves like live. |
| **The "Skip now" button.** Next to the countdown, it fires the skip at once, for quick testing. | Speed. |
| **Test waits stay apart from real customers.** Test waits use the test PSID (`CustomerFields::TEST_PSID`) and never send to Botcake. Either the minute tick (`chatbot:resume-skipped`) handles them through `TestOutbox`, or the Test page drives them. Your plan picks one, but a test wait must never reach a real send. "Reset test customer" clears them. | Safety. |
| **"Write sheet rows for real" checkbox** in the Test tab, off by default and remembered per browser. When it is on, the Sheet step appends the row to the real spreadsheet through the same `ChatbotSheet` as live. Column A of the written row is unchanged; to mark it as a test, the run's customer name is "Test Customer", as now. The Test chat shows "Sheet row written: All Orders, row N", with the "Open row" link when spec 090's `updatedRange` exists, or the range text otherwise. Sends to Botcake stay simulated. | The owner can prove the upload works without a real customer. |
| **On a sheet error** (not shared, tab missing), the Test chat shows the error in plain words, including the service-account email to share with. Never Google's raw error or credentials. | Easy to fix. |
| **The AI step remembers the test conversation.** Today `FlowEngine::history()` returns `[]` when `$run->test`, so an AI step in Test sees only the current message. The owner noticed an AI phone-number step that couldn't see the number given two messages earlier ("di ata nababasa yung naging conversation e, wala yung remember sa test"). Keep the test customer's own conversation: their messages and the bot's sends in that Test session, in order. Store it as a reserved customer field of the test PSID (e.g. `__test_history`, JSON, capped at the node's history limit, max 100). `history()` uses it for test runs, with the same N, roles and order as live. "Reset test customer" clears it. It never mixes with real customers' chats. | Test behaves like live. |
| **The Test banner text changes accordingly:** "Nothing is sent to Botcake. Sheet rows are only shown, unless 'Write sheet rows for real' is on. Skip timers run as live." | Accurate. |

## Done when

- **Tests:**
  - a test wait with a skip time fires after the time (fake clock) and takes the skipped port with `TestOutbox`;
  - a reply before the time cancels it;
  - "Skip now" fires at once;
  - a test wait never sends to Botcake and never touches a real customer's fields;
  - Reset clears test waits;
  - the sheet checkbox off shows the row only, and on appends through `ChatbotSheet` (faked) and shows the range;
  - a sheet error shows the share message;
- an AI step in Test receives the earlier test messages and bot sends as history (OpenAI faked: assert the request's messages);
- Reset clears the test history;
- a real customer's history never includes test messages.
- **Browser check:** a countdown, "Skip now", the skipped sends appearing, and the sheet checkbox, at 1366 and 390 px.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, and the full suite adds no new failures.
- Report the commits.

## Out of scope

- Live skip behaviour (unchanged).
- Prompt quality of AI steps.
