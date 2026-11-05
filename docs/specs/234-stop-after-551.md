# Spec 234: stop messaging a customer after Meta says they can't be reached

**Project:** Likha (the same code runs incepxion.pro). **Shape:** change · **Weight:** bounded · **Risk tier:** high (it decides which customers get messages, on live pages)

## Why
On incepxion.pro on 2026-11-05, 224,678 sends went out and 11% failed, mostly with Meta error #551 ("This person isn't available right now": the person blocked the page, deleted the conversation, deactivated their account or can't be reached). Error #10 is the other big group (sending is not allowed, for example outside the messaging window). Scheduled sends keep trying those same people every day: sequence steps, Chatblast and follow-ups. This wastes sends, and a page that keeps messaging people who can't be reached looks like spam to Meta: several pages already get "temporarily restricted" errors every hour.

Today the code only labels these errors:
- BotHealth groups #551 as "unreachable";
- ForbeastWatch::CUSTOMER_CODES = [551, 10] keeps them from counting as gateway faults;
- ChatblastNeedsSetup shows them as information only.

Nothing stops the next send. All sends are recorded through BotcakeOutbox::record (chatbot_replies), for both Botcake and the Forbeast gateway routes.

## Decisions already made (settled with the owner; don't reopen them unless something is actually broken)
| # | Decision |
|---|---|
| 1 | When a send to a customer (page + psid) fails with Meta #551, or with #10, the customer is marked unreachable on that page, with the time and the code. Read the code from the error the same way BotHealth and ResponseSpeed do ("(#551)", "#10", "isn't available"). |
| 2 | While a customer is marked unreachable, every send that the customer did not just ask for is skipped and recorded in chatbot_replies as status `skipped` with the reason ("Customer can't be reached (#551) since <time>"). This covers sequence steps, Chatblast, scheduled follow-ups, broadcasts and any send that is not an answer to a new customer message. |
| 3 | The mark is cleared the moment the customer sends any new message to that page (any inbound chat after the mark, on either route). From then on, flows answer them normally. The answer to that new message is never skipped. |
| 4 | Store the mark in an existing row-based store if one fits, such as the customer fields per page and psid that flows already use (CustomerFields / ChatbotVars), rather than a new table. Check how those are stored and read before deciding, and say which you chose in a Ruling. A new table only if no existing store can hold it safely, explained in the result. |
| 5 | Both routes (Botcake and gateway) and every sender go through one check, at the single send path (BotcakeOutbox::send or just before it, and the Chatblast and sequence senders if they don't pass through it), so no path is missed. |
| 6 | Health and Replies show how many sends were skipped this way, per page, today. The banner gets no new line. |
| 7 | Botcake's own CBB flows are outside Likha and are not affected. |

## Stories
Add these to qa/stories.md under `## Slice 234 – Stop after #551`, with one failing test per case first, named with its case ID.

S-234.1 A customer who can't be reached is marked (P1)
- .1 happy: a send fails with (#551) → the customer is marked with the time and 551.
- .2 happy: the same for #10.
- .3 negative: other errors (#100, #2022, a timeout, a restriction) → no mark.
- .4 edge: Botcake's wording ("isn't available") and the gateway's ("sendMessage failed (code 551…)") are both recognised.

S-234.2 Scheduled sends skip a marked customer (P1)
- .1 happy: a sequence step to a marked customer → skipped, recorded with the reason, and no call to Botcake or the gateway.
- .2 happy: the same for a Chatblast send and a follow-up.
- .3 edge: a marked customer on page A is still messaged on page B.
- .4 edge: many sends in the async pool for one marked customer → all skipped, with no race that lets one through after the mark is stored.

S-234.3 A new message from the customer clears the mark (P1)
- .1 happy: a marked customer writes to the page → the mark is cleared and the bot answers.
- .2 happy: a later sequence step after that → sent normally.
- .3 edge: an inbound postback or quick-reply tap also clears it.

S-234.4 It shows (P2)
- .1 happy: Health or Replies shows the count of skipped sends per page today.
- .2 edge: the count matches chatbot_replies rows with status skipped and that reason.

## Constraints
- High tier. Untrusted: Meta error texts (parse defensively). Never log message text or tokens.
- The check must be one cheap lookup per send. Measure it in the result.
- Run the full suite last. Known pre-existing failures: ExampleTest and ItemTableFitScriptTest; BigBroadcastTest and AstraAddressRulesTest S-26.18 are time-limited and flaky.

## Budget
At most 2 tries at the same step. Size: medium.

## Done when
- [ ] Cases S-234.1 to S-234.4 pass, each with a test named after it.
- [ ] The full suite adds no new failures.
- [ ] qa/stories.md has slice 234.
- [ ] The result has Merge danger, and how to turn it off quickly if needed (a config switch, default on).

## Out of scope
Deleting customers or PSIDs, changing Botcake's CBB flows, the banner, and deploying.
