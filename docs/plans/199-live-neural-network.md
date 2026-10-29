# Plan 199: the Live page as a neural network room

Spec: `docs/specs/199-live-neural-network.md`. Design contract: `docs/design/199-live/`. Risk tier: medium for every task
(the spec's tier; nothing here is a new way in: the same two routes, the same permission, two more query parameters
behind the existing validation pattern).

## Data design (decided here, the spec leaves it open)

### Order amount (COD join)
The order event (`chatbot_events`, type `order`) is written by the flow run that wrote or appended the order row; the
same run writes the encode row (`order_encodes`: page name, psid, chat_id, flow_id, `run_id`, `macro_output_id`) through
Upload to macro or the sheet step's live encode, and `detail.run` of the event is that same run id. The Encode order step
of a later run (within the customer's open 24 h row) makes a new `order_encodes` row for the same `macro_output_id`.

Join, per order event (page_id, psid, created_at, run):
1. candidates: live `order_encodes` rows of the same page (name from the page map, or `input.page_id`) and psid, created
   from 10 minutes before the event to 24 h after it, not "not accepted" (that row is another order's);
2. the order's encode: the candidate with the same run id, else the earliest candidate;
3. the encoded order: that row when `status = done` and it has a macro row, else a later `done` candidate on the same
   macro row (Encode order in a later run);
4. amount = the `COD` of that `macro_output` row as a positive number (digits, commas, decimals), else null.
One query per resolve (order_encodes left join macro_output, `page IN (...) AND created_at >= ?` on the existing
`order_encodes_page_created` index, `psid IN (...)`), never one per event.

### Order value
A cached running state per page filter and Manila day: the day's order events by id (`id > last seen`, a primary-key
range plus `created_at >= today`), with their resolved amount. Each counts refresh reads only the new order events and
re-resolves only the ones still null, in one query. A known amount is never re-resolved, so an order counts once.
`counts.order_value = {today, recent}` sums the known amounts of today's / the last 5 minutes' events. The feed's order
events get `amount` resolved at read time (one query when the poll has order events).

### Top 3 sequences
Per-sequence follow-up counts join the existing settled / live tally (one grouped query per tally range on
`chatbot_replies` by `sent_at`, joined to `chatbot_sequences` for the name), so a poll never rescans the day.
`counts.sequences = [{id, name, n}]`, top 3 by count, then name.

### Bot filter
`LiveFeed::bot($q)`: `forbeast | incepxionbot | botcake`, anything else = all (null). Narrows `counts.bots.in/out` to the
chosen bot (the others 0) after the shared cache, and the events in SQL by the source's bot expression; AI events have no
bot and are left out under a specific bot. Totals stay page-level.

## Tasks

| # | Task | Side | Tier | Cases | Parallel-safe |
|---|---|---|---|---|---|
| 1 | Order amount on order events + `counts.order_value` (state, resolver, tests) | backend | medium | S-199.1, .2, .3, .8 | no (LiveFeed.php) |
| 2 | Top-3 sequences in the tally + `bot` filter (LiveFeed, controller) | backend | medium | S-199.4, .5 | no (LiveFeed.php) |
| 3 | The page: Blade canvas, network partial, styles, self-hosted fonts or system fallback | frontend | medium | S-199.6, .9 | no |
| 4 | The page JS: pure rules (money queue, merge, roll, never down, reduced motion) as a node-testable block + driver | frontend | medium | S-199.7 | no |
| 5 | Evidence page, screenshots, polish pass, full suite, result | both | low | S-199.10 | no |

Tests: `tests/Feature/Chatblast/ChatbotLiveNetworkTest.php` (new, data and page cases) and a node runner
`tests/js/live.cjs` driven from PHPUnit like `tests/js/page.cjs` (the repo's existing JS-test pattern).
