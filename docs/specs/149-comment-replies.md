# Brief 149: comment auto-reply and comment-to-inbox for Forbeast pages, set per page in /chatbot/pages

## Why
The owner wants what Botcake's comment feature does (public reply to a comment + one private message to the commenter), decided by Likha/Incepxion for pages connected through Forbeast, with the values set per page as new columns on /chatbot/pages next to the Seq and SHOP DETAILS columns. Forbeast (separate repo, handoff 014, built in parallel) will push a new gateway event `type:"comment"` to `/api/gateway/events` and add two send endpoints. A live test on 2026-10-25 (Tech Flask PH) proved Meta accepts both the public reply and the private reply for a commenter without an app role; the commenter's name is NOT available (Standard access), so replies carry no name.

## Contract with Forbeast (build against fakes)
- Inbound event (same signature check and HandleGatewayEvent path as other gateway events): `{page_id, type:"comment", comment_id, post_id, parent_id, message, created_time, from_id|null, from_name|null, is_reply, is_page_author}`; dedupe on comment_id.
- Outbound, through the page's Forbeast token like `GatewayProvider` sends: `POST {GATEWAY_URL}/v1/pages/{page_id}/comments/{comment_id}/reply` body `{message}` → `{ok, comment_id}`; `POST {GATEWAY_URL}/v1/pages/{page_id}/comments/{comment_id}/private-reply` body `{text}` → `{ok, message_id, recipient_id}`; errors `{ok:false, error, meta_code, meta_subcode}`, `error:"already_replied"` for Meta #10900.

## Decisions (do not reopen)
| # | Decision | Owner's reason |
|---|---|---|
| 1 | **New botfield columns** on /chatbot/pages (same mechanism and Edit modal as the existing botfields such as Seq and SHOP DETAILS; include them in the AI paste mapping field list with one-line descriptions): **Comment Reply 1**, **Comment Reply 2**, **Comment Reply 3** (public reply variants) and **Comment PM** (the private message text). Empty = that part is off for the page. | "new columns to, kasama nung seq, shop details etc". |
| 2 | **Public reply**: one of the filled Comment Reply values picked at random per comment (spam guard like Botcake's variants). Plain text; the page's botfield variables (e.g. {{PRICE}}, {{ITEM NAME}}) may be filled the way the flow fills templates; no name variable (not available). | Botcake picks a random variant to avoid Facebook's spam check. |
| 3 | **Private message: text only, exactly one message** (Meta allows one private reply per commenter; more only after the customer replies). Text = the page's **Comment PM** value (variables filled as in 2). The ad's welcome message is NOT read in this spec (needs the Marketing API; a later spike). When the customer answers the private message, the normal bot flow runs (no change). | "text lang, same sana dun sa welcome message ng ads, if meron, if wala, yung isset na value dito". |
| 4 | **Which comments**: only for pages whose bot is On and whose provider is Forbeast (Botcake pages keep Botcake's own comment feature, no double private reply); only top-level comments (`is_reply` false); skip `is_page_author` and comments we created; once per comment (dedupe); **at most 20 replies per minute per page** (queue the rest, Botcake's limit); if Forbeast answers `already_replied`, record it and do not retry. Order: private message first, then the public reply. | Same as Botcake; one private reply per comment. |
| 5 | **Logs**: every comment handled (or skipped, with the reason) and every reply/private message with result is recorded (reuse `chatbot_replies` with kinds `comment_reply` / `comment_pm`, or a small `chatbot_comment_events` table if cleaner — say which) and visible on the existing Replies page with a filter, and counted on the Health page (comments in, replied, failed). No comment text in laravel.log. | Complete logs. |
| 6 | Page checker (the Check column): for a Forbeast page with Comment PM or Comment Reply filled, warn if the page's Forbeast token lacks the comment permissions (the Forbeast connect test reports scopes if available; otherwise skip this check and note it). | Setup visible per page. |

## Threat model
Untrusted: the comment event body (signed by Forbeast, but text from the public), Forbeast/Meta answers. Escape everything shown. Tokens never logged or returned.

## Cases (add as S-149 to qa/stories.md, table like S-148)
P1: .1 the four columns appear, editable, saved with history, in the Edit modal and the paste mapping; .2 a comment event on a Forbeast page with Comment PM + 3 replies → one private-reply call with the PM text (variables filled), then one public reply with one of the 3 variants (random seam testable); .3 Comment PM empty → no private message, public reply still sent; all replies empty → nothing sent, skipped "not set"; .4 Botcake-provider page or bot Off → skipped, nothing sent; .5 replies-to-replies, page-authored comments and our own replies are skipped; .6 the same comment twice → handled once; .7 already_replied → recorded, no retry; .8 more than 20 comments in a minute on one page → the 21st waits for the next minute; .9 logs on Replies (filter) and counts on Health; no comment text in laravel.log; .10 unknown page / bad signature → refused as for other gateway events. P2: .11 Check warns when comment columns are set on a page whose token lacks the permissions (or the check is noted as skipped).

## Done when
- P1 cases have passing tests; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode` adds no new failures vs 5ff034d1.
- Result: files, columns, the event handling path, the random seam, logs location, evidence per case, story changes, commits. End with "149: <done|blocked> — <one line>".

## Out of scope
Reading the ad's welcome message; per-post rules; keyword rules; comments with phone numbers / mentions sets; liking or hiding comments; Instagram; deploying.
