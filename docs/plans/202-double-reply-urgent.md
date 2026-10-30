# Plan 202: a double reply is urgent only when both main flows answer a NEW customer

Risk tier: low-medium (business logic on a trusted path; no new input, route or table). Side: backend first, then the two
screens that show the numbers.

## Where the change lives

`App\Services\Chatbot\ChatEchoes::build()` (the cached Bot Health echo build, 60 s) already loads the window's echoes and
the window's customer chats and finds the double pairs. The classification is added there, so the panel and Bot Health
read the same numbers from one cached array.

### "New customer" in the code

1. The chats query of the build is widened from `[since, now - unanswered_minutes]` to `[since, now]`; the unanswered
   count keeps its own cut-off in PHP (no extra query). Its customers are the candidates.
2. One grouped query: `MIN(received_at)` per `page_id, psid` over `botcake_chats` for the candidates (all rows, not only
   `active()`, so the index `page_id, psid, received_at` answers it). The ad referral and postback starts are stored as
   chats by the gateway (`HandleGatewayEvent::CHATS`), so the first chat already covers an ad or open-thread start.
3. New = the first chat is inside the window, and no Chatbot Builder or Botcake echo came before it
   (`first - EARLY_SECONDS`): first checked in the echoes the build already holds, then, only for the new customers
   CBB answered, one lookup of older CBB echoes (30 days are kept) before the window.

### "Main flow" in the code

There is no main-flow flag in `chatbot_flows`: the page's main flow is the page setting `chatbot.flow_id` (what
`ChatbotPage::flow()` runs for a customer), plus the `flow_id` of the page's active ad groups (the main flow of that
ad's customers). A Likha main-flow reply is a `chatbot_replies` row with `kind = send`, `status = sent`,
`sequence_id IS NULL` and `flow_id` in that set. One lookup, only for the new customers CBB answered.

### Main-flow double

A new customer who got a CBB echo (sender `chatbot_builder` or `botcake`) and a Likha main-flow reply, both within the
reply window of the first chat (`[first - EARLY_SECONDS, first + unanswered_minutes]`, the same window that counts a
message as answered). Every other double pair is a leftover.

### Output (per page, added beside the current keys)

`new_customers`, `cbb_new` (new customers answered by CBB), `main_doubles` (customers), `main_examples` (up to 3 inbox
links), `leftovers` (double pairs not on a main-flow customer), `leftover_examples`. `doubles` and `double_examples`
stay as they are (the total). The cache key moves to `bot-health:echoes:v2` so a deploy never reads the old shape.

## Tasks

| # | Task | Side | Risk | Cases |
|---|---|---|---|---|
| 1 | Classification in `ChatEchoes::build()` (new customers, CBB answers, main-flow doubles, leftovers, examples) and the query-count guard | backend | medium | S-202.1a, S-202.1b, S-202.2a, S-202.4a, S-202.5a, S-202.6a (data) |
| 2 | Problems panel: orange "Main flow double" line with up to 3 example links, white leftover line, the new/answered-by-CBB numbers, help text | backend + blade | low | S-202.1a, S-202.2a, S-202.4a, S-202.6a (panel) |
| 3 | Bot Health "Who answered": the same split and numbers, help text | frontend (blade) | low | S-202.3a |
| 4 | Stories in `qa/stories.md`, result, full tests/Feature run vs the base | docs | low | all |

Tasks 2 and 3 share no files, but both are small and run after task 1, in this session.

## Cases (tests in `tests/Feature/Chatblast/DoubleReplyUrgentTest.php`)

- S-202.1a a new customer answered by CBB and the Main Flow: orange "Main flow double: 1 new customer …", example inbox link.
- S-202.1b a Likha sequence send (and a flow that is not the page's main flow) never counts as the main flow.
- S-202.2a an old customer with a CBB loop reply plus a Likha reply: a white leftover line, no orange.
- S-202.3a Bot Health shows the same split with the same numbers.
- S-202.4a a Karla-like page (many new customers, CBB answers only old ones): no orange, whatever the leftover count.
- S-202.5a the query count of the build does not grow with the number of pairs or customers.
- S-202.6a the line shows "new customers: X, answered by CBB: Y".
- Existing `ChatEchoTest` and `ProblemsPanelTest` kept; changed assertions listed in the result.
