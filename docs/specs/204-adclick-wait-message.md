# Spec 204: the Main Flow starts on the customer's first message, not on the ad click

## Why
For pages on Forbeast, Likha starts the Main Flow the moment Meta reports a Click-to-Messenger ad open (a `referral` event from the Forbeast gateway; `App\Jobs\HandleGatewayEvent::CHATS = ['message', 'postback', 'referral']`). The opening (product image + "Hello po! 👋 Naka-special PROMO…", 4 sends) goes out ~0.7 s after the click, before the customer has written anything. Meta refuses about 29% of those sends (code 551, subcode 1545041): on 2026-10-30, 15,642 sends that followed a referral failed 29% vs 0.4% for sends that followed a customer message. When the customer's own message arrives 2–9 s later the flow has already moved on, so the customer never sees the image and promo intro, only the next answer (e.g. the price). Botcake's default reply only starts when the customer chats. The owner: the bot should send only once the customer has chatted ("yes").

Five real cases from 2026-10-30 14:57–14:59 Manila (pages Lalaine Cruz, Xavia Aquino, Bianca Santos, Bella Garcia, Kylene Dellamas): referral → our 4-send opening failed 551 → customer message ~5 s later → next reply sent fine.

## Stories (S-204)
P1
- .1 A `referral` event (ad open) for a page on the Forbeast gateway no longer starts or advances any bot run and sends nothing. It is still recorded exactly as today for everything that reads it (the ad id / ad attribution used by ad groups and reports, the chat row if the code needs one, the inbox), but marked so the bot does not act on it.
- .2 The customer's first `message` (or a `postback`) after the ad open starts the Main Flow from its first step, so the opening (image + greeting) is the first thing the customer receives, and the ad attribution from the referral is still attached to that customer/run (same ad_id as today).
- .3 A referral that arrives together with or after a message in the same conversation does not restart or double the flow (idempotent; the existing dedupe rules stay).
- .4 A referral for a customer already inside a running flow changes nothing in that run (records the ad only).
- .5 Postback and message handling are otherwise unchanged; Botcake-webhook pages are unaffected (say how their path differs).
- .6 Counts that read chat rows (Live page inquiries/active chats, Speed page, problems panel, ad groups) do not double-count or lose the customer: list every reader of referral-created rows and what each shows before vs after.
P2
- .7 A small daily figure in an existing health view: "ad opens without a message" (customers who clicked an ad and never wrote), so the owner can see how many leave without chatting.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Bot sends only after the customer chats (message or postback); the ad click is recorded only. | The owner; matches Botcake's default reply. |
| 2 | Customers who click the ad but never write get no message (same as Botcake). | Accepted trade-off. |
| 3 | No change on the Forbeast side (it keeps forwarding referrals); the change is in Likha's handling. | Likha decides when to answer. |
| 4 | Same code for Likha and Incepxion. | One codebase. |

## Threat model and risk
Trusted: signed Forbeast gateway events. Risk tier: medium-high (changes when every Forbeast page's bot starts; a mistake could stop replies). Run a skeptic reviewer subagent on the trigger path and the dedupe.

## Cases to test
Feature tests: referral alone → no run, no send, ad recorded; referral then message → Main Flow from step 1 with the referral's ad_id attached; message then referral → no double start; referral inside a running flow → no change; postback still starts as today; existing HandleGatewayEvent / flow / ad group tests kept (list every changed assertion under Story changes). Measure nothing new in production.

## Done when
P1 cases pass; full tests/Feature adds no new failures vs 8506b25c; result with the list of referral readers (.6), deploy notes (no migration expected; queue workers must be restarted after deploy so the new job code runs; how to watch the first hour: failed 551 rate should drop), and the final line.

## Out of scope
Retry policies in Forbeast, changing flow contents, Botcake settings, deploying.

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
