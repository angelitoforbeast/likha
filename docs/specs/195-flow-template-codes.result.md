# Result 195: flow template codes

Status: done. Branch `feat/195-flow-template-codes`, based on 8705e881. Not pushed, not deployed.

## What shipped

1. **A template code per flow** (`chatbot_flows.template_code`, `ChatbotFlow`).
   - Format `LK-XXXX-XX`: six characters from `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (no 0/O, 1/I/L), about 887 million codes. Input is trimmed and upper-cased before lookup, so it is case-insensitive. Any other shape is simply unknown.
   - Staff with flow access (the same `/chatblast` access as editing a flow) use the **Template code** button in the flow editor's toolbar: the code with **Copy**, **Create code** (when there is none), **Regenerate** and **Revoke** (both confirm). Routes: `POST /chatbot/flows/{id}/code` (create or regenerate) and `DELETE /chatbot/flows/{id}/code` (revoke). Every issue and revoke is logged with the user id.
   - Regenerate replaces the code, so the old one stops working at once. Revoke sets it to null. Pages already linked keep their flow, because their `chatbot.flow_id` row is not touched.
   - A code is refused for a flow that was never published ("a page given its code would run nothing") and for a flow that is a sequence step (not a main flow).
   - The Flow list has a **Template code** column. Duplicating a flow never copies its code.
2. **Linked, not copied.** Applying a code calls the same `ChatbotPage::link` that staff use: the page's `chatbot.flow` (name) and `chatbot.flow_id` rows. A publish in Likha therefore changes every page on the flow (S-195.8). It is one flow per page: the page's main flow. Ad-group flows are untouched.
3. **The fields a flow needs** (`App\Services\Chatbot\FlowFields`). They are computed the same way as the Check column, whose code moved unchanged into `ChatbotPageCheck::usedKeys()`; the Check now calls that too.
4. **The gateway API** (`GatewayPageValuesController`). It has the same page lookup, signature and rate limit as the values API. See the contract below.
5. **The Likha side.**
   - **Pages** shows the flow's code under the flow name in the FLOW cell.
   - A value cell whose current value was written from Forbeast shows **via Forbeast**, with who in its title. A Likha edit removes the mark.
   - Every apply writes the page history. The FLOW history and Changes show "via Forbeast: with code LK-…, by <Forbeast user>", or "via Forbeast: with code LK-…" when no user was sent.
6. **No change** to flow logic, sending or the gateway switch.

## JSON contract

**Auth for all routes.**
- It is exactly the values API's: `X-Gateway-Timestamp: <unix s>` and `X-Gateway-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>">`, where GET signs an empty body.
- The secret is the page's own webhook secret of one of its gateways (`forbeast.webhook_secret`, or `incepxionbot.webhook_secret` when that gateway is set up), with no global secret.
- The timestamp may be at most 300 s off.

**Order of checks for all routes.**
1. A page that is not on this site gets 404 `unknown_page`. A missing `?page=` gets the same.
2. A bad, missing or stale signature gets 401 `bad_signature`.
3. More than 60 requests a minute per page (shared with the values API) gets 429 `rate_limited`.
4. Only then is the code looked up. An unknown, malformed or revoked code gets 404 `unknown_code`.

Error bodies are `{"error": "<code>", "message": "<text>"}`.

### `GET /api/gateway/flow-codes/{code}?page={metaPageId}`

```json
{
  "page_id": "1001", "page_name": "Page Uno", "code": "LK-7KQM-4X",
  "flow": {"id": 12, "name": "Main Flow from CBB", "steps": 3, "updated_at": "2026-10-29T14:02:11+08:00"},
  "fields": [
    {"key": "price", "label": "PRICE", "type": "text", "value": "198", "editable": true, "updated_at": "2026-10-29T13:55:40+08:00",
     "group": "Product", "kind": "number", "required": true, "hint": "The price in pesos, e.g. 1299.", "max_length": 50000,
     "read_only": false, "pair": null, "updated_by": "likha"},
    {"key": "seq_2", "label": "Seq 2", "type": "textarea", "value": "Step two", "editable": true, "updated_at": "…",
     "group": "Sequence", "kind": "long_text", "required": true, "hint": "Follow-up message 2.", "max_length": 50000,
     "read_only": false, "pair": 2, "updated_by": "forbeast"},
    {"key": "seq_img_2", "label": "Seq Img 2", "type": "image", "value": null, "editable": true, "updated_at": null,
     "group": "Sequence", "kind": "image", "required": false, "hint": "A link to the picture sent with follow-up 2 (https).",
     "max_length": 50000, "read_only": false, "pair": 2, "updated_by": null},
    {"key": "shop_details", "label": "SHOP DETAILS", "type": "textarea", "value": "PAGE: Page Uno\nPRICE: ₱198", "editable": false,
     "updated_at": "…", "group": "Shop details", "kind": "long_text", "required": false,
     "hint": "Auto: ITEM NAME IN JNT DASHBOARD + PRICE + QUANTITY", "max_length": null, "read_only": true, "pair": null, "updated_by": "likha"}
  ]
}
```

- `flow.steps` is the number of steps in the live (published) graph, not counting Start. `flow.updated_at` is when the live version was published.
- The graph itself is never sent: the flow's own texts, prompts and sheet links stay in Likha.
- `fields` carries **this page's** values only.

### `POST /api/gateway/pages/{metaPageId}/flow`

- Body: `{"code": "LK-7KQM-4X"}`, at most 2 KB. The optional `X-Forbeast-User` is at most 200 characters.
- 200 returns the shape above plus `"changed": true|false`; it is false when the page already had that flow.
- Applying never deletes or changes a value; fields the new flow doesn't use stay stored.
- 422 `invalid_value` is returned for a missing or empty `code` or an over-long user, with `errors`.
- 404 `unknown_code` changes nothing.

### `GET /api/gateway/pages/{metaPageId}/flow`

- It returns the same shape for the page's current flow (`chatbot.flow_id`, whether the bot is On or Off).
- With no flow: `{"page_id": "1001", "page_name": "Page Uno", "code": null, "flow": null, "fields": []}`.
- `code` is null when the flow has no code.

### Values API (162), extended and backwards compatible

- `GET /api/gateway/pages/{id}/values` keeps the same field list, the same order and the same first six keys (`key, label, type, value, editable, updated_at`). Each field also gains `group, kind, required, hint, max_length, read_only, pair, updated_by`.
- `required` is relative to the page's current flow; with no flow every field is `required: false`. Prompts are still never in this list.
- `PUT` works as before. Values it writes read back `updated_by: "forbeast"`; this uses the existing `page_settings.source` column, so no new column is needed.
- `PUT` also accepts any botfield the page's current flow needs that is outside the 162 list, for example a Prompt the flow reads or a "+ Column" field, so every needed field can be filled from Forbeast.
- Reserved keys (`flow`, `flow_id`, `provider`, `gateway`, `active`, `ai_*`, `auto_switch`) and `shop_details` stay `field_not_editable`, even when a flow names them (tested).

## Field metadata rules

- **Which fields.** These are the botfields the flow reads:
  - every `{{Name}}` and Condition variable in the published graph, and in the flows of the sequences it subscribes to;
  - each one matched by key or label (`ChatbotVars::norm`, the same lookup the run and spec 192 use);
  - plus the botfields named inside a used botfield's value on this page.

  Customer fields and system variables are not botfields.
- **SHOP DETAILS.** When it is used, its parts (item name, price, quantity) are needed and required. SHOP DETAILS itself is `read_only`, `editable: false` and not required.
- **Pairs.** Seq N and Seq Img N always come together, `seq_N` then `seq_img_N`, both with `pair: N`. The one the flow doesn't use is `required: false`.
- **required.** A field is required when it is used and not optional. Seq 11–20 and the After Order fields stay optional, as in the Check.
- **group.**
  - **Product:** item name, price, quantity, picture.
  - **Forms and messages:** Main Flow, Main Flow 2, Fill-Up Form, Loop 3 Form, After Order.
  - **Sequence:** Seq, Seq Img.
  - **Comment replies:** Comment Reply 1–3, Comment PM.
  - **Shop details:** SHOP DETAILS.
  - **Other:** prompts, "+ Column" fields and the rest.
- **Order.** Fields are ordered by group in that order, then by the values API's order and the site's column order.
- **kind.**
  - `image`: picture, Seq Img and After Order Img.
  - `number`: price and quantity. This is only a hint; values are not forced to digits.
  - `long_text`: the long fields, the four Forms and messages texts, and SHOP DETAILS.
  - `text`: everything else.
- **type.** `type` keeps the 162 meaning (`text | textarea | image`).
- **max_length.**
  - Comment PM: 2000. Comment Reply: 8000. These are Forbeast's limits, which the Check already warns about.
  - Everything else: 50000.
  - SHOP DETAILS: null.
- **updated_by.** `forbeast` when the active row was written through the gateway (it has a `source`), `likha` otherwise, and null when the field has no value. For SHOP DETAILS it comes from the newest of its parts.

## Case table

| Case | Test (`tests/Feature/Chatblast/FlowTemplateCodesTest.php`) | Result |
|---|---|---|
| S-195.1 | `test_S_195_1_staff_create_copy_regenerate_and_revoke_a_flow_code` (403 without access, format, Copy on the editor, code in the Flow list, regenerate kills the old code, case-insensitive, revoke, no code for an unpublished or sequence-step flow) | pass |
| S-195.2 | `test_S_195_2_get_a_code_returns_the_flow_and_its_fields_grouped_with_required_and_this_pages_values` (the flow's own text, another page's price and unused values never appear) | pass |
| S-195.3 | `test_S_195_3_post_a_code_sets_the_pages_flow_writes_history_and_keeps_every_value`, `test_S_195_3_a_page_without_a_flow_reads_null_and_the_apply_without_a_user_still_says_forbeast` | pass |
| S-195.4 | `test_S_195_4_a_revoked_or_unknown_code_is_404_and_changes_nothing` | pass |
| S-195.5 | `test_S_195_5_a_page_that_is_not_on_this_site_is_404_on_every_flow_endpoint` | pass |
| S-195.6 | `test_S_195_6_an_unsigned_or_wrongly_signed_call_is_401_and_changes_nothing` | pass |
| S-195.7 | `test_S_195_7_values_from_forbeast_read_back_as_forbeast_likha_marks_them_and_needed_fields_can_be_filled`, `test_S_195_7_a_flow_naming_system_keys_never_makes_them_editable_from_forbeast` | pass |
| S-195.8 | `test_S_195_8_publishing_the_flow_in_likha_changes_every_page_that_uses_it` | pass |
| S-195.9 | `test_S_195_9_seq_and_seq_img_come_back_as_pairs_and_only_the_used_one_is_required` | pass |
| S-195.10 | full run below | pass |

**Red runs.**
- S-195.1 first failed with "Expected response status code [403] but received 404" (no route).
- S-195.2–.9 first failed with "Expected … [200] but received 404" (and 404 instead of 401 for .6).
- After the API was built, .3 and .7 failed on the Pages data: "Undefined array key flow_code"; and `forbeast` was already a row key, holding the token info, so the mark is now `from_forbeast`.
- After the review, the sequence-step case failed with "Expected … [422] but received 200".
- The system-keys test passed on its first run. It pins behaviour that was already safe.

**Runs.** Each was `php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings`.

| Run | Tests | Assertions | Failures | Skipped | Deprecations |
|---|---|---|---|---|---|
| Baseline at 8705e881 | 1665 | 51928 | 0 | 1 | 67 |
| After the build | 1675 | 52056 | 0 | 1 | 67 |
| Final (after the review fixes) | 1676 | 52079 | 0 | 1 | 67 |

The final run has no new failures.

## Story changes

- S-195 was added to `qa/stories.md` from the brief.
- **S-195.1** gained "a sequence-step flow gets no code", from the review.
- **S-195.7** gained "system keys stay refused even when the flow names them", from the review.
- **S-162.1** pinned the exact shape of a values field. It now pins the six old keys and values: the new metadata is added, and nothing old changes.

## Review

The skeptic reviewer ran adversarially on the gateway routes and at standard depth on the rest. It found no blocker and no major, and the owner's three checks hold:

1. A code never gives one site's flow or values to another company's page. Each route finds the page from the path or `?page=` and checks that page's own secrets before any lookup. Responses carry only this page's values, plus the flow's id, name, step count and date — never the graph. Each site has its own database, so a code from Likha is unknown on Incepxion and the reverse.
2. Signed calls only. `unknown_code` only comes after a good signature and the rate limit, so codes cannot be guessed without a page secret.
3. Existing values are never lost. An apply writes only `flow` and `flow_id`, through the normal history-keeping write. The PUT extension never reaches reserved or system keys.

**Fixed:**
- No code for a sequence-step flow.
- A test that reserved keys stay refused when a linked flow names them.

**Accepted in `TODO.md`, section 195:**
- The HMAC covers only `<timestamp>.<body>`, not the method or path. A captured GET can be replayed for 300 s across the page's GETs. This is the 162/194 scheme, and a fix needs a contract change with Forbeast.
- A page's own values can widen what its Forbeast PUT may edit, but only within that page.
- The page-flow GET shows the code of a hand-linked flow.
- The history wording.
- Prompts a flow reads now reach Forbeast.
- The `changed` flag in a race.

The browser check was not run (the session has no running app). The UI changes are:
- one toolbar button with an inline panel in the editor;
- one column in the Flow list;
- one text line under the flow name;
- a "via Forbeast" line in value cells.

All text goes through `textContent` / Blade escaping.

## For the owner

- **Prompts.** Spec 162 kept prompts out of Forbeast. With this spec, a prompt that a flow reads shows up in the flow endpoints and can be filled from Forbeast. The values GET still never lists prompts. Confirm that this is intended.
- **Linked flows share their sheets.** A template flow with Google Sheet, Upload to macro or Encode order steps writes the other page's orders to that flow's sheet and macro. This is the linked-flow rule, so check templates before giving out their codes.

## Deploy notes (Likha and Incepxion: the same commits, no site-specific code)

1. Deploy the code, then run `php artisan migrate --force`. This runs `2026_11_14_100000_add_template_code_to_chatbot_flows`: one nullable, unique `string(10)` column. It is guarded and safe to run again. Until it runs, the Flow list and Pages work as before without codes.
2. If routes are cached, run `php artisan route:cache`. The new routes are three API routes and two web routes. If views are cached, run `php artisan view:clear`, because the editor, Flow list and Pages views changed. No Vite build is needed: the scripts are inline.
3. No env, config or queue change is needed, and no worker restart: nothing in the run, sending or the watcher changed.
4. On Incepxion, codes are made by Incepxion staff on Incepxion's flows. A Likha code is unknown there (404 `unknown_code`), and an Incepxion code is unknown on Likha.
5. Forbeast spec 031 builds against the contract above. It calls these routes from Forbeast's server with the page's gateway webhook secret, as it does for the values API.

195: done — staff give a flow a template code; Forbeast reads a code with the fields it needs, applies it to a page (linked, values kept, history written) and reads the page's flow, with field metadata on the values API; one migration.
