# Result 074: /chatbot/inbox, a Pancake-style inbox for the chatbot

Covers the brief and both amendments:
- **Amendment 1:** the reply box, the 24-hour rule, the per-customer bot pause, and who can send.
- **Amendment 2:** executed actions (events), the CBB-style panel sections, and the Human / Bot tabs.

The work was not pushed and touched no remote host. Tests use fake HTTP only. Nothing called the real Botcake, OpenAI or Google.

## Commits

| Commit | What |
|---|---|
| `9f45c858` | docs: the brief, verbatim |
| `ed60495d` | docs: amendment 1, verbatim |
| `3872de07` | docs: amendment 2, verbatim |
| `3e7aa160` | feat: the inbox with replies, the bot pause and executed actions (all code, tests and stories) |
| `5b590c08` | fix (review loop 1 and browser check): events keep their run time; the paused lookup reads only the listed customers; Load older keeps a table that showed nothing; event polling starts at the customer's newest event; pause is set before the send; the thread stays at the bottom; back buttons are phone-only |
| `3c03ff37` | fix (re-check minor): the newest event id is read before the event list |
| (last) | docs: this result and TODO |

## Migrations (run by hand on deploy)

- **`2026_10_24_100000_add_inbox_indexes`:** adds the index `(page_id, psid, id)` on `botcake_chats` and on `chatbot_replies`. Guarded by `Schema::hasTable` / `hasIndex`. Additive, and the down migration drops them.
- **`2026_10_24_100100_create_chatbot_events_and_reply_user`:**
  - creates `chatbot_events`: id, page_id, psid, chat_id, flow_id, node_id, type, detail (JSON), created_at, with indexes `(page_id, psid, created_at)` and `(created_at)`;
  - adds `chatbot_replies.user_id`, nullable.

The existing `(page_id, received_at)` and `(page_id, sent_at)` indexes bound the list.

## Routes (all in the `/chatbot` group: auth, then NavAccess `/chatblast` in the controller, 403 otherwise)

| Method | Path | Name | Who | Throttle |
|---|---|---|---|---|
| GET | `/chatbot/inbox` | `chatbot.inbox` | Chatblast access | — |
| GET | `/chatbot/inbox/conversations` | `chatbot.inbox.conversations` | Chatblast access | 120/min |
| GET | `/chatbot/inbox/thread` | `chatbot.inbox.thread` | Chatblast access | 240/min |
| GET | `/chatbot/inbox/panel` | `chatbot.inbox.panel` | Chatblast access | 120/min |
| POST | `/chatbot/inbox/send` | `chatbot.inbox.send` | CEO or a role in `chatbot.reply_roles` | 30/min per user |
| POST | `/chatbot/inbox/pause` | `chatbot.inbox.pause` | same as send | 60/min |
| POST | `/chatbot/inbox/roles` | `chatbot.inbox.roles` | CEO | 30/min |

Field edits reuse the existing `POST /chatbot/customer-fields`, which is CEO only.

## What was built

### Brief

- **Nav:** the tab "Inbox" sits after Chats.
- **Left column:**
  - page selector (pages with a Botcake ID, remembered in localStorage) and a ⚙ "Who can reply" menu (CEO only);
  - search by name or PSID prefix, and the window 24h / 7d (default) / 30d;
  - All / Human / Bot tabs;
  - each row: name or "PSID …1234", preview ("Bot: " / "You: " for ours), relative time, a dot when the customer is unanswered, and a "Human" tag when paused.
- **Middle column:**
  - customer bubbles on the left (grey), ours on the right (brand blue) with "Bot" or the sender's name;
  - images open full size; 👍 shown large; time on hover, tap or focus;
  - Today / Yesterday / date separators;
  - failed and skipped replies show the reason; sheet rows are system lines;
  - opens at the bottom, "Load older" 50 at a time;
  - a 5-second poll asks only for newer items and keeps your scroll position, with a "New messages ↓" pill.
- **Right column:**
  - name, PSID, ad_id, source, first seen;
  - CHAT STATUS and "Waiting for reply at step nX";
  - the bot switch;
  - fields: `__` keys hidden; long values open in a modal; the CEO can edit or clear them there;
  - Page botfields: collapsible, read-only, with a link to Page settings;
  - the flow, with a link to the editor.
- **Phone:** one column at a time with back buttons. No horizontal scroll at 390.
- **Volume:**
  - the list is two GROUP BY psid queries, each bounded by page, window and LIMIT 500, merged to at most 200 rows;
  - the details are read by id;
  - the thread reads 50 per table by id cursor and merges by time;
  - polling reads only ids above the cursors.

### Amendment 1

- **The reply box:**
  - text plus "Attach image URL" (http/https only, with a preview);
  - Enter sends, Shift+Enter makes a new line;
  - the body is the same `send_content` body the bot sends: text, then image, no tag.
- **The 24-hour rule:** checked on the server from the customer's last `botcake_chats` row. Outside it, the box is disabled with "Outside Messenger's 24-hour window: the customer must message first.", and the server answers 422.
- **The log:** each send is a `chatbot_replies` row with kind `manual`, `user_id`, content, status and error. It shows in the thread and in Replies as "Manual by <name>: …".
- **The pause:**
  - "Bot for this customer: On/Off" stores `__bot_paused=1`;
  - while paused, the runner marks each chat handled, sends nothing, and records a "bot paused" event;
  - the first manual send in a thread asks "Pause the bot for this customer?".
- **Who can send:** the CEO, or roles in `app_settings.chatbot.reply_roles` (CEO always included), set by the CEO in the ⚙ menu. They also need Chatblast access. Throttle: 30 a minute per user.

### Amendment 2

- **`chatbot_events`:**
  - recorded per run: started (new / resumed after wait), condition result, field set / append / clear / all cleared, AI step (success / failed, model, saved to, never the prompt), sheet row, waiting at step nX, and run ended (with reason and path);
  - skipped runs (bot paused, chat too old) are recorded too, with the reason;
  - one batched insert per run;
  - each event keeps its own time in the run;
  - values are cut to 200 characters;
  - pruned after 30 days by `botcake:prune-chats` and the daily prune.
- **"View executed actions":** a checkbox, remembered in localStorage. It shows the events as centered grey lines with icons, in time order, within the same window as the bubbles, e.g. "Custom field value changed — CHAT STATUS was set to LOOP 1", "Condition n3 → row 1", "AI step n4 → success (saved to GPT INPUT)".
- **Panel sections:** Executed flows/steps (the last 20 runs: time, flow, path, result), Error logs (failed sends, sheet rows and AI steps), Media and files (the customer's images).
- **Human / Bot tabs:** Human = paused, Bot = not paused.

## Case table

Tests are in `tests/Feature/Chatblast/ChatbotInboxTest.php` and `tests/Feature/Chatblast/ChatbotInboxActionsTest.php`. The last run is on `3c03ff37`.

| Case | Test | Result |
|---|---|---|
| S-074.1 list order, preview, dot, windows, search, unknown page | `test_S_074_1_the_list_is_newest_activity_first_with_a_dot_when_the_customer_waits` | pass |
| S-074.2 thread merge with failed, skipped, sheet, photo, Like | `test_S_074_2_the_thread_merges_both_sides_in_time_order_with_failed_skipped_sheet_photo_and_like` | pass |
| S-074.3 load older by 50 (including a table with nothing on the first page) and newer-only polling | `test_S_074_3_load_older_pages_by_50_and_polling_returns_only_newer_items` | pass |
| S-074.4 panel: fields, `__wait` humanized, `__last_chat` hidden, botfields, flow | `test_S_074_4_the_panel_shows_the_customer_fields_the_wait_and_the_page` | pass |
| S-074.5 403 without Chatblast; nav; CEO-only field edits | `test_S_074_5_only_the_ceo_edits_fields_and_the_inbox_needs_chatblast_access` | pass |
| S-074.6 bounded queries (and the pause lookup by listed customers), indexes | `test_S_074_6_the_list_and_thread_queries_are_bounded_by_page_and_time_or_customer` | pass |
| S-074.R1 exact body, manual log, "by" name, Replies summary, no token | `test_S_074_R1_a_manual_reply_inside_the_window_posts_the_exact_body_and_logs_it_as_manual` | pass |
| S-074.R2 outside 24 h / bad image / empty / too long → 422, nothing sent | `test_S_074_R2_outside_24_hours_or_a_bad_image_nothing_is_sent` | pass |
| S-074.R3 roles, the CEO-only gear, reply role without Chatblast → 403, throttle 429 | `test_S_074_R3_only_allowed_roles_send_the_ceo_sets_them_and_sends_are_throttled` | pass |
| S-074.R4 paused → nothing sent, chat handled, pause set before the send; unpause resumes | `test_S_074_R4_a_paused_customer_gets_no_bot_runs_until_unpaused` | pass |
| S-074.E1 CBB-like flow events: one insert, no prompt, 200-character cut, events between the bubbles by their own time | `test_S_074_E1_a_run_writes_its_events_in_one_insert_without_the_prompt` | pass |
| S-074.E2 executed flows, error logs, media | `test_S_074_E2_the_panel_lists_runs_errors_and_media` | pass |
| S-074.E3 Human / Bot tabs, 30-day prune, old events not re-polled | `test_S_074_E3_human_and_bot_tabs_follow_the_pause_and_events_are_pruned_after_30_days` | pass |
| S-069 T1 nav (updated: Inbox after Chats) | `ChatbotTabsTest` | pass |

**Red runs of the review fixes (test first):**
- S-074.3: "old reply 1" missing from Load older.
- S-074.6: `"psid" in (` missing from the pause lookup.
- E1: "out: It is 499." came before run_started.
- E3: the 29-day-old event came back on the poll.
- R4: BOT_PAUSED was null at send time.

R3's new 403 check and R1's token check passed at once; they pin behaviour that was already right.

## Full suite

On `3c03ff37`, run alone: **2 failed, 175 passed, 1774 passed with warnings** (20423 assertions, 285 s).

The two failures are the known ones, the same as in the 069 result, and are outside this work:
- `ExampleTest` ("the application returns a successful response");
- `NightRun\ImportStartTest` ("macro job final write applies only while the run is still active").

Every Chatblast test passes, including the 13 inbox tests and the updated `ChatbotTabsTest`.

## Browser check

Headless Chrome over CDP against a scratch app:
- `php artisan serve` on 127.0.0.1:8074, with a scratch sqlite file outside the repo;
- fake tokens, `CHATBOT_REPLIES_ENABLED=false`, no OpenAI key;
- three pages; one customer with 60+ items over three days (Likes, failed and skipped replies, a photo, a sheet row, events and a manual reply), seven others (one paused, one past 24 hours), and one on another page.

**Send was never pressed:** the Botcake base URL is fixed in code, so the check stopped at the filled form. The pause switch and the roles menu only write the scratch database.

| Check | Result |
|---|---|
| Nav | Flow, Page settings, Chats, **Inbox** (current), Replies, Test, Chatblast ✔ |
| List | 8 rows newest first; 5 dots (customer last, or our last reply failed); "Bot: …" previews; "Human" tag on the paused one ✔ |
| Thread opens | 50 items, at the bottom, Load older shown, Yesterday / Today separators, 2 images, Likes large, 2 failed labels, the sheet system line, the manual bubble labelled with the user's name ✔ |
| Load older | 50 → 71, the view stays where it was (0 px shift), no duplicates ✔ |
| Polling | Scrolled up + a new chat → "New messages ↓" pill, position kept; at the bottom + a new chat → auto-scroll, no pill ✔ |
| Executed actions | Checkbox on → the 8 event lines in order; remembered after reload ✔ |
| Panel | Sections open; the long GPT INPUT field opens in a modal with Save / Clear (CEO); Escape closes it ✔ |
| Reply box | Image preview shows; `javascript:` link → "The image link must start with http:// or https://." ✔ |
| Pause switch | Off → "Human" tag in the list; back On ✔ |
| Tabs | Human = the paused customer; Bot = the other 7 ✔ |
| Past 24 hours | Box and Send disabled, the 24-hour note shown ✔ |
| ⚙ roles | CEO (fixed) plus the roles from employee profiles ✔ |
| Page selector | Other page → its one customer; remembered after reload ✔ |
| Keyboard | Tab from search reaches the rows; Enter opens the conversation ✔ |
| Phone 390 × 844 | List → thread → panel and back, one column each, page width 390 = 390 everywhere ✔ |
| Console and HTTP | No errors, no 4xx/5xx ✔ |

**Bugs the check found, fixed in `5b590c08`:**
- The thread didn't end at the bottom when images or the reply box loaded after the first scroll. A ResizeObserver now keeps it pinned while you are at the bottom, and it ignores its own scroll events.
- The back buttons showed on desktop, because the button style overrode the phone-only rule.
- The image-link row stayed open when switching customers.

Screenshots are in `docs/specs/074-chatbot-inbox/`:
- `inbox-list.png`, `inbox-desktop.png`, `inbox-pill.png`, `inbox-events.png`, `inbox-panel-sections.png`;
- `inbox-field-modal.png`, `inbox-reply.png`, `inbox-outside-window.png`, `inbox-roles.png`;
- `phone-inbox-list.png`, `phone-inbox-thread.png`, `phone-inbox-panel.png`.

The scratch server was stopped. Its files (`%TEMP%\cb074`) were left in place, because the guard blocks deletion.

## Review

`skeptic-reviewer`, adversarial depth on opus, on `3872de07..3e7aa160`.

**First pass: 3 majors and 1 missing auth test. All fixed in `5b590c08`, test first:**
1. **Events were stamped at insert time,** so a run's actions could show below the replies they produced. Each event now keeps its own time.
2. **The Human/Bot lookup read every customer-value row of the page** on each 5-second refresh. It now reads only the listed customers, in chunks of 500.
3. **Load older lost a table** whose rows were all older than the first 50 (its cursor became 0). The cursor is now its largest fetched id + 1.
4. **Missing test:** a role in `reply_roles` without Chatblast access gets 403 on send and pause.

**Minors fixed:**
- the 200-character cut is tested;
- event polling starts at the customer's newest event (old events were re-polled);
- pause is set before the Botcake call;
- the token is asserted absent from the response.

**Re-check of `5b590c08`:** all fixes hold, with no new blocker or major. Two new minors:
- the newest event id was read after the event list, so a run written in between could be missed by polling. Fixed in `3c03ff37`.
- with "pause" on, a refused send leaves the customer paused and unanswered. Accepted: staff see "Not sent" and the switch shows Off.

**Accepted in `TODO.md` (section "074"), with reasons:**
- the list regroups the window every 5 seconds;
- the tabs filter after the 500-customer group limit;
- the shared Botcake rate key;
- a double submit of Send;
- manual sends ignore the emergency stop;
- same-second order;
- the deploy notes.

## Story changes

- **S-069 T1 (nav):** now expects Inbox after Chats. Reason: the brief adds the tab.
- **S-074.3:** adds a table with nothing on the first page. Reason: review finding 3.
- **S-074.R1:** also checks the Replies summary ("Manual by …") and that the token is absent. Reason: the brief says manual sends show in Replies; the review asked for the token check.
- **S-074.R3:** adds a reply role without Chatblast access. Reason: review.
- **S-074.R4:** checks the pause is set before the send. Reason: review.
- **S-074.E1:** checks events between the bubbles by their own time, and the 200-character cut. Reason: review.
- **S-074.E3:** checks old events are not re-polled. Reason: review.

## What the brief got wrong, or left open

- **"Never show tokens or keys" vs a reply box:** the send needs the page token server-side only. It never reaches the browser or a response, and R1 pins that.
- **The emergency stop:** amendment 1 doesn't say whether `CHATBOT_REPLIES_ENABLED=false` blocks manual sends. They are **not** blocked: the switch stops the bot, and a person chose to send. Listed in TODO for the owner to confirm.
- **"Events between the bubbles":** times are whole seconds, so items in the same second sort customer → event → reply. A run's last lines (sheet, waiting, ended) can show above the reply sent in that same second.
- **Pause with a failed send:** the pause is set before the send, so the bot can't answer on top of a person while Botcake is slow. If Botcake then refuses, the customer stays paused; staff see "Not sent" and the switch.
- **"Load older" for events:** events load only for the window of the bubbles shown, so a run with no bubble nearby (e.g. only skipped chats) shows only when its time is in range.
- **Who can send also needs Chatblast access:** the brief says "NavAccess users whose role is …", so a role in `chatbot.reply_roles` without Chatblast access gets 403.

## Release steps (for whoever deploys)

1. Run `php artisan migrate --force`; it runs the two migrations above. Run it at a quiet hour: the new index on `botcake_chats` is a long ALTER on MySQL.
2. No new settings or keys. `chatbot.reply_roles` defaults to CEO only; the CEO adds roles in the inbox ⚙ menu.
3. The `chatbot` queue worker from 069 is unchanged.

## Merge danger

- **Migration order:** deploy the code and run the migrations together. Until `chatbot_replies.user_id` exists, a manual send reaches the customer but its log entry fails with a 500, which invites a resend. Before `chatbot_events` exists, runs continue (the insert error is swallowed) and the inbox shows no events.
- **Shared files:**
  - `ChatbotRunner` now checks `__bot_paused` before each run;
  - `CustomerFields::clearAll` keeps `__bot_paused`;
  - `FlowEngine` records events;
  - `ChatbotTabsController` summary has a manual prefix;
  - the nav has a new tab.

  Any branch that touches these files will conflict in small, obvious ways.
