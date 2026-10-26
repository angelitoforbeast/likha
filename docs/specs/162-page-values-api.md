# Brief 162: Forbeast can read and edit a page's bot values

## Why
The owner wants to see and edit each page's bot values (Main Flow, sequences, pictures…) from Forbeast, which already forwards the page's chats here and signs them with the page's webhook secret (`GatewayEventController`, setting `ForbeastPageSetup::PROVIDER` / `webhook_secret`). The values stay here, in page_settings (provider chatbot), edited today on Likha's own screens (`PageSettingController`, `ChatbotController`, `ChatbotFields::columns()`, the ShopDetails builder of spec 158).

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Build part B of the contract below exactly (GET/PUT, HMAC with timestamp using the page's own secret only, the field list in order, editable flags, validation as Likha's own screen, one transaction, history kept, shop_details rebuilt, rate limit, error codes). | Forbeast depends on it. |
| 2 | Labels come from the same place Likha's screen takes them (ChatbotFields labels); the seq/seq_img count follows the site's own columns (Likha has fewer seq columns than Incepxion — return what exists). | Same names as staff see. |
| 3 | Audit: each change records the X-Forbeast-User actor (in an existing column if one fits, else a small nullable `source` column on page_settings — no new table) and shows "via Forbeast: <actor>" wherever Likha shows who changed a value. | Owner sees who changed what. |
| 4 | Clear the chatbot label/field caches (spec 161) that depend on these values after a write, so the bot uses new values at once. | No stale bot. |

## Cases (S-162)
P1: .1 GET with a valid signature returns the fields in contract order with labels, values, editable flags; prompts/flow_id/AI/auto_switch/botcake settings never appear; .2 bad signature / stale timestamp / unknown page / a page without its own secret → contract errors; .3 PUT valid values → saved with history and actor, response has fresh values, the bot's next run uses them (cache cleared); .4 PUT a non-editable or unknown key → 422 field_not_editable, nothing saved; invalid value → 422 invalid_value with per-key errors, nothing saved; .5 shop_details rebuilt when price/quantity/item name change; .6 rate limit 60/min per page. P2: .7 Likha's page-settings screen shows "via Forbeast: <actor>".

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` adds no new failures vs da1d158f. Result with deploy notes (migration if any, both sites). End with "162: <done|blocked> — <one line>".

## Rules
Conventional Commits; no assistant/owner names, no attribution trailers in commit messages, no "Decision N" in committed text. Never push/deploy/touch a server; never call another live app (fake it per the contract). php may only be on PowerShell's PATH (C:\Users\Forbeast\.config\herd\bin\php.bat). Medium job, 2 tries per step; commit after each passing stage; reviewer subagent at the end. The contract at the end is shared by three apps built in parallel: build only your side exactly as written; if the contract is wrong or impossible for your side, stop that part and say so in the result rather than changing it.

## Contract
# Contract: page bot values edited from Forbeast, pictures on Image Host

Three apps take part. Each worker builds only its own side and tests the other sides with fakes that follow this contract exactly.

## A. Image Host (botcake.pro): upload API

- Per-company API keys: an Admin of an Image Host company creates a key on a new "API keys" screen (shown once, stored as SHA-256, can be revoked; name + prefix + last used shown). Prefix `ih_`.
- `POST https://botcake.pro/api/v1/images`, header `Authorization: Bearer <key>`, multipart:
  - `file` (required): same image types and size limit as the dashboard upload; the same optimizer and storage (R2) as dashboard uploads.
  - `folder` (required): a folder name, `[A-Za-z0-9 _.-]{1,100}`. The folder is a top-level folder of the key's company with exactly that name; created if missing (idempotent, race-safe: two uploads at once create one folder).
- 201 response JSON: `{"id": <int>, "url": "https://botcake.pro/i/<token>.<ext>", "folder": {"id": <int>, "name": "<name>"}, "width": <int>, "height": <int>, "bytes": <int>}`. The url is the same public link format the dashboard gives.
- Errors JSON `{"error": "<code>", "message": "<text>"}`: 401 `unauthorized` (missing/revoked key), 422 `invalid_file` / `invalid_folder`, 413 `too_large`, 429 `rate_limited` (60 uploads/minute per key).
- The upload is logged in the existing activity log as "via API key <name>".

## B. Likha / Incepxion (same codebase): page values API

- Base: the partner's own origin (`https://likhaaitech.com`, `https://incepxion.pro`). Forbeast derives it from the page system's webhook URL (scheme + host).
- Auth: the same HMAC as gateway events: header `X-Gateway-Signature: sha256=<hex HMAC-SHA256 of the raw request body, or of the empty string for GET>` with the page's own webhook secret (page setting `forbeast.webhook_secret` for that page; no global fallback here), plus `X-Gateway-Timestamp: <unix seconds>` included in the signed string as `<timestamp>.<body>` — reject if more than 300 s off. Unknown page → 404 `unknown_page`; bad signature → 401 `bad_signature`.
- Actor header: `X-Forbeast-User: <name> <email>` (required on PUT, max 200 chars). Stored with the change for the audit trail.
- `GET /api/gateway/pages/{meta_page_id}/values` → 200:
  ```json
  {"page_id": "<meta id>", "page_name": "<name>",
   "fields": [{"key": "main_flow", "label": "Main Flow", "type": "text|textarea|image", "value": "<string|null>", "editable": true, "updated_at": "<ISO8601|null>"} ...]}
  ```
  Fields, in this order (only those that exist as columns on that site; seq_N / seq_img_N up to the site's own count): `main_flow`, `main_flow_2`, `fill_up_form`, `loop_3_form`, `item_name_in_jnt_dashboard`, `price`, `quantity`, `picture` (image), `seq_1..seq_N` (textarea), `seq_img_1..seq_img_N` (image), `comment_reply_1..3`, `comment_pm`, `after_order_1`, `shop_details` (editable false: it is built from the other values). Never prompts (`prompt_*`), flow/flow_id, AI settings, auto_switch, provider, secrets or any botcake.* setting.
- `PUT /api/gateway/pages/{meta_page_id}/values`, JSON `{"values": {"<key>": "<string|null>", ...}}` (only keys from the list above with editable true; anything else → 422 `field_not_editable` naming the key, nothing saved). Validation is the same as Likha's own page-settings screen for that key (lengths, URL for image fields must be https). Saves through the app's normal page-settings write (history kept: the old row replaced, not overwritten), in one transaction; rebuilds shop_details the way the app does when its inputs change. 200 → the same body as GET (fresh values). 422 `invalid_value` with `{"errors": {"<key>": "<message>"}}`.
- Rate limit 60 requests/minute per page.

## C. Forbeast: "Bot values" tab

- Shown on Pages → a page, for pages whose page token system has a webhook URL (the page is answered by a partner). Only the company's Admins and the user who connected the page (the same rule as the page token: `User::managesTokenOf($page)`) can see and edit; everyone else gets 403 and no tab.
- Forbeast's server calls B (never the browser), signed with the page system's webhook secret, with `X-Forbeast-User` set to the signed-in user.
- Picture fields: an Upload button sends the file to Forbeast's server, which uploads it to A with the company's Image Host API key (a new company setting, Admin-only, stored encrypted, typed by the Admin, never shown back — only "set / not set" and its prefix) and `folder` = the page's Meta page id. The returned url fills the field (with a preview); Save sends it to B. No key set → the field still takes a pasted https url, and the Upload button says the key is missing.
