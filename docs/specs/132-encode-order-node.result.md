# Result: spec 132, an "Encode order" step in the bot flow

Status: done, not pushed. Branch `feat/132-encode-order-node` (cut from `0f0272c4`). The step exists in the builder and the engine and is used by no flow. No migration. Nothing was run against real Google, OpenAI or a real queue worker.

## What shipped

| Part | What |
|---|---|
| Node type | `encode_order` in `FlowGraph` (TYPES, ports `next` / `failed`, `cleanConfig`, `counts_order` allowed, `publishProblems`) and `FlowEngine::execute` → `encodeOrder()` |
| Live path | `FlowHook::hasNode` / `FlowHook::node` (new): creates the `order_encodes` row (mode `live`) and dispatches `EncodeOrder`; `Runner` keeps the step's `input` keys and skips the header read for node orders; `Runner::fail` writes the fallback sheet only when the order is still `queued`, the sheet id is a real one and the step did not already write the row |
| Builder | `resources/views/chatbot/editor.blade.php`: palette button, label, ports, node summary, defaults, prefill, config panel, "Counts as an order"; `ChatbotFlowController::edit` passes the models; `publish` calls `FlowGraph::publishProblems` |
| Event text | `ChatbotEvents`: line for `encode_order` |
| `/order-encode` | `OrderEncodeController` + view: Mode filter (shadow default / live), live rows show "live" and a link to their `macro_output` day page |
| Page settings | the cell of `order_encode.mode` says "off or shadow. Use the Encode order step in the flow instead of live." (`page_settings/index.blade.php`); `live` is still accepted |
| Docs | `docs/order-encode.md` (new section), `qa/stories.md` S-132, `TODO.md` section 132 |

Files: `app/Services/Chatbot/{FlowGraph,FlowEngine,ChatbotEvents}.php`, `app/Services/OrderEncode/{FlowHook,Runner}.php`, `app/Http/Controllers/{ChatbotFlowController,OrderEncodeController}.php`, `resources/views/{chatbot/editor,order-encode/index,page_settings/index}.blade.php`, `tests/Feature/OrderEncode/{EncodeOrderNodeTest,OrderEncodeTestCase}.php`.

## The node's config keys

`date, page, name, phone, input, shop, extracted, price` (templates, cells A to H of the row; I is always empty), `also_sheet` (bool, default off), `sheet` (link or ID), `tab` (default "All Orders"), `on_failure` (`sheet` default, or `continue`), `counts_order` (only stored when ticked, like Send / Set / Sheet). Unknown keys are dropped. `sheet` is required (checked at **Publish**, not at draft save, so an autosave of a half-filled step still works) when `on_failure = sheet` or `also_sheet` is on.

## How it runs

1. Builds the 9-cell row with `ChatbotVars::sheetCell` (same apostrophe rule as the Sheet step).
2. Test tab: nothing queued; `sheetRows` gets the row with status `test` and "Test: would encode the order"; if "Also write" is on it calls the Test outbox exactly like the Sheet step (which only writes when "Write sheet rows for real" is on).
3. Replies switched off: nothing queued or written; a `sheet` reply `skipped` "Replies are switched off".
4. Same run (psid + run id + flow + node) already queued: nothing happens, event `already_queued`, goes on `next`.
5. "Also write" on: appends once through the Outbox (same call and reply log as the Sheet step).
6. Queues: `order_encodes` row (`spreadsheet_id` = the fallback sheet when `on_failure = sheet`, else `flow-node`; `tab`; `row_number` null; `run_id`; `input` = `row`, `node`, `sheet_written`) + `EncodeOrder` on queue `encode`. Continues on **next**.
7. Cannot be queued (no OpenAI key, no address file, no table, queue refuses the job): the row is written to the fallback sheet (unless "Also write" already did) and the flow continues on `next` with a note; if that write fails the step leaves on **failed**; with "Continue without saving" it continues on `next` with the note "Warning: the order was NOT saved" (event `not_saved`).
8. `compare_sheet` and the page `mode` are not needed.

A failed encode (`Runner::fail`): the order is `failed`; the row goes to the fallback sheet once (not if already written by "Also write", not for "Continue without saving", not on a re-run of the failed order, not if `macro_output` already has the order).

## Reply and event kinds, and how Results / Inbox count it

- Reply row: kind **`sheet`**, status `skipped`, content note "encoded in Likha", node id of the step: exactly what spec 130's live Sheet step records, so the Inbox line ("Order row skipped"), the replies view and the failed-reply list already tolerate it. With "Also write" there is a second `sheet` reply (`sent`) from the Outbox.
- Event: **`encode_order`**, `result` = `queued`, `already_queued`, `sheet_instead`, `not_saved`, `failed`, `test`, `skipped` (+ `error`). New text line in `ChatbotEvents`.
- Order count: unchanged. Results and the Inbox read only the **`order`** event, which the engine emits for any node with `counts_order` that did not leave on `failed` (once per customer per 24 hours). The test compares that row with a Sheet step's (same page, psid, flow, node, type and detail keys).

## Builder panel (not seen in a browser)

Palette button "Encode order" (title = the help text). The panel shows: the help line "Encodes and checks the order in Likha and saves it to the orders (macro output). Replaces the Google Sheet step."; eight textareas (Date A, Page B, Name: FB name C, Phone D, All user input E, Shop details F, Extracted details G, Price H) that take the same variable chips as the Sheet step; a read-only box "Models in use: extract …, checker …" with the `app_settings` key names and a link to `/order-encode`; the checkbox "Also write to Google Sheet (while changing over)"; the select "If the encode fails" (Write to Google Sheet instead / Continue without saving); the sheet link and tab fields (shown when either needs them) with a red hint when the link is missing; "Counts as an order". A new step is prefilled from the flow's own Sheet step on the tab "All Orders" (columns A to H, sheet, tab, counts-as-order) when there is one, else empty. All text is set with `textContent`; the page uses Blade escaping. The JS I added was syntax-checked with `node --check`; no browser pass was run (no browser tools in this session).

## Cases

| Case | Test (`tests/Feature/OrderEncode/EncodeOrderNodeTest.php`) | Result |
|---|---|---|
| S-132.1 palette, save and reload | `test_S_132_1_the_builder_lists_the_step_and_saves_and_reloads_its_config` | pass |
| S-132.2 publish needs the fallback sheet (5 cases) | `test_S_132_2_publishing_needs_the_fallback_sheet_when_the_step_would_use_it` | pass |
| S-132.3 one live encode, A–I row, next | `test_S_132_3_…` | pass |
| S-132.4 Test tab | `test_S_132_4_…` | pass |
| S-132.5 also write once | `test_S_132_5_…` | pass |
| S-132.6 failed encode, fallback once | `test_S_132_6_…` | pass |
| S-132.7 continue without saving | `test_S_132_7_…` | pass |
| S-132.8 retry, one `macro_output` row | `test_S_132_8_…` | pass |
| S-132.9 rerun of the same run | `test_S_132_9_…` | pass |
| S-132.10 cannot be queued (3 cases) | `test_S_132_10_…` | pass |
| S-132.11 replies off | `test_S_132_11_…` | pass |
| S-132.12 `order` event same as a Sheet step | `test_S_132_12_…` | pass |
| S-132.13 old graph unchanged | `test_S_132_13_…` | pass |
| S-132.14 `/order-encode` live filter and link | `test_S_132_14_…` | pass |
| S-132.15 same run id, other customer | `test_S_132_15_…` | pass |
| S-132.16 no key | `test_S_132_16_…` | pass |
| S-132.17 dispatch refused | `test_S_132_17_…` | pass |
| S-132.18 Page settings help text | `test_S_132_18_…` | pass |

Final run: `php vendor\phpunit\phpunit\phpunit tests/Feature/OrderEncode tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake tests/Feature/NightRun`: **1468 tests, 12880 assertions, 1 error**: `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`, "file …storage\app/credentials.json does not exist" (the known local-only failure). A second error seen in the first full run, `ChatbotTagsTest::test_S_100_3…` (it passes alone and in its folder), came from the static "tags ready" cache in `ChatbotTags` surviving into a later test whose app object had the same id; `OrderEncodeTestCase::tearDown` now resets it (test-only change).

Red runs (first failure line): the new tests were written before the engine code. Before the engine arm, the engine tests stopped at the step (unknown node type, no port): S-132.3 "Failed asserting that two arrays are identical" (path ended at n2); S-132.6 `ModelNotFoundException` (no `order_encodes` row); S-132.14 "Failed asserting that … contains \"#2 · row 77\"" (no Mode filter). Fix loop: S-132.15 "Failed asserting that two arrays are identical" (second customer's order dropped), S-132.17 "Failed asserting that 'queued' is identical to 'failed'". The `FlowGraph` changes (S-132.2 and .13) went in just before the first run, so they have no separate red run; S-132.16 and .18 passed on first run (behaviour already present), they pin it.

## Review

`skeptic-reviewer`, adversarial depth, on `17e241fe..12f28d74`. Findings and what happened:

- **Major, fixed (test first, S-132.15):** the "already queued" check matched on run id + flow + node only; run ids are 8 hex digits, so over time a new customer's order could be treated as already queued and dropped. It now also matches the customer.
- **Major (legacy rule), fixed:** a one-character change in the `cleanSkip` docblock was undone.
- **Minors fixed:** a refused dispatch left a `queued` row that never runs (now marked `failed`; S-132.17); the compare link took its day from "now" when the date was unreadable (now from the `macro_output` row's TIMESTAMP, else the creation day); the prefill did not carry "Counts as an order" over (now does); S-132.11 now asserts nothing is written or stored; added S-132.16, .18.
- **Minors accepted in `TODO.md`:** `not_saved` still counts as an order when ticked; no index on `run_id`/`flow_id` (no migration in this spec; the check is narrowed by `psid`); the models have no screen to change them; the link goes to the day page of `macro_output`, not the row.
- Not done: the scoped re-check by the reviewer after the fix loop (the fixes are small and covered by S-132.15, .16, .17); the optional browser check.

## Story changes

The spec brought no stories; S-132.1 to .18 were drafted from its "Done when" list (no reviewer reachable for the plan "go", as in spec 130; the decisions table was taken as the agreed design). No existing story changed.

## Merge danger

- The step is inert until a flow contains it; the new engine arm and the `FlowGraph` TYPES entry change no stored graph (S-132.13).
- `Runner` changes touch spec 130 orders: `input` is now merged instead of rebuilt (adds `fields`, keeps all keys), and the fallback sheet write needs `status === 'queued'` plus a real sheet id; the existing S-130.55/.59 tests pass unchanged.
- `/order-encode` default view is still shadow only; a `mode` other than `live` falls back to shadow.
- Before any flow uses the step: migrate (spec 130 table), the Supervisor `encode` worker, `ORDER_ENCODE_OPENAI_KEY` or `OPENAI_API_KEY`, and the address file; without the worker an order stays `queued` (TODO).
- Decisions I took where the brief was open: the failed port is also used when the fallback sheet write fails; "Continue without saving" with an unqueueable order continues with a warning; validation of the fallback sheet is at Publish; the models line points to the `app_settings` keys (no screen exists) plus a link to `/order-encode`.

## Commits

```
17e241fe docs: spec 132 encode order node
12f28d74 feat: Encode order step in the bot flow, queues the live encode and continues, with builder panel and live filter on /order-encode (spec 132)
a95306b0 fix: encode order already-queued check includes the customer, a refused dispatch marks the row failed, compare link uses the order's own date, prefill keeps counts as order (spec 132)
```
(plus the commit that adds this result.)
