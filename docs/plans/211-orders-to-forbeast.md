# Plan 211: encoded orders also go to Forbeast

## What is there (read on this base)

- `upload_macro` → `OrderEncode\Upload::run`: Insert → `LiveOrder::insertRaw` (new `macro_output` row, inside a
  transaction); Update → `LiveOrder::appendRaw` on the customer's open row. Each upload is an `order_encodes` row
  (status `uploaded`, `input.upload`, `input.fields` = the A..H after the conversation is built, `input.page_id`).
- `encode_order` (FlowHook::node) and the shadow/live sheet hook → `EncodeOrder` job → `Runner::live` →
  `LiveOrder::create` (new row) or `LiveOrder::update` (the open row). Nothing is written to `macro_output` before
  the encode ran, and a failed encode never writes.
- `encode_macro` (the "Encode order" of an uploaded row) → `EncodeOrder` → `Runner::uploaded` →
  `LiveOrder::encodeRow` on the upload's row.
- Test runs never reach these: `FlowHook::mode` is `off` for the Test tab, `uploadMacro`/`encodeMacro` return early.
- `Gateways::config($g)` holds each gateway's `url` (ends in `/api`) and `site_key`; `ForbeastSite::linked($g)` says a
  gateway is linked as a site; `ForbeastSite::self()` names this site (likha / incepxion) from `app.url`.
- Forbeast side (spec 042, `SiteOrderController`, `SiteOrderInput`): `PUT /api/v1/site/orders/{ref}`, ref
  `[\x21-\x7E]{1,100}`, `page_id` a Meta id, `version` int, `ordered_at` ISO with offset and not in the future, texts
  with max lengths (name 200, phone 20, details 5,000, item 200, shop 1,000, cxd 5,000, status 40), price
  `\d{1,10}(\.\d{1,2})?` or null, `fields` ≤ 20 values of ≤ 5,000 chars. 201/200 `{order_id, duplicate?, stale?}`,
  404 `page_not_found`, 422 `invalid_request` + `fields`, 401/403 key, 429 rate.

## Approach

1. **State on `order_encodes`** (one additive migration, no new table): `forbeast_state` (string, indexed:
   queued | sent | failed | paused), `forbeast_version` (bigint, the version of the latest send of this row, so a
   job of an older version stops and state writes are conditional), `forbeast` (json: ref, gateway, stage, tries,
   reason, queued_at, sent_at, order_id, duplicate/stale).
2. **`App\Services\OrderEncode\ForbeastOrders`**:
   - `queue(OrderEncode, MacroOutput, stage)`: never throws. Nothing when the switch is off
     (`order_encode.forbeast.since` empty), when the row was made before `since` (no backfill), or when the page
     has no linked gateway. Gateway: the page's `chatbot.gateway` row when valid, else `Gateways::active` when the
     page's provider is `gateway`; it must be `ForbeastSite::linked`. Version = now in ms, above the row's last.
     Saves the state, dispatches `SendOrderToForbeast` after commit.
   - `payload()`: ref `<site>-mo-<macro_output id>` (site from `order_encode.forbeast.site`, default
     `ForbeastSite::self()`), A..H from `input.fields` (else the step row), mapped per contract, texts cut to the
     contract lengths (valid UTF-8), price normalised or null, `ordered_at` from the row's TIMESTAMP in Manila with
     +08:00 (never in the future), status = the row's STATUS or the stage (`uploaded` / `encoded`).
   - `send(OrderEncode, version)`: one PUT with the gateway's site key; reads only `order_id`, `duplicate`, `stale`,
     `error`, `message` (cut) of the answer.
3. **`App\Jobs\SendOrderToForbeast`** (database connection, queue `order_encode.queues.forbeast`, default
   `default`): 2xx → sent; 404/422/other 4xx → failed with the reason, no retry; 401/403 → paused (cache flag per
   gateway; later sends of that gateway are paused without a call); timeout, 5xx, 429 → thrown, retried with
   backoff until 24 h, then failed. Superseded (version changed) → nothing.
4. **Hooks** (OrderEncode only; not FlowEngine, ChatbotAi* or ForbeastWatch): `LiveOrder::create`,
   `LiveOrder::update` and `LiveOrder::encodeRow` when they wrote, `LiveOrder::insertRaw`, and `Upload::run` after an
   accepted Update (appendRaw has no order row).
5. **Resume**: `order-encode:forbeast-resume` every 5 min: per gateway with paused sends and no pause flag, the
   oldest one is queued again as the probe; a successful send queues the rest of that gateway's paused sends.
6. **Owner list**: `/order-encode/forbeast` (CEO only): failed and paused sends (order, page, reason, time) with
   "Send again" (POST, new version, queued).

## Tasks

| # | Task | Cases | Risk |
|---|---|---|---|
| 1 | Stories in `qa/stories.md`; failing tests per case (red lines) | all | low |
| 2 | Migration + service + job + hooks | .1–.12, .14 | medium |
| 3 | Resume command, schedule | .10 | low |
| 4 | Owner list and Send again | .13 | low |
| 5 | Full suite, migrate/rollback, reviewer subagent, result | — | — |

## Tests

`tests/Feature/OrderEncode/ForbeastOrdersTest.php` (extends `UploadEncodeTestCase`: real FlowEngine, Runner and fake
OpenAI), Forbeast faked with `Http::fake`; one test per case, named `test_S_211_1_N_…`.

## Not done

No backfill, no change to Forbeast, no deploy. The switch `FORBEAST_ORDERS_SINCE` (config
`order_encode.forbeast.since`) is set at go-live (deploy notes).
