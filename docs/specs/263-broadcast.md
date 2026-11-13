# 263: Broadcast to a chosen audience of PSIDs (/chatblast/broadcast)

Weight: architectural (new page, new send path on existing pieces). Risk tier: high (sends messages to real customers on Facebook pages; a mistake reaches many people at once). Size: large, but slice 1 must be deployable within about 5 hours.

## Why

The owner wants to send one message to a chosen group of customers, for example everyone on pages A, B and C who subscribed before August and has no order, for a sale today (11.11). The /chatblast page already sends to customers who have a waybill: it writes the text into the page's message custom field, reads it back, then triggers the page's utility flow (`ChatblastSender`, steps write → read → `send_flow`). A broadcast is the same send, with a different audience (PSIDs from `botcake_psids`, not waybills), owner-written content, and a flow the owner picks.

## Slices (build and commit in this order)

- **Slice 1 (needed today):** audience from data Likha already stores; preview count; test send; send, pause, resume, stop; progress. Commit and report as soon as it is green, before starting slice 2.
- **Slice 2:** a filter on a Botcake custom field value (e.g. "GPT phone number has no value"), read live from Botcake.

## Stories

### S1 Build an audience (P1)
As the owner, I pick who gets the broadcast, and I see how many that is before anything is sent.
- Filters, all optional, combined with AND:
  - **Pages:** a checkbox list of the pages that have all of `Chatblast::REQUIRED` set, with "select all" and "clear".
  - **Orders:** any / with an order / without an order. "With an order" = the PSID appears in `macro_output.botcake_psid` for that page.
  - **Subscribed:** before and/or after a date (`botcake_psids.subscribed_at`).
  - **Last interaction:** before and/or after a date (`last_interactive_at`).
  - **Phone in Botcake:** any / has / has none (`phone_numbers`).
- Only rows with `status` available or verified, and one row per page and PSID.
- Cases:
  - .1 happy: 2 pages, "without an order", subscribed before 2026-08-01 → shows a count per page and a total. It matches a plain SQL count of the same filter, checked in the test.
  - .2 edge: no filter at all → the total is shown with a warning line "this is every PSID on the chosen pages".
  - .3 negative: no page selected → cannot preview or send.
  - .4 edge: a PSID that is on the page twice (different rows) is counted and sent once.
  - .5 edge: a PSID with an order on page A but not on page B, with "without an order" on both pages → it is in B's audience and not in A's.

### S2 Pick the flow and write the content (P1)
As the owner, I pick the flow and write the text that goes into the message custom field, the same as a normal chatblast message.
- Flow: per page, the default is the page's `flow.shipping`. The owner may pick another flow by **name** from the flows that exist on the chosen pages. The page's flow list is read from Botcake `GET /pages/{id}/flows`, like `ChatblastFlowInfo`. A page without a flow of that name is listed and skipped, never sent with a different flow.
- Content: a text, put into `field.message` exactly as written. The same length and character rules apply as chatblast messages.
- Cases:
  - .1 happy: flow "Shipping Status" exists on both pages → both pages use their own flow id of that name.
  - .2 negative: the chosen flow name is missing on one page → that page shows "no flow named X" and its count is excluded from the send.
  - .3 negative: empty text → cannot send.

### S3 Test send (P1)
As the owner, I send the broadcast to my own test PSID first and see it arrive.
- Reuse the chatblast test PSID (`/chatblast/test-psid`) and its write → read → flow steps.
- Cases:
  - .1 happy: a test on one page → one row with the steps and HTTP codes, shown as sent.
  - .2 negative: the page token is refused (401/403) → shown as such, nothing else is sent.

### S4 Send, pace, pause, stop, progress (P1)
As the owner, I start the broadcast, watch it, and can pause or stop it at any time.
- One send row per broadcast, page and PSID, with a dedupe key, so a double click or a retry never sends twice.
- Rows go through the existing pool and rate limits: the per-token pace shared with chatblast and the Botcake 429 backoff. A broadcast must never starve the waybill chatblast or the chatbot: the existing sends keep their share.
- **Per-page cap:** sends per page per hour, default 300, editable on the broadcast.
- **Auto-pause per page:** after 20 failures in a row on one page, or on any answer that says the page is restricted or blocked from messaging, that page pauses with the reason shown. The other pages go on.
- Statuses as in chatblast: queued, sent, failed, unknown, field_mismatch. "Unknown" is never retried by itself.
- Pause / resume / stop for the whole broadcast. Stop cancels the queued rows.
- The progress view shows per page: audience, queued, sent, failed, unknown, paused reason. It refreshes without a full reload.
- Cases:
  - .1 happy: 3 PSIDs on a mocked page → 3 sent rows, each with the write, read and flow steps.
  - .2 edge: start pressed twice → still 3 rows.
  - .3 negative: 20 failures in a row on page A → A pauses, and page B goes on.
  - .4 edge: stop during sending → the queued rows become cancelled, and a row already sent stays sent.
  - .5 edge: the per-page cap of 2 per hour with 3 PSIDs → 2 sent now, and 1 waits for the next hour.

### S5 Custom field filter (P2, slice 2)
As the owner, I filter by a Botcake custom field: has a value / has no value / equals a text.
- Read live from Botcake `GET /pages/{page_id}/custom_field/{custom_field_id}/get_customers_field`. The field list comes from `GET /pages/{id}/custom_fields`, by field name across pages, like S2's flow name.
- **First, read one page by hand (read-only) and record the real answer shape in the result:** the docs show `customer_name` and `value` but not `psid`. If the answer has no PSID, stop slice 2 and report. Do not match on names.
- The read is cached per broadcast build, not stored in a new table.
- Cases:
  - .1 happy: "has no value" on field X → the PSIDs with no row or an empty value for X are kept.
  - .2 negative: Botcake errors on the field read → that page is excluded with the reason shown, never sent unfiltered.

## Decisions already made (do not reopen)

| # | Decision | Who and why |
|---|---|---|
| 1 | Use the existing utility flow and message custom field, exactly like the chatblast send. Do not use Botcake-native broadcasts or new flows. | The owner: "Yung existing flow na muna... Ang ilalagay lang is kung ano yung value dun sa custom field." |
| 2 | The flow is selectable. | The owner: "selectable sana". |
| 3 | The audience is chosen by the owner with filters: pages, with/without order, subscribed before a date, custom field values. | The owner, 2026-11-11. |
| 4 | Text only in slice 1. No image is sent by URL: URL images fail on many pages now. | The reviewer: today's image failure data. |
| 5 | No new table unless the existing ones cannot hold it. Prefer a `chatblast_campaigns` row (new kind) for the broadcast definition and `botcake_messages` rows (dedupe key `broadcast:<id>:<page>:<psid>`) for sends. Check the precedent first and say in the plan which you chose. | The owner's standing rule (lean schema; Page \| Key \| Value). |

## Constraints

- Follow `CLAUDE.local.md` and the dev kit. Write tests first. Use PHP 8.4: `C:\Users\Forbeast\.config\herd\bin\php84\php.exe vendor/phpunit/phpunit/phpunit`. If the worktree needs it, use the bootstrap shim at `C:\Users\Forbeast\AppData\Local\Temp\claude\2e760ef270dbe960\07319e29-2afc-45d1-9b8d-9083b1ed643e\scratchpad\wt-bootstrap2.php`.
- Botcake is mocked in tests (`BotcakeMock`). Never call the real Botcake from tests.
- Never send to a real customer during the build. The only real call allowed is the read-only S5 shape check, and only on one page.
- Audience queries run on tables with millions of rows (`botcake_psids` has 2.3 M rows). Use indexes and set-based SQL. No per-row PHP loops over the whole table. No `CREATE TABLE AS SELECT` or `INSERT ... SELECT` from live tables at request time. Show EXPLAIN in the result for the count query. If an index is needed, add it as a normal migration and say how long it takes on 2.3 M rows.
- Queueing a large audience (over 1 M rows) must be done in batches, by a job, not in the web request.
- Commit messages and code carry no `Co-Authored-By`, `Claude-Session` or "Generated with" lines, and no person's name. Use Conventional Commits. Spec numbers are allowed.
- Commit this spec as `docs/specs/263-broadcast.md` in the first commit, and write `docs/specs/263-broadcast.result.md` at the end.

## Threat model

- Untrusted: the owner's content text is shown back escaped. Botcake answers are data. Filter inputs are validated server-side: dates, page names from the allowed list, flow and field names that exist.
- Trusted: the signed-in owner (existing `auth` and `allowed_ip` middleware, same as /chatblast). The route group, permission and throttles are the same as the chatblast send routes.
- Exposure: one wrong click reaches many customers. So there are always a preview count, a confirm step that shows the total and the flow name per page, a cap and auto-pause.

## Done when

- Every case above has a test that passes. The full suite adds no new failures compared to the base `3191ed41`.
- Slice 1 is committed on `feat/263-broadcast` and reported back before slice 2 starts.
- The result file lists: the files changed, the schema choice and any migration with its timing, EXPLAIN of the count query, how pacing shares the pool with chatblast, the S5 answer shape, and any story changes.

## Out of scope

Images, scheduling at a set time, Marketing Messages, A/B content, editing flows in Botcake.

## Budget

2 attempts per step. If something is unclear or out of scope, stop and report instead of guessing. Do not deploy: the reviewer deploys after checking.
