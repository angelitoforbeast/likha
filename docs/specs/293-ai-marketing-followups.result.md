# 293 result: AI follow-ups for Marketing

Base: live dea0c621. Branch `feat/293-ai-marketing-followups`.

## Plan

**Lean schema check (before building).** The spec's columns on `checklist_tasks` work; no separate table is needed. Every place that lists or counts tasks goes through one of two doors: the `ChecklistTask` Eloquent model (index, report, range report, CSV, manage, history, the submit/edit/delete/duplicate route binding, bulk actions, reorder, `max('sort_order')`) or the raw `DB::table('checklist_tasks')` query in `ChecklistDueToday::rows()` (badge, staff feed status, the new command). Nothing else in `app/`, `routes/` or `resources/` reads `checklist_tasks` or `checklist_task_users` directly. So:
- `ChecklistTask` gets a global scope "origin is null or not `ai`", applied only when the `origin` column exists (checked once per request, like `ChecklistDueToday::schema()`). Route binding then 404s an `ai` row, so staff can never submit to, edit or delete one through the old routes; the bulk actions already skip ids that `find()` does not return.
- `ChecklistDueToday::rows()` gets the same filter explicitly (`schema()` gains an `origin` flag).
- A new model `ChecklistAiFollowup` on the same table with the opposite scope (`origin = ai`) is the only way the feature reads or writes these rows.

**Pieces.**
1. Migration `add_ai_followup_columns_to_checklist_tasks` (each column guarded with `Schema::hasColumn`, sqlite-safe, index on `origin, follows_date`) and migration `add_ai_followups_nav_link` (CEO only), in the style of the recent nav link migrations.
2. `App\Services\AiFollowups`: settings (`AppSetting`), marketing staff (active, role `^MARKETING(\s*-\s*OIC)?$`, the `isMarketing()` rule), candidates from `ChecklistDueToday::rows()` + `isLate()` + the latest verdict, dedupe (any status, same task/person/date), the 3-a-day cap (draft + approved), monthly spend and cap, the prompt (with the last 20 reviewed follow-ups), the OpenAI call (Http client, `services.openai.followup_model`), JSON validation, the 240-character refusal, the deadline clamp, saving the draft, expiry, Done detection and the staff-feed events.
3. Command `checklist:ai-followups` and its schedule entry in `routes/console.php` (every 30 minutes, 09:00-21:00 Manila, without overlapping).
4. `ChecklistAiFollowupController` (CEO only, the same check as `ChecklistController::isCeo()`): the inbox page, approve, edit-and-approve, reject (reason required, at most 300), and settings (switch, cap, rate, prices).
5. The staff card on `/checklist` (only the person's own approved, Waiting follow-ups for today) and the header counts in English.
6. Design first with `impeccable` (brief in `docs/design/293-ai-followups/brief.md`), build the two views to that brief, then critique and audit local screenshots.

**Tests first** (`tests/Feature/Checklist/`, `Http::fake()`, stray requests refused): drafts (who is marketing: OIC in, MA out, CEO out, other departments out; late; not approved with its reason; no duplicate; 3 a day; switch off; budget reached; failed or bad answer; deadline clamp; 240-character refusal; prompt contents), review (403 for OIC and staff on the page and every action; approve; edit and approve; reject needs a reason; expiry at day end; Sent states), staff card (only its person, only approved, only today, clears and shows Done when the original is approved), every count and list unchanged with follow-up rows present (badge, `ChecklistDueToday`, staff feed status, `/checklist` counts, report, manage, history), and the learning record and staff events.

**Commits:** plan; design brief; tests and code per story; screenshots and fixes; result.

## What shipped

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
