# Brief 157: an "FB link" on /chatbot/pages that opens the Facebook page

## Why
On /chatbot/pages the staff see each page's Page ID but have no quick way to open the Facebook page itself. The owner: "lagyan na doon ng parang link … FB link nung page. Nandun naman na yung ID."

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Add a read-only **FB link** column (and the same link in the Edit modal / Connections popup header) built from the page's Facebook page id already stored (`botcake.page_id`, the same id Forbeast uses): `https://www.facebook.com/<page_id>`, opens in a new tab (`target="_blank" rel="noopener noreferrer"`), text "Open page ↗" (or an icon with that title). No id → empty cell. Only digits are accepted for the id (anything else → no link). | One click to the page. |
| 2 | Column follows the page's existing column rules (show/hide, order, filters if the grid has them per column) — no new settings, no new table, no new setting row. | Lean. |

## Cases (S-157 in qa/stories.md)
P1: .1 a page with id 1123777717482685 shows a link to https://www.facebook.com/1123777717482685 with target _blank and rel noopener noreferrer; .2 no id or a non-digit id → no link; .3 the link appears in the Edit modal/Connections header too; .4 the grid's existing column show/hide works for it. P2: .5 390 px.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/PageSettings` adds no new failures vs 816c5baa. Result with files, evidence, commits; end with "157: <done|blocked> — <one line>".

## Out of scope
Anything else on the page; deploying.
