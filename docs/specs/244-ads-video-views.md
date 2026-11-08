# Spec 244: the Meta Ads sync brings in video views (3-second, 15-second / ThruPlay, 50%)

Type: change. Weight: bounded. Risk tier: medium (a migration on `ads_manager_reports`, a large live table; a wrong mapping writes wrong numbers into every synced row). Budget: small-to-medium job, at most 2 attempts per step.

## Why

The owner wants three video numbers per ad in the Ads Manager report, next to reach, impressions and link clicks: 3-second video plays, 15-second plays (ThruPlays), and 50% watched. The Meta Ads API sync (`App\Services\MetaAds\Insights` → `Sync` → `ads_manager_reports`) does not ask Meta for them today (`Insights::FIELDS`), and the table has no columns for them.

## Stories

S1 (P1). The sync stores the three numbers per ad and day.
- Happy: `Insights` requests the video fields from the Insights API and maps them to three new nullable integer columns on `ads_manager_reports`:
  - `video_plays_3s` ← 3-second video plays (the `video_view` action in `actions`).
  - `video_thruplays` ← ThruPlays (`video_thruplay_watched_actions`, plays of 15 seconds or to the end). If the API version Likha uses also returns `video_15_sec_watched_actions`, use ThruPlay as the "15-second" number and say in the result which field was used and why.
  - `video_p50` ← 50% watched (`video_p50_watched_actions`).
  Check the exact field names and shapes against the Graph API version Likha uses (`Graph.php`) and the Marketing API docs; each comes as a list of `{action_type, value}`; take the `video_view` entry.
- Edge: an ad with no video (image ad) or no such entry: the column is null, not 0, and nothing else in the row changes.
- Edge: the existing columns (reach, impressions, link clicks, spend, results, and the others) keep exactly their current values for the same API answer (a test with a recorded answer proves it).
- The sync's update path fills these columns on rows it updates, the same way as its other columns.

S2 (P1). The numbers are visible where the owner reads the Ads Manager report (the `/ads_manager/report` page and any table or export that lists reach/impressions/link clicks per ad): three columns "3s plays", "ThruPlays (15s)", "50% views", placed after link clicks. Empty when null.

S3 (P2). `Compare` (API vs uploaded report) is not changed; the uploaded CSV import is not changed unless it already has these columns in its mapping (then map them; otherwise leave it and say so).

## Decisions already made (do not reopen unless something is actually broken)

| Decision | Reason |
|---|---|
| Three new nullable columns on the existing `ads_manager_reports`; no new table. | The owner prefers no new tables; these are per-row numbers like reach. |
| The "15 seconds" number is ThruPlays unless the API gives a true 15-second field. | Meta's ThruPlay is 15 seconds or the whole video if shorter; it is the number Ads Manager shows. |
| Null means "no data", never 0. | An image ad has no plays; 0 would read as a failed video. |

## Threat model

Untrusted: Meta's API answers (validate integers; ignore unknown shapes). Trusted: the database. No user input is involved.

## Constraints

- Tests first, with recorded (made-up) API answers; never call Meta from tests.
- The migration must be safe on a large live MySQL table: adding nullable columns without defaults (MySQL 8 instant add where possible). Say in the result how long it is expected to take and that it does not rewrite the table.
- English UI text. Code comments explain the reason itself. Commit messages carry no Co-Authored-By, Claude-Session or "Generated with Claude Code" lines and name no people.
- No `.env`, server or deploy changes; the reviewer releases it after the owner's yes and runs the migration.

## Done when

1. Feature tests prove every case of S1 and S2 (and S3 if a mapping existed).
2. The full suite adds no new failures compared with `cf1dd382` (2 known failures: ExampleTest and ItemTableFitScriptTest).
3. Exactly one new migration (the three columns), with a working `down()`.
4. Branch `feat/244-ads-video-views` from `cf1dd382`, pushed to `origin`; the result lists its head commit and the release commands.

## Out of scope

Other video metrics (25%, 75%, 95%, 100%, average watch time); Compare; dashboards beyond the report page; backfilling old rows (the next syncs fill the dates they cover).

## Report back

In `docs/specs/244-ads-video-views.result.md`: plan, the exact API fields used and why, done-when evidence (commands and output), files changed, head commit, migration and release commands.
