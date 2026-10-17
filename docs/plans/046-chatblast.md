# Plan 046: /chatblast

Spec: `docs/specs/046-chatblast.md`. Threat model: untrusted = request input on the new routes (filters, ids, search, template text), Botcake answers, and stored text that came from customers (FB names, item names); trusted = config, migrations, the owner's settings. Sending is outward: no real send ever happens without an explicit press, and never twice for the same waybill and kind.

## Design decisions the spec leaves open

- **Table `botcake_messages`** (new; the send log). Columns: `page_name`, `macro_output_id`, `waybill`, `kind`, `fb_name`, `psid`, `vars` (JSON: name, item, cod, waybill, page, so the text can be rendered again), `text`, `jnt_status` (at build), `status`, `reason` (why `no_psid` / `no_config` / `failed` / `skipped`, e.g. the missing keys), `attempts`, `last_http`, `steps` (JSON, each step's method, path, request body, HTTP code, trimmed response, time), `is_test`, `test_of`, `dedupe_key` (`waybill|kind` for real rows, NULL for test rows; unique, so MySQL allows many NULLs), `queued_at`, `sent_at`, `created_by`, `sent_by`, timestamps. Indexes: `created_at`; `(status, created_at)`; `(page_name, created_at)`; `(waybill, kind)`; `(page_name, sent_at)` for the daily cap; `sent_at`.
- **"Delivering"** = `from_jnts.status = 'Delivering'` with an empty `rts_reason`, the rule `/jnt` status and `/page-settings/psid-coverage/orders` already use.
- **Required settings** for `ready`: `page_id`, `token`, `flow.shipping`, `field.message` (`page_id` is needed for every API path, so it is checked too).
- **A second "Kunin ang mga bago"** also re-checks rows still `no_psid` / `no_config` (the PSID may have been matched, a setting filled in) and turns them `ready`.
- **Template** in `app_settings` key `chatblast.template.<kind>`. Saving it re-renders the text of unsent rows of that kind (`ready`, `no_psid`, `no_config`, `failed`, `field_mismatch`, `skipped`); sent and queued rows keep theirs.
- **Pause** = `app_settings` `chatblast.paused`. A job that meets a pause leaves its row `queued`; Resume dispatches a job per queued row. A job claims its row with `UPDATE … WHERE status = 'queued'`, so a duplicate job does nothing.
- **Rate**: a shared spacing per page token kept in the cache (0.5 s between requests across jobs). **Cap**: sent real messages of the page since midnight (Manila) ≥ `services.botcake.chatblast_daily_cap` (default 3000) → `skipped` with the reason; it can be sent again the next day.
- **401/403** → `botcake.token_status` invalid (as the sync does), the row `failed`, and every other `queued` row of that page goes back to `ready` with reason "Huminto: token invalid".
- **Job**: `SendChatblastMessage` on queue `chatblast`, `$tries = 1`, `$timeout = 300` (3 steps × up to 4 tries × 15 s request timeout + backoff). Supervisor: `queue:work database --queue=chatblast --sleep=3 --tries=1 --timeout=320 --max-time=3600`, numprocs 1.
- **Test PSID**: chosen from the page's `botcake_psids` (source api) by name search; the browser gets name + last 4 + row id, the server stores the PSID as `botcake.test_psid`. "Send test" creates a copy row (`is_test`, `test_of`, no `dedupe_key`) to the test PSID.

## Tasks

| # | Task | Side | Risk | Covers |
|---|---|---|---|---|
| 1 | Migration + model, template + render, list building (button service + `chatblast:build` command) | backend | medium | list per status, no duplicates, placeholders |
| 2 | Client `call()` + send job (3 steps, field check, retries, 401, rate, cap, pause) + send/test/retry actions in a service | backend | high | send sequence cases, unique, test send, pause, cap |
| 3 | Controller + routes + NavAccess + nav link migration; list/counts/detail JSON | backend | high | grouped counts, query count flat for 1,000 rows, no token in the panel, access |
| 4 | `/chatblast` page (sheet-like table, chips, filters, side panel, poll) | frontend | medium | — (browser check) |
| 5 | `/page-settings`: `Test PSID` known column + picker endpoint | both | low | test PSID saved from a search |

Tasks share files (the service), so they run in order, not in parallel. One review (adversarial, since task 2 and 3 are high) on the whole diff before the result.
