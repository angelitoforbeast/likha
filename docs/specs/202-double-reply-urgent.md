# Spec 202: a double reply is urgent only when both main flows answer a NEW customer

## Why
The chatbot "pages with problems" panel (/chatbot/pages, App\Services\Chatbot\ProblemsPanel; doubles from App\Services\Chatbot\ChatEchoes::build) marks a page orange for any two echoes from different apps to one customer within double_seconds (60). On 2026-10-30 that made 18 pages "urgent", e.g. Katie Santos with 37 — but the owner says Katie's Chatbot Builder (CBB) main flow is OFF; those are leftovers: old customers still inside CBB sequences and loops (Loop 1 / Loop 3), who get a CBB reply right after each message. His rule: a double is urgent only when both MAIN FLOWS answer — CBB's main flow (still on for the page) and the Likha bot's main flow.
A read-only test on 3 h of production data showed timing alone cannot separate them. The signal that does: CBB replying to a NEW customer. Karla Chavez: 55 new customers, 0 CBB replies, so all her doubles are leftovers; Katie: 19 of 183 new customers got a CBB reply; Kylene 7/94; Jenny 3/73. If CBB's main flow is off, new customers should never get a CBB reply.

## Definitions
- NEW customer (for a page): the customer's first-ever inbound on that page (earliest botcake_chats received_at for page+psid; also an ad referral / open-thread start if Likha stores one — check) falls inside the panel's window, and there was no echo from chatbot_builder or botcake to that psid before that first inbound.
- MAIN-FLOW DOUBLE: a NEW customer who, after their first inbound, got a reply from CBB (sender chatbot_builder or botcake) AND a Likha bot reply from the Main Flow (chatbot_replies kind send, sequence_id NULL, flow = the page's main flow; confirm how the main flow is identified in chatbot_flows) within the same reply window.
- LEFTOVER DOUBLE: any other double (old customers).

## Stories (S-202)
P1
- .1 The panel shows a page orange "Main flow double: N new customers answered by both CBB and the Likha bot in the last 60 min" only when N >= 1 (red levels unchanged). Each line links to up to 3 example conversations (inbox links, no names or text).
- .2 Leftover doubles become WHITE (not urgent): "N leftover CBB replies to old customers (sequence/loop) in the last 60 min", so the owner can watch them drain.
- .3 Bot Health (wherever ChatEchoes doubles are shown today, e.g. /chatbot/health) shows the same split with the same numbers.
- .4 A page whose CBB never answers new customers is never urgent for doubles, whatever its leftover count (the Karla case).
- .5 Performance: the classification happens inside the existing cached build (at most one extra grouped, indexed query for first inbound per psid), not per pair; measure and record it.
P2
- .6 The page's line also shows "new customers: X, answered by CBB: Y" for the window.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Urgent (orange) only for main-flow doubles on NEW customers; leftover doubles are white. | The owner's rule. |
| 2 | "New" is measured from Likha's own records; the panel's help text says a customer already known to CBB but new to Likha counts as new. | Data limits, said plainly. |
| 3 | No change to unanswered (red), failed sends, slow, quiet or setup lines. | Narrow change. |

## Threat model and risk
Trusted: signed-in staff. No new inputs. Risk tier: low-medium (a wrong rule would hide a real double; cover it with tests).

## Cases to test
Seeded echoes, chats and replies: new customer answered by CBB and the Main Flow gives orange with the count; old customer with a CBB loop reply plus a Likha reply gives a white leftover; a sequence send on the Likha side never counts as main flow; a Karla-like page (many new customers, CBB only on old ones) gives no orange; the query count does not grow with pairs; existing ProblemsPanel/ChatEchoes tests kept (list changed assertions under Story changes).

## Done when
P1 cases pass; the full tests/Feature suite adds no new failures vs 8506b25c; the result has the rulings (how "new" and "main flow" are identified in the code), deploy notes (no migration expected; cache keys) and the final line.

## Out of scope
Turning CBB off anywhere, Botcake API calls, deploying.

## Budget
Small-medium job. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
