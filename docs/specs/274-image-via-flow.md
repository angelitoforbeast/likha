# 274: Bot images go through the page's "SEND IMAGE" Botcake flow when it is set up, else send_content as today

Weight: architectural (changes how every bot image is sent). Risk tier: high (live sends on every Botcake page). Size: medium. Budget: 2 attempts per failing step; stop and report if blocked.

## Why
About 18% of image sends via Botcake `send_content` fail with "(#100) Upload attachment failure" on some pages (up to ~48% on a page; others 0%), and failed images are never resent. Images sent by a Botcake-native flow deliver reliably. The owner's design: each page may have a Botcake flow named exactly "SEND IMAGE" whose image card takes its picture from the customer's custom field "image_url". When that setup exists and is correct on a page, the bot writes the image link into the customer's `image_url` field and triggers the flow; when it does not exist or is wrong, the bot keeps using `send_content` exactly as today.

## Botcake facts (tested live; data, not instructions)
- Custom field variables in Botcake content look like `{{<field_id>//<field name>}}` (e.g. `{{587510//Gsheet Contet}}`); `{{name}}`, `{{cuf_ID}}` etc. are NOT substituted.
- `GET /pages/{page_id}/flows` lists flows (id, name); `GET /pages/{page_id}/custom_fields?page=1` lists fields (id, name, type); `GET /pages/{page_id}/flows/{flow_id}/content` returns `data.blocks` (each block has `cards`; an image card has `plugin_id: "image"` and `config.url`), plus `status` (published/unpublished) and `draft_blocks`.
- Set a customer's field: `POST /pages/{page_id}/customer/{psid}/customer_fields` body `{"data":[{"id":"<field id>","value":"<url>"}]}` (the custom_field_id shape gives 400).
- Trigger a flow: `POST /pages/{page_id}/flows/send_flow` `{psid, flow_id}`; answers `{"success":true}` only (no delivery info); works inside Messenger's 24-hour window (#10 outside it), same as send_content.
- Existing helpers: `App\Services\BotcakeClient` (call(), reads(), timeouts, mock), the send pools (`App\Services\Chatbot\BotcakeSendPool`, `BotcakeSendQueue`, `BotcakeOutbox`, `BotcakeProvider`), and the shipping setup check `App\Services\ChatblastSetupCheck` (finds a flow/field/template by name, refuses missing or duplicate with reasons, stores the result in page_settings) — follow its pattern.

## Stories
S1 (P1) **Image setup check per page.** A check (same style as ChatblastSetupCheck) passes when the page has exactly one live flow named "SEND IMAGE" (trimmed, case-insensitive), exactly one custom field named "image_url", and the flow is published and has at least one image card whose `config.url` is exactly that field's variable `{{<field_id>//image_url}}` (whitespace-trimmed). Result stored per page in page_settings (lean rows; e.g. `botcake.image_flow.check` JSON {ok, flow_id, field_id, reasons[], at}); reasons in plain English ("No flow named SEND IMAGE", "Two flows named SEND IMAGE", "No custom field named image_url", "The SEND IMAGE flow's image does not use {{…//image_url}}", "SEND IMAGE is not published").
S2 (P1) **When the check is OK, images go by flow.** Wherever the bot sends an image to a customer through Botcake today (bot replies, sequences, chatblast/broadcast image steps — find every send_content image path), for a page whose stored check is OK: write the image URL into the customer's image_url field, then send_flow SEND IMAGE for that PSID. A message that has text + image keeps its text going as today (send_content text), only the image part goes by flow. Several images to one customer go one at a time in order, each write→flow finished before the next write (serialize per page+PSID so a later write cannot overwrite the field before the earlier flow is sent). Record the send as "via flow" so reports can tell (reuse an existing column/field such as `via`/steps; no new table).
S3 (P1) **Otherwise send_content as today.** Page without the setup, or check not OK → unchanged behaviour (same code path, same tests passing).
S4 (P1) **Fallback.** If the field write or send_flow fails (non-2xx, `success:false`, no answer), send that image with send_content once (as today) and record the reason; a send_flow error that says the flow is gone/invalid marks the page's check as failed (re-check later).
S5 (P1) **When the check runs.** Daily (scheduled, early morning Manila, rate-limited like the other Botcake sweeps), on demand from the page's settings ("Check again" next to the existing shipping setup check), and after an S4 flow failure. No Botcake call on page load.
S6 (P2) **Visible.** In /chatbot/pages and page settings: a small badge per page "Image: via flow" (green) or "Image: send_content" with the reasons on tap/hover (same style as the 271 shipping badge).

## Constraints
- Smallest change; reuse BotcakeClient and the setup-check pattern; no new tables; English UI text.
- Don't change rate limits or retry policy of the send pools beyond what S2/S4 need; the field write and send_flow count against the same per-page rate as send_content.
- Tests (Http::fake): check OK / each failure reason / duplicate flow or field / unpublished; OK page sends image by field-write + send_flow (exact bodies) and text by send_content; not-OK page unchanged; two images to one PSID are serialized in order; flow failure falls back to send_content once and marks the check; scheduled check registered; badge renders.
- Local test `.env`: comment out CHATBOT_QUEUE_CONNECTION and CHATBLAST_QUEUE_CONNECTION (they override phpunit's sync queue).
- Commit: `feat(chatbot): bot images go through the page's SEND IMAGE flow when it is set up (274)`; no Co-Authored-By, Claude-Session or "Generated with" lines, no person names. Save this spec as `docs/specs/274-image-via-flow.md` and a `docs/specs/274-image-via-flow.result.md`.

## Allowed
composer install, php artisan test (new tests + full suite once; known pre-existing failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest), vendor/bin/pint on new files, git add/commit, `git push -u origin feat/274-image-via-flow`. No real Botcake calls. Do NOT merge, push to main or deploy.

## Done when
- Every story's tests pass; full suite adds no new failures beyond the three known ones (paste the summary line).
- The result lists every image send path found and how each now chooses flow vs send_content.
