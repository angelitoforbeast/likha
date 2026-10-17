# Brief 048: chatblast campaigns on their own page, every day's text visible at a glance

## Why

/chatblast (specs 046-047) sends Botcake messages per J&T status with days and versions, but the setup is a collapsed editor on the sending page with a dropdown per kind, and the two kinds (`delivering`, `in_transit`) and their statuses are fixed in code (`Chatblast::KINDS`). There is no send time, no other statuses, and no on/off per kind. The owner: "ihiwalay na route mo naman. tapos yung madali makita: day 1 intransit content, day 2 intransit content, yung ganto" and wants the content prepared per day.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| A separate page `/chatblast/campaigns` (link from /chatblast and back). At the top: the campaigns list (name, trigger status, pages, send time, mode, on/off, counts today: built / sent / failed). Below: ONE flat sheet of every message text across campaigns, one row per (campaign, day, version): columns Campaign, Araw ("Day 1"), Bersyon (A, B, ...), Laman (the text, editable in place, saves like the page-settings grid: on blur/Enter, with a Saving/Saved/error mark), Preview (rendered with a sample order), Naipadala (how many times this day+version was sent). Filter by campaign; add day / add version / remove buttons on the rows. Readable at a glance: no dropdown hides the texts | The owner's example |
| A campaign = name; trigger: one or more J&T statuses chosen from the statuses actually present in `from_jnts` (e.g. In Transit, Delivering, Problematic, For Return, Delivered); pages: all or a chosen list (only pages with chatblast settings are sendable; others show `no_config` as today); order window in days (by order `ts_date`); days with versions (as built in 046); one message per waybill per campaign per Manila day; stop when the waybill's status leaves the trigger statuses or the days run out; mode `manual` (build and send only by buttons, as today) or `auto` (build and send by itself at the campaign's send times, e.g. 09:00 and 15:00 Manila); on/off switch that stops everything for that campaign at once. `delivering` keeps its 047 rules (track before send, only On Delivery, rider fresh) as a per-campaign option "requires On Delivery" | Campaign-like setup the owner asked for |
| Storage: a new small table for campaigns (justified: many fields per campaign; days/versions as JSON in the row), `botcake_messages` gets a nullable `campaign_id` (small table, additive). Migrate the two existing kinds into two campaigns ("In transit" and "Delivering") with their current texts and rules, mode `manual`, on; existing rows linked to them; the old `kind` keeps working. Never touch `macro_output` or `from_jnts` schema | Lean schema, nothing lost |
| Auto mode: a scheduled command every 5 minutes checks campaigns whose send time has come today and not yet run, builds and queues their ready rows (respecting the per-page daily cap and pause). Default for every campaign is `manual`; switching to `auto` asks for confirmation in the UI and is logged (who, when). Likha's scheduler runs every minute; Incepxion has no scheduler cron: write in the result the exact cron line the release must add there | Sending to customers by itself must be a deliberate choice |
| /chatblast (the sending page) keeps working: its kind filter becomes a campaign filter; the old collapsed editor is replaced by a link to /chatblast/campaigns. Fix the old editor note that said {rider}/{rider_phone} "have no value yet" (they do since 047) | Clean |
| Access: `/chatblast/campaigns` follows NavAccess like /chatblast (CEO always; roles checked in nav settings for '/chatblast') | Same as chatblast |

## Tests

Campaign CRUD and validation (non-empty texts, known placeholders, at least one day/version, statuses from the allowed list); the flat sheet saves one cell and its history/version; migration of the two kinds keeps texts and links rows; build per campaign by trigger status, pages filter, window, day numbering, stop rules; auto run at send time once per day per campaign, skipped when off/paused/manual; one message per waybill per campaign per day; delivering campaign still tracks and requires On Delivery; access. Red first where natural. Whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

New Botcake flows/fields; per-campaign flow choice (all campaigns use the page's `flow.shipping` + `field.message`); analytics beyond the counts above.

## Final message

Commits; the tables and migrations; how a campaign builds and sends (manual and auto); the scheduler entry and the exact cron line for Incepxion; the campaigns page and the flat sheet; test lines and suite result; review findings and fixes; anything the brief got wrong.
