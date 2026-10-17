# Plan 048: chatblast campaigns on their own page

Spec: `docs/specs/048-chatblast-campaigns.md`.

Threat model: untrusted = everything a logged-in user sends to the new routes (campaign name, statuses, pages, texts,
days/versions, send times, mode), order data (`macro_output`, `from_jnts`) and J&T/Botcake answers. Trusted = the
migrations, config, the scheduler.

## Design

**One key, as today.** Every campaign has a `kind` string (unique). The two migrated campaigns keep `delivering` and
`in_transit`; a new campaign gets `c<id>`. `botcake_messages.kind` keeps holding that key, so the dedupe key
`waybill|kind|day`, day numbering (`sent_n` per kind), "one message per waybill per kind per Manila day", recheck, rerender,
stale skip and the sender work per campaign without a second rule. `botcake_messages.campaign_id` (nullable, index) is
filled for every new row and for the old rows by the migration.

**Table `chatblast_campaigns`:** `id`, `kind` (30, unique), `name` (100), `statuses` (JSON list of `from_jnts.status`),
`pages` (JSON list, NULL = all pages), `window_days`, `days` (JSON: days × versions, the 046 shape), `requires_on_delivery`
(bool: the 047 delivering rules), `mode` (`manual`|`auto`, default manual), `send_times` (JSON list `HH:MM` Manila),
`active` (bool), `auto_ran_at` (last auto run), `mode_changed_by`, `mode_changed_at`, `log` (JSON, the last 200 changes:
text edits with old/new, mode, on/off; who and when), timestamps. One table only: the edit history lives in `log`.

**Migration** `2026_10_18_400000_create_chatblast_campaigns_table.php` (guarded with `Schema::hasTable`/`hasColumn`):
creates the table, adds `campaign_id`, inserts "In transit" (`in_transit`, In Transit, window 14) and "Delivering"
(`delivering`, Delivering, window 7, requires On Delivery) with the texts saved in `app_settings`
(`chatblast.sequence.<kind>`, when valid) or the current defaults copied into the migration, mode manual, on, send time
09:00; then `UPDATE botcake_messages SET campaign_id` by kind. The `app_settings` keys are left as they are. `down()` drops
the column and the table. `macro_output` and `from_jnts` are untouched.

**Build** (`Chatblast`): `Chatblast::KINDS` goes; the campaign row gives statuses, window, days, pages and the On Delivery
rule. `build(null)` = every active campaign; `build(kind)` of an off campaign builds nothing. Status match = the furthest
`from_jnts` row of the waybill (PROGRESS, as today) is one of the campaign's statuses; the "no RTS reason" rule stays for
In Transit and Delivering only (as on /jnt status: a return trip is not a delivery), other statuses (For Return,
Problematic, …) carry an RTS reason by nature. Pages filter by `pageKey`. `jnt_status` of a row = the waybill's status at
build. Every `$kind === 'delivering'` becomes `requires_on_delivery`; tracking order puts those campaigns first.

**Send** (`ChatblastSender`): `queue()` refuses rows of an off campaign; `deliver()` skips them ("Naka-off ang
campaign"); switching a campaign off turns its `queued` rows into `skipped` at once. Pause and the per-page daily cap stay
global as today.

**Auto** (`chatblast:auto`, scheduled every 5 minutes, Manila, `withoutOverlapping(30)`): for each active `auto` campaign,
the latest send time `t` of today with `t <= now < t + 3 h` that ran no auto run since `t` (`auto_ran_at < t`) is due.
Paused → nothing (the run is not marked; it runs when resumed within the 3 hours). Due → `build(kind)` (busy → next tick),
then an atomic claim (`auto_ran_at` set only when still `< t`), then `queue()` of the campaign's `ready` rows built today
(user null = system). The 3-hour limit keeps a long pause or a scheduler outage from sending at night.

**Routes** (`/chatblast/campaigns…`, NavAccess `/chatblast`, named throttles):
`GET /` page, `GET /data` (campaigns + counts today + sheet + Naipadala), `POST /` create, `POST /{id}` settings
(name, statuses, pages, window, send times, On Delivery, on/off, mode: `auto` needs `confirm=1`), `DELETE /{id}` (only
without messages; else turn it off), `POST /{id}/text` (one cell: day, version, text), `POST /{id}/day` (adds a day: copy of
the last day's version A), `POST /{id}/version` (adds a version to a day: copy of A), `POST /{id}/remove` (a version; the
last version removes the day; the last day can't go). Every text change goes through `invalid()` (non-empty, ≤ 1000,
known placeholders, ≤ 30 days, ≤ 10 versions), is saved under a row lock (two cells at once lose nothing), logged, and
re-renders the unsent rows as today. Statuses allowed = distinct `from_jnts.status` (cached 10 min) plus the campaign's
own; at least one. Send times `HH:MM`, up to 6.

**UI**: `/chatblast/campaigns`: campaigns list on top (name, trigger, pages, send times, mode, on/off, today built / sent
/ failed, edit), a form for new/edit, the flat sheet below (Campaign, Araw, Bersyon, Laman as a textarea saving on blur or
Enter with Saving/Saved/error, Preview with a sample order, Naipadala; filter by campaign; add day / add version / remove).
/chatblast: kind filter → campaign filter (names), editor replaced with a link; no campaign chosen → "Kunin ang mga bago"
builds every active campaign (the old default was delivering only). `/chatblast/sequence` (the old editor's endpoint)
goes; its tests move to the cell endpoint.

## Tasks

| # | Task | Side | Tier | Cases |
|---|------|------|------|-------|
| 1 | Table, migration of the two kinds, model | backend | medium | migration keeps texts, links rows, defaults when no saved text |
| 2 | Build and send by campaign (statuses, pages, window, On Delivery option, off) | backend | high | trigger statuses, pages filter, window, day numbering, stop rules, one per waybill per campaign per day, off stops build/queue/send, delivering still tracks |
| 3 | Campaign CRUD and the sheet endpoints | backend | high | create/update/delete, validation (texts, placeholders, days/versions, statuses, times), one cell saves + log + rerender, add/remove day/version, mode auto needs confirm and is logged, access |
| 4 | `chatblast:auto` and its schedule | backend | high | due at send time once per day, not before, not after 3 h, skipped off/paused/manual, busy build retried, queues only that campaign's ready rows |
| 5 | Campaigns page and flat sheet | frontend | medium | page renders for allowed roles, 404 for others; sheet data |
| 6 | /chatblast: campaign filter, link, old editor out | frontend | medium | list filters by campaign; page shows link |

Not parallel: tasks 2–4 share `Chatblast.php`/routes. Review: one adversarial `skeptic-reviewer` pass on the whole diff
before the result (high tier), fix loop for majors.

## Cron line for Incepxion

`* * * * * cd /path/to/incepxion && php artisan schedule:run >> /dev/null 2>&1` (exact path filled in the result from the
deploy notes when known; otherwise the release fills it).
