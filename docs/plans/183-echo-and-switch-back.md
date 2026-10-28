# Plan 183: echo, who answered, switch back fixes, honest lag

Spec: `docs/specs/183-echo-and-switch-back.md`. Threat model: the gateway webhook body (signed by Forbeast, but the text
inside is customer/page text) is untrusted data; config, migrations and tests are trusted.

## Findings that shape the design

- Botcake's JSON API block (`/api/botcake/chat`) forwards **customer** input only; Botcake never sends its echoes to Likha.
  Every echo (Forbeast's, Botcake's, Page Inbox's, others') reaches Likha through the Forbeast gateway webhook, because
  Meta sends every echo of a page to every subscribed app. So echoes are stored from `HandleGatewayEvent` only.
- The gateway event already carries `is_echo` and `app_id` (spec 111 format); `fields()` drops `app_id` today.
- The webhook already accepts `{"kind":"batch","events":[...]}` for `send_status` only.

## Storage choice: `chatbot_events`, type `echo` (no migration)

`chatbot_events` has `page_id`, `psid`, `type`, `detail` (JSON), `created_at`, indexed `(page_id, psid, created_at)` and
`created_at`, and is pruned after 30 days. One row per echo: `type = echo`, `psid` = the customer, `created_at` = Meta's
timestamp when given (else now), `detail = {mid, app_id, sender, hash, text}` (hash = first 16 hex of sha1 of the
normalised text, text = first 200 chars). `botcake_chats` was rejected: every reader of it treats a row as a customer
message (counts, flows, inbox, ForbeastWatch). Readers of `chatbot_events` all filter by type except the Inbox thread,
which will exclude `echo` from its executed-actions list. Idempotent by Meta mid: a cache lock per mid plus a lookup in
the customer's rows (`detail->mid`, on the page/psid index).

## Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | Store echoes: `fields()` keeps `app_id` and `timestamp`; `HandleGatewayEvent` stores each echo (any provider, bot On or Off) through a new `ChatEchoes` service (sender map, store, latest-per-customer); the webhook batch accepts echo events beside `send_status`, and a bare JSON list body is read as a batch. | backend | high (new batch input) | S-183.1 (storage, batch, idempotent), S-183.2 (Person label, bot not paused) |
| 2 | Inbox: `Inbox::thread` returns echoes as page messages (label Bot (Forbeast) / Botcake / Person / other app); an echo matching a Likha reply (same text or image, within 120 s) labels that reply instead of a second bubble; the executed-actions list excludes echoes. Inbox view shows the label. | backend + small frontend in the same view | medium | S-183.1 (shows on Inbox chat), S-183.2 |
| 3 | Bot Health: `ChatEchoes::health()` per page, last 60 min — unanswered customers (customer message, bot On, not ignored attachment, not a test page, on a page that had an echo in the last 24 h, no echo from any sender in [received − 30 s, received + 5 min]) and double replies (two different app ids to the same customer within 60 s, latest 5 examples). Pages with ≥ 3 unanswered customers are red in `buildProblems` with reason "N customers not answered in 5 min" (banner follows). New section "Who answered" on the Health screen. | backend + view | medium | S-183.3, S-183.4 |
| 4 | Switch back: `health` joins `BACK_CONDITIONS`; the gap between switch backs is `back_gap_minutes` (60) instead of `back_hours` (6). | backend | medium | S-183.5 |
| 5 | Honest lag: in `ForbeastWatch::inbound`, a Botcake copy with no Forbeast twin is evidence only when the customer also has no echo from any sender since the copy (from 120 s before its arrival, the Botcake copy lag). Settle window unchanged; standby answering unchanged. | backend | medium | S-183.6 |
| 6 | Suites vs baseline, skeptic review (standard depth on the whole diff, adversarial on task 1), result. | — | — | S-183.7 |

## Config (services.gateway)

- `echo.unanswered_minutes` (`GATEWAY_ECHO_UNANSWERED_MINUTES`, 5)
- `echo.unanswered_customers` (`GATEWAY_ECHO_UNANSWERED_CUSTOMERS`, 3)
- `echo.double_seconds` (`GATEWAY_ECHO_DOUBLE_SECONDS`, 60)
- `auto_switch.back_gap_minutes` (`GATEWAY_SWITCH_BACK_GAP_MINUTES`, 60), replacing `back_hours`.

## Not touched

Sending, flows, chatblast, Forbeast, the person-reply pause (none added), routes and nav (spec 182 works there).
