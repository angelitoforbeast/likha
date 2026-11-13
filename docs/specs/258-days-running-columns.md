# 258: Days running on the page row, and one column list for the page and its settings

## Why
On /owner/private each page row says "since <date> (when <item> became primary)" under the page name, and its numbers are computed from that date. The owner wants a "Days running" column on the page row counting from that date. "Days running" exists today only in the campaigns expand table (from first_started), not on the page row.
The page row's columns are defined twice: the JS column list in resources/views/owner/private.blade.php (around lines 2160-2215, ids like orders_1d, proj_prof_1d, proj_prof_1d_mira) and OwnerColumnSettingsController::CATALOG['owner_private'] (around line 66), which feeds /owner/column-settings/owner-private (role visibility, order, conditional formatting). A new column must be added in both, and the settings page misses any that is forgotten. The owner wants the settings page not hardcoded.

## Stories
**S1 (P1) Days running on the page row.** A new column `days_running` (label "Days running") on the page summary table = whole days from the date the current primary item became primary (the same date the "since …" line shows) to the end date of the selected range (or today, if the range ends today), inclusive or exclusive — choose and state it; e.g. since Nov 3, viewing to Nov 9 → 7 days if inclusive. Sortable. Rows without a primary-since date show "—".
- It respects the existing role visibility and order from the column settings (hidden by default for non-CEO roles unless the owner turns it on; visible for CEO is fine).
**S2 (P1) One column list.** The page summary column catalog lives in one place (a PHP class/config) and is used by both the /owner/private page (the JS list is generated from it, keeping each column's label, sort key, align, min width and any other attributes the JS uses) and the column settings page (CATALOG['owner_private'] reads it). Adding a column in that one place makes it appear on the page and in the settings with no second edit.
- Existing saved settings (app_settings owner_private_cols, conditional formatting) keep working: same column ids.
- Columns that exist in one list but not the other today: list them in the result and decide sensibly (keep every column the page shows; make the settings show them all).
**S3 (P2)** If the campaigns catalog (CATALOG['campaigns'] vs defaultCampaignsCols in the views) has the same duplication, note it in the result; fixing it is optional only if trivial and covered by tests.

## Decisions already made
| Decision | Why |
|---|---|
| Days running counts from when the primary item became primary | Owner's choice; the row's numbers use that date. |
| Single source for the page summary columns | Owner: the settings page should not be hardcoded. |
| No migration | Pure code/config change. |

## Done when
1. Tests: days_running value for a page with a primary-since date and for one without; the catalog the settings page uses equals the page's column list (same ids, same order); a new catalog entry appears in both (test via the single source).
2. Full suite: no new failures vs d50d030c except the known/flaky ones (name them).
3. Result file docs/specs/258-days-running-columns.result.md: files (file:line), the inclusive/exclusive choice, columns that were in only one list, test counts, final sha.
4. Branch pushed.

## Out of scope
Other pages' column settings beyond noting S3, any deploy.
