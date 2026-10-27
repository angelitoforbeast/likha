# Brief 164: the CEO sees who owns each page, takes new pages and moves pages to or from the other site

## Why
Pages come to this site from Forbeast (the gateway) through a page token that a person generates in Forbeast and pastes into the Connections popup (`ChatbotConnections::saveForbeast`, `ForbeastPageSetup::connect`). The owner wants: a table of every page connected in Forbeast and which site (Likha or Incepxion) owns it; a new page is unowned (bot off) until a CEO takes it, with no token to paste; and a CEO can move a page to the other site or ask for one from it — the other site's CEO accepts — with the page's values, saved customer details and PREORDER follow-ups copied, behind a strong confirmation. Forbeast's side (owner records, the move job) is built in parallel (Forbeast spec 024).

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Build this site's side of the contract below: a CEO-only "Pages" ownership screen calling Part S, and the Part P endpoints Forbeast calls (adopt, export, import, release, activate). Env `FORBEAST_SITE_KEY` / `FORBEAST_SITE_SECRET` (names only in .env.example); without them the screen says the site is not linked and the endpoints answer 503. | Forbeast is the one record of owners. |
| 2 | Screen: every page with owner (this site / the other site / Unowned), pending requests (who, when, direction), and the history (from `GET site/transfers`). Actions: Take (unowned), Move to <other site> (owned here), Ask for this page (owned there), Accept / Decline (requests made by the other site), Cancel (own requests). CEO only (`EnsureCeo`); other roles get 403 and no menu link. Phone width (390 px) works. | Owner: CEO does it, both sides. |
| 3 | Confirmation for take, request and accept: the CEO types their **own password** (checked with `Hash::check` here, never sent anywhere) and the phrase shown on screen (`TAKE <page> TO <SITE>` / `TRANSFER <page> TO <SITE>`). No lockout; a plain rate limit (10/min per user) is enough. | Owner: "matinding confirmation… ikaw na magisip"; no lockout. |
| 4 | No new tables. Ownership is read from Forbeast; this site keeps what it already keeps in page_settings (history rows with `source` "transfer <id> from <site>" / "taken by <actor>"), chatbot_customer_values, chatbot_sequence_subscriptions. A held subscription is marked with the existing columns (e.g. status `held`) — say which. | Owner's lean-schema rule. |
| 5 | adopt: create/update the page's page_settings (botcake.page_id = the Meta page id, the page name, chatbot provider gateway, forbeast.token) and run the existing `ForbeastPageSetup::connect`; `take` → bot off (`chatbot.active` 0) until the CEO sets it up and turns it on. A page name that already exists for a different Meta page id → 409, nothing saved. | Reuse today's connect path. |
| 6 | export/import exactly as the contract: values by flow **name** (Main Flow and any flow-named field), sequences by sequence **name** with `current_step` (position) and `next_send_at`, fields without `__*` run-state keys; imports idempotent, chunked, all-or-nothing per call; a missing flow/sequence name → 422 `missing_names` before anything of that part is saved. Mid-flow state (waits, `__wait`, pending AI/send requests) is never copied: those customers start at the Main Flow on their next message. | Owner: copy values, saved details, PREORDER at the same step; mid-flow back to Main Flow. |
| 7 | release: bot off with reason, subscriptions of the page ended with exit_reason `transferred`, waits and pending AI/send requests of that page forgotten, Forbeast connection rows deactivated (history kept), bot-config cache (spec 163) forgotten. activate: held subscriptions released keeping step and `next_send_at`. | No double follow-ups, nothing answered by two sites. |
| 8 | Part P is signed per the contract (timestamp + HMAC with `FORBEAST_SITE_SECRET`, 300 s); never log tokens, secrets or field values. Sequence logic and timings are not changed. | Security; owner: sequence logic "wag na galawin". |

## Cases (S-164)
P1: .1 CEO sees the screen with owners and pending requests (Part S faked); a non-CEO gets 403 and no link; unlinked site → clear message; .2 Take/Move/Ask/Accept each need the right password AND phrase: wrong password → refused, nothing sent to Forbeast; wrong phrase → refused; right → the Part S call with actor; Accept/Decline only on the other site's requests, Cancel only on own; .3 adopt (signed) creates the page settings and connects it (ForbeastPageSetup faked), take → bot off; a bad signature or old timestamp → 401; name clash → 409; .4 export pages through values/fields/sequences with cursors (≤500), no `__*` keys, no secrets, `since` filter works; .5 import is idempotent (twice = same rows), maps flow and sequence by name, missing names → 422 and nothing saved; held subscriptions send nothing until activate; .6 release ends subscriptions (`transferred`), forgets waits/pending requests, bot off with reason, connection deactivated; activate releases held ones at the same step; .7 existing Chatblast/Botcake/PageSettings suites pass. P2: .8 390 px.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` adds no new failures vs 87cb0e1e. Result with deploy notes (env lines, migration if any, both sites; Incepxion gets the same commits). End with "164: <done|blocked> — <one line>".

## Out of scope
Forbeast's side (spec 024); changing sequence logic or timings; deleting old data; deploying.

## Contract: page owner and transfers between the partner sites (Forbeast spec 024 ⇄ Likha spec 164)

Forbeast is the one place that knows which site owns a page. Each partner site (Likha = likhaaitech.com, Incepxion = incepxion.pro) is a **site system** in Forbeast: an existing shared `external_systems` row (page_id NULL, spec 007) with the site's origin as `webhook_url` and its own `webhook_secret`. The site keeps that system's `fbs_` key in its env (`FORBEAST_SITE_KEY`) and the secret as `FORBEAST_SITE_SECRET`; nobody types them in a screen.

**Part S — site → Forbeast** (`Authorization: Bearer fbs_…`; the system must be a site system; JSON; every write carries `actor` = "<name> <email>" of the site user, recorded):
- `GET /api/v1/site/pages` → `{"site": "likha", "pages": [{"meta_page_id", "name", "picture_url"|null, "owner": "likha"|"incepxion"|null, "connected_at", "pending": [{"id", "direction": "push"|"pull", "from", "to", "requested_by", "requested_at"}]}]}`: every connected page of the company.
- `POST /api/v1/site/pages/{meta_page_id}/take` `{actor, phrase}` → 202 `{"transfer_id"}`: only when the page has no owner. Phrase = `TAKE <page name> TO <SITE NAME>`.
- `POST /api/v1/site/pages/{meta_page_id}/transfers` `{actor, phrase}` → 201 `{"id"}`: a request to move the page between the two sites. If the caller owns the page it is a **push** (to the other site); if the other site owns it, a **pull** (to the caller). Phrase = `TRANSFER <page name> TO <SITE NAME>` (destination). Several pending requests per page are allowed; the page keeps working where it is until one is accepted.
- `POST /api/v1/site/transfers/{id}/accept` `{actor, phrase}` → 202: only by the site that did **not** make the request. Same phrase as the request. Starts the move; every other pending request of that page becomes `superseded`.
- `POST /api/v1/site/transfers/{id}/decline` and `/cancel` (requesting site) `{actor}`.
- `GET /api/v1/site/transfers?page=` → the page's history: every request, step and outcome, with actors and times.
- Errors `{"error","message"}`: 401 unauthorized, 403 not_a_site / not_your_turn, 404 unknown_page, 409 already_owned / not_pending / moving, 422 wrong_phrase.
- The **password** check is the site's (it holds the passwords): the site checks the CEO's own password before calling any write above, and sends nothing about it.

**Part P — Forbeast → site** (signed like spec 022: `X-Gateway-Timestamp`, `X-Gateway-Signature: sha256=HMAC("<timestamp>.<raw body>", site secret)`, 300 s window; `X-Gateway-Transfer: <id>`):
- `POST {origin}/api/gateway/site/pages/{meta_page_id}/adopt` `{"name", "token": "fbp_…", "mode": "take"|"transfer"}` → 200: the site saves the page token and connects the page the way its Connections popup does today (`ForbeastPageSetup::connect`: new webhook secret, PUT webhook to Forbeast). For `take` the page's bot starts **off**. The token is never logged or returned.
- `GET {origin}/api/gateway/site/pages/{meta_page_id}/export?part=values|fields|sequences&cursor=` → `{"items": [...], "next": cursor|null}`, at most 500 items a call:
  - `values`: the page's chatbot settings that a transfer copies (the 162 fields plus `active`, and the Main Flow **by flow name**), never secrets, tokens or AI prompts.
  - `fields`: saved customer values `{psid, key, value, updated_at}`, without run-state keys (`__*`).
  - `sequences`: active subscriptions `{psid, sequence (by name), current_step, next_send_at, enrolled_via}`.
  - `?since=<ISO time>` limits `fields` to rows changed after that time (the final catch-up).
- `POST {origin}/api/gateway/site/pages/{meta_page_id}/import?part=values|fields|sequences` `{"items": [...]}` → 200 `{"saved": n}`: idempotent upsert (same item twice = same result). Flows and sequences are matched by **name**; a name the target does not have → 422 `missing_names` listing them, and nothing of that part is saved. `values` keeps the source's on/off (the target gets no events for the page until the switch). `sequences` are imported **held**: nothing is sent for them until `activate`, so a customer never gets the same follow-up from both sites.
- `POST {origin}/api/gateway/site/pages/{meta_page_id}/release` `{"to": "<site>"}` → 200: the old site stops answering the page: bot off (with the reason "transferred to <site>"), active sequence subscriptions ended with exit reason `transferred`, waits and pending AI/send requests of that page forgotten, its Forbeast connection marked disconnected (history kept).
- `POST {origin}/api/gateway/site/pages/{meta_page_id}/activate` → 200: the new site releases the held subscriptions (each keeps its step and its `next_send_at`; one already past is sent on the next sequence tick).

**The move (Forbeast job, after accept; each step recorded on the transfer):**
1. check: both sites answer; export `values` from the source and dry-run nothing else.
2. copy `values`, `sequences`, then `fields` from source to target, in chunks (export → import). Any 4xx/5xx after retries → the transfer fails here, **nothing changed for customers**, the page stays with the source, both CEOs see why.
3. switch: issue a new page token (`PageTokens::issue`) and `adopt` on the target (this moves the webhook; spec 020 keeps the old connection as `kept`). From here events go to the target.
4. `release` on the source.
5. catch-up: `fields?since=<time step 2 started>` copied again.
6. `activate` on the target; owner = target; done.
Steps 3–6 retry on failure (the page is already moving); after 3 failed tries the transfer is `stuck` and shown to Admins in Forbeast with the step that failed. Customers who were in the middle of a flow on the source simply start at the Main Flow on their next message (waits are not copied).

**Reversing** = a new transfer the other way. **Take** = steps 3 and 6 only (no copy), bot off until the CEO turns it on.
