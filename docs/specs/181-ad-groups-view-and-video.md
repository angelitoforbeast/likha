# Brief 181: ad groups are viewed in the pages list (with each ad's video) and created in Edit

## Why
Spec 174 put everything for an Ad-based page in the /chatbot/pages table: the Replies switch under the page name, an "Ad groups" button that expands rows with Default, each group's editable values, its ad IDs with status, "Ads not in a group yet", add/remove ad IDs and "+ Add ad group". The owner wants a cleaner split: the pages list is for **seeing** — an expand on an Ad-based page shows its groups and each group's ad IDs (status, customers, last time) — and each ad ID can be clicked to **watch the ad's video/creative**, the same way /owner/private shows the ads per campaign. **Creating and changing** groups and their ad IDs happens in the page's **Edit** window, so the list never shows every ad ID of the page.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **Pages list (view):** the Replies switch stays under the page name. An Ad-based page's expand shows read-only: Default (page values), each group (name, its values compactly, its ad IDs with name, status On/Off since/New/No report data, customers 30 d, last customer, "as of" date), and a count "N ads not in a group yet" (the list itself is in Edit). No add/remove/create controls in the list — a "Manage in Edit" link opens the Edit window at the Ad groups section. Group **values** may stay editable inline in the list with ✎ like page cells if that is how page values are edited there today (keep consistency) — state your choice. | Owner: list to see, Edit to create. |
| 2 | **Edit window (manage):** the Ad groups section returns to the Edit window (Ad-based pages only): create group, rename, delete, add ad IDs (tick from "Ads not in a group yet" with status, or paste), remove ad IDs, set group values, History/Restore. Reuse the 172/174 endpoints and JS; no duplicate logic. | One place to create. |
| 3 | **Ad video/creative:** clicking an ad ID (in the list and in Edit) opens a small viewer with the ad's video or image and its headline/body, using the same source /owner/private uses to show ads per campaign (find it: owner private views/JS, ad_campaign_creatives / ad_catalog / creative URLs stored by the upload or the Meta sync). If no media is stored for an ad, show "No preview" and a link to the ad in Ads Manager (by ad id) if /owner/private has one. No new Meta calls on view. | Owner: see the video of each ad. |
| 4 | Access as today (same roles that edit page values; server-side). No change to resolution rules, statuses, storage, or the Replies switch semantics. No new tables. | Safe. |

## Cases (S-181)
P1: .1 Ad-based page expand in the list shows Default, groups, ad IDs with status and the "N not in a group" count, and no create/add/remove controls; .2 Edit window shows the Ad groups section with create/add/remove/paste/History; changes made there show in the list after refresh; .3 clicking an ad ID with stored media shows the video/image and text; an ad without media shows "No preview"; .4 Page-based pages unchanged; .5 non-editors see nothing of it (403 on endpoints); .6 existing 172/174 and Chatblast/Botcake/PageSettings suites pass. P2: .7 390 px.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` adds no new failures vs fe54c227 (record the baseline). Result with: where the media comes from, deploy notes (expected: no migration; view:clear, route:clear if routes change), and that the same commits apply to Incepxion. End with "181: <done|blocked> — <one line>".
