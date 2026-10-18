# Result 069: our own chatbot flow builder that replies through Botcake send_content

Branch `feat/069-chatbot-flow-builder`, cut from `main` at 7187cd60. Not pushed.

No real Botcake, OpenAI or Google call was made:
- **tests:** `Http::fake` with `preventStrayRequests`, and a fake sheet;
- **browser check:** a scratch app with replies switched off, no OpenAI key, and a flow with no AI step.

No page and no global switch was turned on anywhere.

## Commits

| Commit | Slice | What |
|---|---|---|
| `e82d3b17` | | docs: the brief, verbatim |
| `c986e54c` | 1, engine | Tables, the graph rules, one interpolator, customer fields, the engine with all 7 step types, the 100-step limit, the formula guard |
| `0161e1ed` | 2, sending | `BotcakeClient::sendContent`, the live outbox (switch, reply log), the sheet writer, the runner (queue, lock, run once, order), the trigger in the chat endpoint |
| `b7654476` | 3, editor | The Flow tab (list, create, rename, duplicate, delete), the canvas editor, graph save with per-port checks, `/chatbot/pages` with FLOW as a dropdown |
| `01217db5` | 4, tabs | The chatbot nav, Chats under /chatbot with customer fields, Replies, Test |
| `3c8a0430` | browser check | Editor: `{{ }}` is no longer eaten by Blade; a dropped wire lands on the step, not on the preview line |
| `6368bb8b` | review, loop 1 | No replay of old chats; pages bound to a flow by id; sheet cells from variables are always text; AI cap per run; OpenAI errors without detail; replies data throttled |
| `69616f6e` | review, loop 2 | The page list shows the flow a page is really linked to, and old FLOW text as "Not linked" |
| (this commit) | | docs: result, stories, TODO, screenshots |

## Tables

`2026_10_23_100000_create_chatbot_tables.php` creates three tables, each guarded with `Schema::hasTable`:
- **`chatbot_flows`:** id, `name` (100, unique), `graph` (longText JSON `{"nodes":[…],"edges":[…]}`), `updated_by`, timestamps.
- **`chatbot_customer_values`:** id, `page_id` BIGINT UNSIGNED, `psid` BIGINT UNSIGNED, `key` varchar(100), `value` TEXT, `updated_at`.
  - UNIQUE(page_id, psid, key). One row per key, written with upsert in place.
  - Keys are normalised: lower case, spaces and `_` are the same.
  - Run state uses the reserved keys `__wait` (`"flowId:nodeId"`) and `__last_chat` (the last chat id handled).
  - PSID `0` is the Test tab's customer.
- **`chatbot_replies`:** the log of every send and sheet row.
  - Columns: `page_id`, `psid`, `chat_id`, `flow_id`, `node_id`, `kind` (send / sheet), `content` JSON, `status` (sent / failed / skipped), `error`, `http`, `sent_at`.
  - Indexes: (page_id, sent_at), (psid), (sent_at).

A page is linked to a flow by the system page setting `chatbot.flow_id`, which only the dropdown writes. The `chatbot.flow` text keeps the name for display.

## Step types and their config shapes

| Type | Config | Ports |
|---|---|---|
| Start | `{}`; exactly one per flow | `next` |
| Condition | `{"rows":[{"variable","rule","value"}]}` (up to 20). Rules: `is`, `is_not`, `contains`, `contains_any` (one per line or comma), `not_contains`, `starts_with`, `regex`, `is_empty`, `is_not_empty`, `gt`, `lt`. Text compares ignore case; the value may hold variables. | `row_1` … `row_N`, `no` |
| Send message | `{"items":[{"type":"text"\|"image","value"}], "wait":bool, "save_to":field}` (up to 10 items, 2000 characters each) | `continue`, or `reply` when it waits |
| Set field | `{"ops":[{"op":"set"\|"append"\|"clear"\|"clear_all","field","value","separator"}]}` (up to 20) | `next` |
| AI | `{"model":"gpt-4o-mini","system","user":"{{last_input}}","history":0–20,"max_tokens":1–4000 (250),"save_to","send":bool}`; uses `services.openai.key` | `success`, `failed` |
| Google Sheet | `{"sheet":"link or ID","tab","columns":[template per line]}` (up to 50) | `next`, `failed` |
| End | `{}` | none |

**Graph limits:** at most 200 steps and 1000 wires. Every wire must leave from a port of its step's type. A wire to a step outside the flow, a self-loop, or two wires on one port is refused with 422 and a plain message; the saved graph stays.

**Variables:** one interpolator, `{{name}}`, single pass. Customer text is never read as a variable.
- **Run:** last_input, full_name, first_name, psid, page_id, page_name, current_date, last_interaction.
- **Botfields:** by key or column label, expanded one level.
- **Customer fields.**
- Later sources win. Unknown names give empty text.

## Runtime design

- **Trigger:** after `BotcakeChat::create` in `/api/botcake/chat`, a page that is On and linked to a flow (`chatbot.flow_id`) dispatches `RunChatbotFlow` on the queue **`chatbot`**.
  - The check returns null when the table doesn't exist yet.
  - A failure here is reported and never touches the stored chat.
- **Lock:** `ChatbotRunner` takes a cache lock per page and PSID for 300 s. When it is busy, the job goes back to the queue after 3 s, until 30 minutes have passed (`retryUntil`). The job's timeout is 280 s.
- **Order and run once:**
  - Inside the lock, the customer's chats newer than `__last_chat`, up to this one, run in id order.
  - `__last_chat` is written before each chat runs. A retry or a late job for an earlier chat therefore does nothing: a chat runs at most once.
  - A customer's first chat after linking runs alone: no replay of older chats.
  - A chat older than 30 minutes is marked done but not answered.
- **Resume or start:**
  - When `__wait` points at a "wait for reply" step of the same flow, the reply is saved to its field and the run follows `reply`.
  - Otherwise, or when the step is gone, the run starts at Start.
- **Limits:** 100 steps per run and 10 AI calls per run. A step that fails follows its `failed` port when it has one; otherwise the run stops and logs a warning.
- **Sending:** `BotcakeOutbox`.
  - With `CHATBOT_REPLIES_ENABLED` off (the default), every send and sheet row is logged as "skipped" with "Replies are switched off".
  - With it on, `send_content` goes out with the exact body `{"psid","data":{"version":"v2","content":{"messages":[…]}}}`, with no message tag. It retries only on 429 (a 5xx may have been received), and the call shares the page's Botcake rate key.
  - Every attempt goes to `chatbot_replies`, without the token.
  - Sheet rows go through the existing Google client (`credentials.json`) as USER_ENTERED. Every cell built from a variable starts with `'`, so it is never a formula, a number or a date.
- **Test:**
  - It uses `TestOutbox` (nothing sent or written) and the PSID 0 customer, with its own fields.
  - An AI step calls OpenAI for real, and the screen says so.
  - "Reset test customer" deletes PSID 0's fields for that page.

## Routes

All routes sit under `web`, `auth` and `allowed_ip`. Signed out gets 302. Without `/chatblast` access (NavAccess) every route gives 403, including `/chatbot/chats`.

| Method | Path | Notes |
|---|---|---|
| GET | `/chatbot` | Flow list (name `chatbot.index`) |
| POST | `/chatbot/flows` | Create |
| POST | `/chatbot/flows/{id}/rename` | Linked pages follow the new name |
| POST | `/chatbot/flows/{id}/duplicate` | |
| DELETE | `/chatbot/flows/{id}` | 422 while a page uses the flow |
| GET | `/chatbot/flows/{id}` | Editor |
| GET / PUT | `/chatbot/flows/{id}/graph` | Load / save (checked per port) |
| GET | `/chatbot/pages` | The 068 page list, moved; its JSON/POST routes keep their names and paths |
| GET | `/chatbot/chats` and `/chatbot/chats/customer` | Same view as /chatblast/chats |
| POST | `/chatbot/customer-fields` | CEO only; `__` keys refused |
| GET | `/chatbot/replies`, `/chatbot/replies/data`, `/chatbot/replies/{id}` | Replies data is always for one date, throttled at 60 a minute |
| GET | `/chatbot/test` | |
| POST | `/chatbot/test/send`, `/chatbot/test/reset` | Throttled at 30 a minute |

Write throttles: flows 30/min, graph saves 120/min, customer fields 60/min. Nothing on these routes sends to customers. The only sending path is the queued run, and it obeys the switch.

## Case table

Every case passed on 2026-10-19. Story IDs are in `qa/stories.md` under S-069.

| Case | Test | Result |
|---|---|---|
| S-069.E1 | `ChatbotEngineTest::test_S_069_E1_the_owners_main_flow_runs_end_to_end` (new chat → form → phone → LOOP 2 → AI → sheet → exitloop) | pass |
| S-069.E2 | `…E2_a_wait_in_another_flow_or_a_removed_node_starts_from_the_start` | pass |
| S-069.E3 | `…E3_every_condition_rule` (17 data sets) | pass ×17 |
| S-069.E4 | `…E4_the_first_matching_row_wins_and_no_match_exits_on_no` | pass |
| S-069.E5 | `…E5_every_set_field_operation` | pass |
| S-069.E6 | `…E6_the_ai_step_follows_success_or_failed` | pass |
| S-069.E7 | `…E7_a_sheet_cell_with_a_variable_is_never_a_formula` (5 data sets) | pass ×5 |
| S-069.E8 | `…E8_variables_ignore_case_and_spaces_and_unknown_ones_are_empty` | pass |
| S-069.E9 | `…E9_a_loop_stops_after_100_steps` | pass |
| S-069.E10 | `…E10_a_failed_send_without_a_failed_port_stops_the_run` | pass |
| S-069.S1 | `ChatbotSendingTest::…S1_a_send_node_calls_send_content_with_the_exact_body_and_logs_it` | pass |
| S-069.S2 | `…S2_with_replies_switched_off_the_run_happens_but_nothing_is_sent_or_written` | pass |
| S-069.S3 | `…S3_a_failed_send_is_logged_and_stops_the_run_and_a_live_sheet_row_is_written` | pass |
| S-069.S4 | `…S4_a_stored_chat_queues_a_run_on_the_chatbot_queue_only_for_a_page_with_a_flow` | pass |
| S-069.S5 | `…S5_a_chat_is_run_at_most_once_and_a_customers_chats_run_in_order` | pass |
| S-069.S6 | `…S6_a_customer_already_running_waits_and_a_page_switched_off_since_does_not_run` | pass |
| S-069.D1 | `ChatbotEditorTest::…D1_the_graph_saves_and_loads_cleaned` | pass |
| S-069.D2 | `…D2_a_bad_graph_is_refused_and_the_saved_one_stays` (12 data sets: ports, foreign node, self-loop, duplicate wire, starts, type, id, sizes) | pass ×12 |
| S-069.D3 | `…D3_flows_are_created_renamed_duplicated_and_deleted` | pass |
| S-069.D4 | `…D4_the_flow_pages_need_chatblast_access` (also pins the editor's script against Blade) | pass |
| S-069.D5 | `…D5_the_page_list_flow_is_a_dropdown_of_flows` | pass |
| S-069.T1 | `ChatbotTabsTest::…T1_the_chatbot_section_has_its_own_nav_and_links_back_to_chatblast` | pass |
| S-069.T2 | `…T2_chats_show_under_chatbot_and_the_ceo_edits_a_customers_fields` | pass |
| S-069.T3 | `…T3_replies_list_one_date_with_filters_and_details` | pass |
| S-069.T4 | `…T4_test_runs_the_flow_for_a_test_customer_without_botcake_or_the_sheet` | pass |
| S-069.T5 | `…T5_the_tabs_need_chatblast_access` | pass |
| S-069.H1 | `ChatbotHardeningTest::…H1_a_customer_with_old_chats_gets_one_run_for_the_new_chat_only` | pass |
| S-069.H2 | `…H2_a_page_runs_only_the_flow_picked_in_the_dropdown_not_old_flow_text` | pass |
| S-069.H3 | `…H3_a_cell_built_from_a_variable_is_always_text` (5 data sets) | pass ×5 |
| S-069.H4 | `…H4_customer_text_is_never_read_as_a_variable` | pass |
| S-069.H5 | `…H5_ai_history_is_only_this_customers_on_this_page_and_only_sent_replies` | pass |
| S-069.H6 | `…H6_config_caps_ai_calls_per_run_and_openai_errors_without_detail` | pass |
| S-069.H7 | `…H7_the_page_list_shows_the_linked_flow_and_marks_old_flow_text_as_not_linked` | pass |

**Red runs:**
- **Slice 1 (engine):** written before its tests. That breaks the kit's test-first rule and is recorded in `TODO.md`. The first runs then failed on test-side errors: a helper named `run()` clashing with PHPUnit, then field order. One real gap surfaced: botfields holding variables were not expanded. It was fixed before the commit.
- **Slice 2:** all 6 tests failed first (the classes didn't exist).
- **Slice 3:** all tests failed with 404 (no routes yet).
- **Slice 4:** all 5 tests failed first, on 404s and the missing nav.
- **Fix loop 1:** H1, H2, H3 and H6 failed first. H4 and H5 passed at once and pin existing behaviour.
- **Fix loop 2:** H7 failed first.

`php artisan test tests/Feature/Chatblast`: every test passes (1979 assertions). The 067 and 068 tests are in that folder; the PageSettings and Botcake folders pass too.

## Full suite

`php artisan test` on `69616f6e` (the final code): **2 failed, 175 passed, 1735 passed with warnings** (20065 assertions, 373 s). The only failures are the known `ExampleTest` and `NightRun\ImportStartTest`, so there are no new failures.

Earlier runs while other test processes were running at the same time also failed `NightRun\AstraReplayCommandTest` S-31.3 ("changes no file") and `AstraAddressRulesTest` S-26.18. Both pass when the suite runs alone.

## Browser check

Headless Chrome over CDP (the session has no browser tools), against a scratch app:
- `php artisan serve` on 127.0.0.1:8069, with a scratch sqlite file under `%TEMP%`;
- 12 made-up pages with fake tokens, 30 reply rows;
- replies switched off, no OpenAI key.

| Check | Result |
|---|---|
| Nav on /chatbot | Flow (current), Page settings, Chats, Replies, Test, Chatblast ✔ |
| Build a 6-step flow in the editor with the toolbar, the forms and real pointer drags (Start → Set field → Condition → Send message with wait → Google Sheet → End, 6 wires) | 6 steps and 6 wires saved by autosave ✔ ("Saved 04:46:53") |
| Pick it as a page's FLOW in Page settings | Saved; other pages show their 068 text as "Not linked: …" ✔ |
| Test: "What is the price?" | "Price is 299 pesos. How many?" (botfield PRICE), then waits for the reply ✔ |
| Test: "2 pcs please" | Resumes; shows the sheet row `'TEST \| '2 pcs please \| 'LOOP 1` as not written; fields `answer`, `chat status` ✔ |
| Replies | 28 rows on the date; the header stays sticky in its box (1 px offset after scrolling); the details modal opens ✔ |
| No page-level horizontal scroll at 390 × 844 | Flow, editor, Test, Replies and Page settings all at 390 ≤ 390 ✔ |

**Two real bugs found by the check and fixed in `3c8a0430`:**
- **The editor's script was broken by Blade:** the `{{` and `}}` constants rendered empty, so the editor didn't load at all. The PHP tests couldn't see it; S-069.D4 now pins the rendered script.
- **Dropping a wire exactly where the preview line ended missed the step.** The preview path now ignores pointer hits.

Screenshots are in `docs/specs/069-chatbot-flow-builder/`:
- `flows.png`
- `editor.png`
- `editor-form.png`
- `pages.png`
- `test.png`
- `replies.png`
- `replies-detail.png`
- `phone-editor.png`, `phone-test.png`, `phone-replies.png` and `phone-pages.png`

The scratch server was stopped. Its files (`%TEMP%\cb069`, and gitignored sessions and cache in this worktree's `storage/framework`) were left, because the project guard blocks file deletion.

## Review

`skeptic-reviewer`, adversarial depth on opus, on `e82d3b17..01217db5`.

**First pass: 1 blocker and 3 majors, plus 3 missing-test majors.** All are fixed in `6368bb8b`:
- **blocker:** a customer's first chat after a page got a flow replayed every older chat of theirs: dozens of sends, AI calls and sheet rows. Now only the triggering chat runs, and stale chats (over 30 minutes) are not answered (H1).
- **major:** pages were bound to a flow by name. FLOW text left from 068 such as "Main Flow" would have started a half-built flow on many pages the moment a flow with that name was created. Pages now run only the flow picked in the dropdown, by id (`chatbot.flow_id`, system-only). Rename, delete and "used by" go by id (H2).
- **major:** USER_ENTERED turned phone numbers into numbers (the leading 0 was lost). Every cell built from a variable is now text (H3).
- **missing tests:** customer text with `{{…}}` stays literal (H4); the AI history scope (H5); the caps (H6).
- **Minors fixed in the same commit:**
  - an AI cap per run (10);
  - OpenAI errors without their message (it can hold a masked key);
  - replies data throttled.

**Re-check of loop 1:** all the above closed, with one new major. The page list's dropdown still showed old FLOW text as if linked. Fixed in `69616f6e`: the data now carries the linked flow, and old text shows as "Not linked: …" (H7). That second loop got no re-check (the 2-loop cap).

**Accepted minors, with reasons in `TODO.md`:**
- the queue worker setup;
- AI calls and field changes while replies are off;
- a customer's very first two chats arriving together;
- free-text model names;
- Test calling OpenAI for any role with access;
- the sheet step targets;
- regex values with variables;
- the Botcake rate shared with chatblast;
- light per-run caching;
- the Replies search cost;
- editor last-write-wins;
- collation of field keys;
- the 16,000-character append cap.

## Story changes

- **S-068.1:** opens `/chatbot/pages` (the list moved). Its FLOW text now comes back as `flow_text` ("Not linked") rather than as the linked flow.
- **S-069.S5:** the customer has already talked to the bot (has a marker) before two new chats run in order. A first contact only answers its own chat (H1).
- **Sheet-row expectations in E1, E7, S2, S3 and T4:** cells built from variables now start with `'` (H3).
- **Tests that linked a page by writing the FLOW text:** they now link through the dropdown, or `ChatbotPage::link()`.
- **S-069.H1 to H7:** added from the review.

## What the brief got wrong, or left open

- **"Whose flow names a flow":** binding by name would have started flows on pages that still hold 068 FLOW text. Pages are bound by id through the dropdown; the name stays for display.
- **"Runs still happen" with the switch off:** they still call OpenAI and change real customer fields. Only sends and sheet rows are skipped. Keep pages unlinked until the flow is ready.
- **A queue worker for `chatbot` is not in this repo.** Production needs `queue:work --queue=chatbot` under supervisor before any page is linked.
- **The formula guard from TeleSMS only covered `= + - @`.** Under USER_ENTERED, Google also turns phone numbers and dates into numbers. Every cell built from a variable is now text.
- **A page with no `active` row counts as On** (068), so in practice the gates are "linked to a flow" plus the global switch.
- **The Chats access rule:** `/chatblast/chats` gives 404 without access; `/chatbot/chats` gives 403, as the brief asks for `/chatbot/*`.
- **The layout:** the Chatblast layout has no sticky top bar, by an earlier owner rule. Here the table and canvas boxes scroll inside, with sticky table headers. The top bar and filters stay in view on desktop because the page itself doesn't scroll.
- **Sequences (070):** no nav slot was added.

## Release steps (for whoever deploys)

1. Run the migration `2026_10_23_100000_create_chatbot_tables`.
2. Start a supervisor worker: `php artisan queue:work --queue=chatbot`.
3. Leave `CHATBOT_REPLIES_ENABLED=false`.
4. Build the flow and try it in Test.
5. Link one page in Page settings and watch Replies (everything shows as "skipped").
6. Then flip the switch.

## Merge danger

- **Medium.** The chat endpoint gains a flow lookup after each stored chat (wrapped in try/catch; null before the migration).
- **`/chatbot` changes from the page list to the Flow tab.** The list moved to `/chatbot/pages`; its JSON/POST routes are unchanged.
- **New system keys:** `chatbot.flow_id`, and `chatbot.active` from 068.
- **Nothing replies until** a page is linked and the switch is on.
