# Plan 195: flow template codes

Spec: `docs/specs/195-flow-template-codes.md`. Base 8705e881. Threat model: every `/api/gateway/*` request (path,
query, headers, body, the code) is untrusted until its signature checks out against one of the page's own gateway
secrets, and stays data after that (text, never instructions). Staff input on Likha screens is behind the existing
login and `/chatblast` access. Config, migrations and the owner's page rows are trusted.

## Design

- **Storage.** `chatbot_flows` has no settings or meta store, so one new nullable, unique column
  `chatbot_flows.template_code` (string 10). No new table. Revoke sets it to null (the old code is gone: 404 from then
  on); regenerate writes a new code (the old one stops working at once). Pages already linked keep `chatbot.flow_id`,
  nothing is touched on revoke.
- **Code format.** `LK-XXXX-XX`, 6 characters from `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (no 0/O, 1/I/L). Input is
  trimmed and upper-cased before lookup (case-insensitive). Anything else is 404 `unknown_code`. A code is created
  only for a published flow (an unpublished flow runs nothing).
- **Staff screen.** The flow editor gets a "Template code" button that opens a small panel: the code with Copy,
  Create (when none), Regenerate and Revoke (both confirm). Same access as editing the flow (`/chatblast`). Web
  routes: `POST /chatbot/flows/{id}/code` (create or regenerate), `DELETE /chatbot/flows/{id}/code` (revoke). The
  Flow list shows each flow's code.
- **Fields a flow needs** (`App\Services\Chatbot\FlowFields`). The used keys are computed exactly as the Check column
  does today (`ChatbotPageCheck`): every `{{Name}}` and condition variable in the published graph and in the flows of
  the sequences it subscribes to, matched to a botfield by key or label (`ChatbotVars::norm`, the resolver the run and
  spec 192 use), plus botfields named inside a used botfield's value (the page's own values). That code moves into a
  public `ChatbotPageCheck::usedKeys()` that the Check also calls (no behaviour change). Then:
  - SHOP DETAILS used → its parts (item name, price, quantity) are needed and required; SHOP DETAILS itself is
    read-only.
  - Seq N or Seq Img N used → both are returned, adjacent (`seq_N`, then `seq_img_N`), each with `pair: N`; the one
    the flow does not use is not required.
  - `required` = used and not optional (Seq 11–20 and After Order stay optional, as in the Check).
  - Each field: `key`, `label`, `group` (Product, Forms and messages, Sequence, Comment replies, Shop details, Other),
    `type` (legacy: text | textarea | image), `kind` (text | long_text | image | number), `required`, `hint`,
    `max_length`, `read_only`, `editable`, `pair`, `value`, `updated_at`, `updated_by` (`likha` | `forbeast` | null).
    `updated_by` is `forbeast` when the active row has a `source` (written through the values API or a code).
  - Order: by group, then the site's column order.
- **Gateway API** (in `GatewayPageValuesController`, same page lookup, signature, rate limit and errors as the values
  API: 404 `unknown_page` before the signature, 401 `bad_signature`, 429 `rate_limited`, then 404 `unknown_code`):
  - `GET /api/gateway/flow-codes/{code}?page={metaPageId}` → `{page_id, page_name, code, flow: {id, name, steps,
    updated_at}, fields}` with this page's values. `steps` = number of steps in the published graph (not the graph:
    prompts, sheets and texts of the flow are never sent). `updated_at` = when the live version was published.
  - `POST /api/gateway/pages/{metaPageId}/flow` `{code}` (optional `X-Forbeast-User`, at most 200) → links the page
    through `ChatbotPage::link` (the same `flow` / `flow_id` rows staff set), with source `with code LK-…, by <user>`
    so the page history shows "via Forbeast: with code …, by …". Returns the same shape plus `changed`. Values are
    never deleted.
  - `GET /api/gateway/pages/{metaPageId}/flow` → the same shape; `code` and `flow` null when the page has no flow
    (`code` null when the flow has no code), `fields` empty then.
  - Values GET: every old key stays; each field gains the metadata above, with `required` relative to the page's
    current flow. Values PUT: `updated_by` becomes `forbeast` (the existing `source` column). PUT also accepts the
    botfields the page's current flow needs that are outside the 162 list (e.g. a Prompt the flow reads, a
    "+ Column" field), so a Forbeast user can fill every needed field; SHOP DETAILS stays read-only.
- **Likha screens.** Pages: the FLOW cell shows the flow's code under its name; a value cell last written from
  Forbeast shows "via Forbeast" (title: who). History already shows "via Forbeast: …".

## Tasks

| # | Task | Side | Tier | Cases | Parallel-safe |
|---|---|---|---|---|---|
| 1 | Migration + `ChatbotFlow` code helpers (generate, normalise, find), staff create/regenerate/revoke routes, editor panel and Flow list column | backend + frontend | medium | S-195.1 | no (shared files with 4) |
| 2 | `FlowFields` (needed fields + metadata), `ChatbotPageCheck::usedKeys` extraction | backend | medium | S-195.2, .9 | no |
| 3 | Gateway API: code GET, page flow GET/POST, values GET metadata, PUT `updated_by` and needed-field edits | backend | high (new routes, untrusted input) | S-195.2–.8 | no |
| 4 | Pages screen: flow code and "via Forbeast" mark | frontend | medium | S-195.7 (Likha side), Decision 6 | no |
| 5 | Done-when suites, result, stories, review | — | — | S-195.10 | — |

All tasks touch overlapping files, so they run in order in this worktree.

## Tests

`tests/Feature/Chatblast/FlowTemplateCodesTest.php`, one test per case (`test_S_195_N_…`), using the signing helper
pattern of `GatewayPageValuesTest`. `GatewayPageValuesTest::test_S_162_1` pins the exact field shape, so it moves to
"contains the old keys" (story change).
