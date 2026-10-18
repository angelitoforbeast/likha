# Plan 057: a new page sets itself up in Page settings

Spec: `docs/specs/057-page-auto-setup.md`. No migration: everything new is a `page_settings` row (system keys) or is
derived from `botcake_messages`.

## Design

1. **Auto-test on save** (`App\Services\BotcakePageSetup::check`).
   - **When:** `saveCell` calls it after `botcake.page_id` or `botcake.token` is saved and the page has both.
   - **Where:** inside the save response, with the 25 s test budget. That is the brief's "in the save response" option;
     a queued job would leave the row waiting for the 5 s poll with nothing to show.
   - **The token test:** one `custom_fields` call (shared rate key, `once()`, no retry, short timeout). The same body
     gives the custom fields, so the field detection costs no extra call. `BotcakeClient::tokenAndFields()` returns the
     ok/invalid flag and the parsed list.
   - **On ok:** `BotcakeFlowNames::afterTokenOk` reads the flows (one call) and the templates (one call), as today.
   - **Botcake errors** are caught and returned as `refresh_error`. The save itself is already committed.
   - **The row Test button** and "Test all tokens" use the same `check()`, so every test also refreshes the
     candidates.
2. **Candidates and auto-fill.**
   - **Flow** (in `BotcakeFlowNames`, from the flows list it already reads):
     - candidates: non-removed flows whose name contains "CHATBLAST" (case-insensitive);
     - stored as the system key `botcake.flow.shipping.options` (JSON `[{id,name}]`);
     - when `botcake.flow.shipping` is empty: the one "(post_order)" candidate if there is exactly one, else the only
       candidate. It is written through `PageSettings::set` with no user (logged as the system).
   - **Field** (in `BotcakePageSetup`, from the `custom_fields` body):
     - candidates: names that normalise (lower case, spaces and hyphens removed) to "gptinput" or "message";
     - stored as `botcake.field.message.options`;
     - auto-filled only when there is exactly one and the key is empty.
   - **Template:** spec 052's auto-pick, unchanged. It runs after the flow name is written, so a newly picked flow's
     "(post_order)" name feeds it.
   - **Never overwrite:** a value a person saved is never changed. The page shows a warning when a saved value is not
     among the candidates.
   - **The options keys** are detail keys (`PageSettings::DETAIL_PATTERN`): system-only, not grid columns, and sent in
     the row's cells for the page script.
3. **Page settings view** (`page_settings/index.blade.php`).
   - **Dropdown:** when there is more than one candidate, or the saved value is not one of them, a select of
     "name · id" sits under the ID. Choosing one saves it through the same save path as typing; typing stays possible.
   - **Guides:** red lines with the fix in the Botcake and Chatblast flow cells, once candidates were read:
     - no flow: "No CHATBLAST flow in Botcake. In Botcake, copy 'CHATBLAST - SHIPPING STATUS (post_order)' from another
       page to this page, then press Test.";
     - no field: "No 'GPT Input' custom field in Botcake. Create it, then press Test.";
     - no template: "No approved post_order template in Botcake."
   - **Warnings:** "This flow is not a CHATBLAST flow in Botcake" and "This field is not a 'GPT Input' field in
     Botcake".
   - **"How to set up a new page":** a link that opens a modal checklist (not sticky).
   - **No reload:** the row Test (JSON test) and Sync (JSON when the request expects JSON) use fetch. Cust. and Orders
     stay links to other pages. Saves were already fetch.
4. **Ready** (`App\Services\ChatblastReady`).
   - **The rule:** a page is tested when its last confirmed send is at or after its last configuration change. The
     configuration is the newest active `page_id`, `token`, `flow.shipping` or `field.message` row. A confirmed send is
     either:
     - `botcake.test_ok`, a system key written by the sender when a test row ends `sent` (write, read-back and flow all
       2xx); or
     - the newest real `sent` row in `botcake_messages`, which is derived and stores nothing.
   - **Reset:** changing the flow, field, token or page id makes the earlier test older than the change. That is the
     reset; nothing is deleted.
   - **To send and auto:**
     - a complete but untested page gives `no_config` with the reason "Page not ready: needs a test send", through
       `Chatblast::missingByPage`;
     - each fetch's recheck also moves existing `ready` rows of untested pages to that state, and turns them back to
       `ready` once the page is tested;
     - the sender refuses non-test rows of an untested page with the same reason (the final guard).
   - **The choice:** they live in History under No config, rather than greyed in To send. To send then lists only
     what will really be sent.
   - **Page settings:**
     - a "Test send" line in the Botcake cell (amber "Needs a test send", or when the page was last tested);
     - a **Send test** button in the row. It sends the page's latest message text, or a fixed sample, to the Test PSID
       through `ChatblastSender`. Without a Test PSID it says "Set a Test PSID first" and links to the picker on To
       send;
     - a "Needs test N" chip;
     - green "Ready" only when complete and tested.
   - **The script** computes "tested" from the cells' full timestamps and `info.last_real_send`, so the 5 s poll turns
     a row Ready when `test_ok` arrives.

## Tasks

| # | Task | Cases | Side | Tier |
|---|------|-------|------|------|
| 1 | Auto-test on save, candidates, auto-fill, `tokenAndFields`, JSON Test and Sync | S-057.1, S-057.2, S-057.3 | backend | high (a new write path from a save) |
| 2 | Ready rule: `ChatblastReady`, `test_ok`, the To send, auto and sender gate, the Send test endpoint | S-057.5, S-057.6, S-057.7 | backend | high (sending) |
| 3 | Page settings view: dropdown, guides, warnings, checklist, Test send line and button, chip, fetch for row actions | S-057.4, S-057.8, S-057.9 | frontend | medium |
| 4 | Browser check with a fake Botcake; review | S-057.10 | both | — |

One reviewer pass at adversarial depth (sending) on the whole diff before the result.
