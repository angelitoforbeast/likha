# Result 192: an ad group can set every value a page can

Status: done. Branch `feat/192-ad-group-all-values`, based on eb34bc82. Not pushed, not deployed.

## What shipped

1. **Every page value is a group value** (`App\Services\Chatbot\AdGroups`).
   - The fixed `VALUE_KEYS` list is gone. `AdGroups::isValueKey` now accepts any botfield-shaped key (`a-z 0-9 _`, up to 100) that is not in `AdGroups::EXCLUDED`.
   - On save, a key must also be a real value of the page: a column of /chatbot/pages (`ChatbotFields::labels()`: the defaults, "+ Column" columns and any key in use), `flow_id`, a `seq_N` / `seq_img_N` key (as before), or one of the page's AI keys. Anything else gets "<key> is not a value of the page."
   - Values are checked like the page's own cells: length and bytes, image links (`ChatbotFields::valueError`), an existing flow for `flow_id`. The AI keys are checked like the page's AI dialog (`ChatbotAiSettings::clean`): Local must be set up and have a model somewhere (group, page, site or config), and "default" is refused because an empty value already means "the page's".
   - An excluded key is refused, on Save, the cell edit and Restore alike, with: "<key> is the page's own setting (identity or a switch): it is set on the page only, not in a group." Nothing is written.
   - Storage, history, Restore, the per-page lock and the access check are unchanged from 172/174. Existing group rows keep working: their keys are a subset of the new ones.
2. **The final key lists.**

   **Included (a group can set it):**

   | Kind | Keys |
   |---|---|
   | Flow | `flow_id` (the FLOW select of the group) |
   | Item and shop details fields | `item_name_in_jnt_dashboard`, `price`, `quantity`, `picture` (SHOP DETAILS is rebuilt from them) |
   | Flow texts | `main_flow`, `main_flow_2` |
   | Forms | `fill_up_form`, `loop_3_form` |
   | Prompts | `prompt_1`, `prompt_2`, `prompt_3` |
   | Sequence | `seq_1`–`seq_20`, `seq_img_1`–`seq_img_20` (and `seq_N` / `seq_img_N` up to 99, as before) |
   | After order | `after_order_1`–`after_order_4`, `after_order_img_1`–`after_order_img_4` |
   | Comment replies | `comment_reply_1`–`comment_reply_3`, `comment_pm` |
   | Other value columns | every "+ Column" column and any other chatbot value key in use on a page |
   | The page's AI | `ai_provider`, `ai_fallback`, `ai_local_model`, `ai_local_timeout` |

   **Excluded (`AdGroups::EXCLUDED`, page only):**

   | Why | Keys |
   |---|---|
   | Switches | `active` (On/Off), `provider` (Botcake / Forbeast), `mode` (the Ad-based switch itself) |
   | Identity and connection | `page_id` (Botcake ID), `token`; the other connection keys live under other providers and never reach a group |
   | Not values | `auto_switch` (the auto-switch history), `mock_ai`, `mock_ai_latency_ms` (stress-test switches), `flow` (the FLOW text; the group's flow is `flow_id`), `shop_details` (built, read-only) |
   | Site-wide AI (no per-page value) | `ai_local_concurrency`, `ai_breaker_failures`, `ai_breaker_seconds`, `ai_local_reasoning_effort` |
   | The group's own rows | `name`, `ads` (a "+ Column" with these names stays page-only so it cannot overwrite `gN.name` / `gN.ads`) |
3. **The group value reaches every reader** (empty in the group = the page value; Page-based pages ignore groups as before).
   - The run, sequences, Test, the sheet preview and the order (SHOP DETAILS, PRICE, …) already read `ChatbotPage::botfields($page, $group)`. It now carries every group value, so forms, loop forms, prompts, after-order fields and the rest arrive with no other change.
   - **The AI choice.** `ChatbotAiChain::forPage($page, $group)` lays the group's AI keys over the page's.
     - The waiting call: `ChatbotAi::answer` passes `$run->adGroup`.
     - The pool: `ChatbotAiQueue::submit` stores `ad_group` in the ticket's `context` (no new column), and `ChatbotAiChain::forRow` reads it in `ChatbotAiPool::send` and `ChatbotAiQueue::late`. A ticket from before this change uses the page's AI.
   - **The resumed run.** After the AI pool or the Botcake send pool, the run continues with the group stored on the ticket while that group is still in use on the page. Before, it re-read the customer's latest ad, so a pinned sequence could switch groups midway. `BotcakeSendQueue` stores `ad_group` the same way. Otherwise it falls back to the latest ad, as before.
   - **The Test tab** now sets `$run->adGroup`, so the chosen group's AI answers too.
   - **Comment replies** (`CommentReplies::handle`) use the group of the commenter's latest ad. The commenter's `from_id` is matched as a customer of the page; an unknown commenter gets the page values.
   - **The inbox customer panel** (`Inbox::panel`) shows the customer's group values and flow, so it matches what the bot sends.
4. **Screen** (`public/js/chatbot-adgroups.js`).
   - The editable keys come from the server, so every value cell of a group row now has ✎, both in the pages list and in the Edit window's Ad groups section. Only SHOP DETAILS stays "Built from the group's item".
   - The group row's Active/Bot column has a **✎ AI: …** button, like the page's "AI:" button. It opens the group's four AI values, each with ✎. Provider and fallback are selects (OpenAI / Local / None, with "(use the default)" first).
   - The Edit window's list shows every key, AI included.
   - Each empty value shows the page's value as "uses default"; for the AI it shows the page's AI, or "site default".
   - There is no layout redesign.

## Readers changed or confirmed

**Changed:** `ChatbotAiChain::forPage` / `complete` / new `forRow`, `ChatbotAi::answer`, `ChatbotAiQueue::submit` and `late`, `ChatbotAiPool::send`, `BotcakeSendQueue` (ticket context), `ChatbotRunner::resume`, `ChatbotTabsController` (Test send and skip), `CommentReplies::handle`, `Inbox::panel`, `ChatbotAdGroupController::index` (the keys), `AdGroups` (keys and checks).

**Confirmed, unchanged (they already read the group through `botfields` / `flow`):** `ChatbotRunner` handle / skip, `ChatbotSequences` (the pin and `runStep`), `ChatbotSheetController::preview`, `ChatbotPage::flow` and `runs`. Orders, Encode order and Upload read the run's botfields.

**Deliberately page-only (page setup, not a customer):**
- the Forbeast values API `PageValues` (page defaults, as in 172);
- `/chatbot/data` page cells, the page AI dialog (`ChatbotAiSettings::row`);
- the setup checks `ChatbotPageCheck`, `PageContentCheck`, `CommentCheck` (TODO 192);
- chatblast and `ChatblastSetupCheck`; `BotcakeMock`;
- the identity and switch readers (`active`, `provider`, tokens).

## Case table

| Case | Test | Result |
|---|---|---|
| S-192.1 | `AdGroupAllValuesTest::test_S_192_1_a_group_sets_the_forms_the_prompt_and_the_ai…` (group forms, Prompt 1 and Local AI vs. page values for an ad in no group and for no ad) | pass |
| S-192.1 | `…test_S_192_1_the_ai_answered_by_the_pool_uses_the_groups_ai_too`, `…test_S_192_1_the_run_resumed_after_the_pool_keeps_the_group_it_asked_with`, `…test_S_192_1_the_inbox_panel_shows_the_customers_group_values`, `CommentRepliesTest::test_S_192_1_a_commenter_whose_latest_ad_is_in_a_group…` | pass |
| S-192.2 | `…test_S_192_2_an_empty_group_value_falls_back_to_the_page_value` | pass |
| S-192.3 | `…test_S_192_3_every_value_column_of_the_page_row_is_a_group_value_and_saves` (every key saved through the HTTP endpoint; wrong values refused), `…test_S_192_3_a_group_cannot_pick_local_when_no_local_model_is_set_anywhere`; screen checked in headless Chrome (below) | pass |
| S-192.4 | `…test_S_192_4_identity_and_switch_keys_are_refused_for_a_group` (13 data sets: On/Off, provider, Botcake ID, token, mode, auto-switch, mock AI, FLOW text, SHOP DETAILS, site-only AI, name, ads, a dotted key) | pass |
| S-192.5 | `…test_S_192_5_a_page_based_page_ignores_its_groups_values` (forms, prompt and AI) | pass |
| S-192.6 | full run below | pass |

**Red runs:** `AdGroupAllValuesTest` first failed with 14 failures ("…is set on the page only, not in a group." instead of the new message; keys missing). The runtime tests then failed without the AI/comment/inbox changes, with "Call to undefined method …ChatbotAiChain::forRow()" and array mismatches (OpenAI instead of Local, page form in the inbox and comment). After the review, the resume and Local-model tests failed first ("Opo|Toner form", null error) before the fix.

**Runs.** Both used `php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings`.

| Run | Tests | Assertions | Failures | Skipped | Deprecations |
|---|---|---|---|---|---|
| Baseline at eb34bc82 | 1612 | 51299 | 0 | 1 | 67 |
| Final (after the review fix) | 1634 | 51569 | 0 | 1 | 67 |

The final run has no new failures.

**Screen check.** The browser-checker was not run. Instead, headless Chrome loaded the real `chatbot-adgroups.js` in a static page with a mocked `fetch`, using the server's key shape (columns plus AI with options). It found:
- every group cell has ✎ (7 of 7 value columns; SHOP DETAILS shows "Built from the group's item"; no "page value" cells left);
- the row shows "✎ AI: Local", and its dialog lists the four AI values;
- the provider select offers "(use the default)", OpenAI and Local, and saving posts `{key: ai_provider, value: openai}`;
- the Fill-Up Form ✎ shows "Default: Page form" and saves `fill_up_form`;
- the Edit window lists 11 editable values, AI included.

Not checked against the logged-in live page or at 390 px. The only layout change is one button in the existing Active/Bot column, sized like the page's AI button.

## Story changes

- **S-192** was added to `qa/stories.md` from the brief.
- **S-192.1** gained variants for the AI pool, the resumed run, the inbox panel and comment replies, because the brief asks for every reader.
- **S-192.3** gained "a group cannot pick Local without a model", from the review.
- **S-172.2** and **S-174.3**: their tests asserted that `fill_up_form` is refused for a group. That is now allowed by this spec, so they now assert that `active` is refused, with the new message.

## Review

The skeptic reviewer ran at standard depth on eb34bc82..HEAD and found no blocker or major. It confirmed:
- every customer-context reader gets the group;
- only `AdGroups` writes group rows;
- excluded keys are refused on every write path;
- Page-based pages ignore groups;
- there is no XSS (all text goes through `textContent`).

Minors fixed with tests (commit 1051f853):
1. A resumed run re-read the latest ad instead of the group it asked the AI with.
2. A group could pick Local with no model anywhere.
3. The screen took any "ai_…" custom column for an AI setting. It now uses the key kind.

Accepted in `TODO.md` (section 192): comment `from_id` as PSID (to check on the server), two cosmetic AI labels, a seconds-long window after switching to Page-based, and the page checks not looking at group values.

## How marketing uses it

1. On **Chatbot → Pages**, make the page **Ad-based** and open **Ad groups**.
2. In a group's row, press ✎ on any value that differs for that ad: Fill-Up Form, Loop 3 Form, Prompt 1/2/3, after-order texts, comment replies, item, price and the rest. Use **FLOW** for its own flow and **✎ AI** for its own AI. **Manage in Edit** shows the same list.
3. Leave a value empty to use the page's value, shown in grey as "uses default".

## Deploy notes

- **No migration, no new table, no route, no config, no env var.** The pool tickets carry the group inside the existing `context` JSON.
- No Vite build is needed: `public/js/chatbot-adgroups.js` is versioned by its hash. Run `php artisan view:clear` only if views are cached (no view changed).
- **Restart the queue workers and the AI pool** (`php artisan queue:restart`, and restart the `chatbot:ai-pool` / `botcake:send-pool` processes under supervisor). The runner, the AI chain, the comment job and the send queue code changed. Tickets already in flight have no `ad_group` and use the page's AI and the latest ad, as before.
- **Behaviour at deploy:** existing groups keep their values. Nothing changes until someone sets a new value on a group, and Page-based pages are untouched.
- **Incepxion:** the same commits apply as they are. It is the same codebase with no site-specific code here.

## Notes and conflicts

- The kit asks for the reviewer's "go" on a plan before code. The brief said to build in stages and commit each, so I followed the brief; there is no plan file.
- Whether the comment `from_id` equals the Messenger PSID could not be checked locally (TODO 192). If it does not, comments get the page values, as before.
- The after-order fields have no runtime sender in this codebase yet. Wherever they are used as variables, they come through `botfields` and so follow the group.

192: done — an ad group now sets every page value (forms, loop forms, prompts, comment replies, after-order, item, flows, Seq, the page's AI) except identity and switches, and the group value reaches the run, the AI pool, sequences, orders, comments and the inbox; no migration.
