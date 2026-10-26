# Result 157: FB link on /chatbot/pages

## What shipped
- `/chatbot/pages` grid: a read-only **FB link** column after Last chat. A page whose Page ID (`botcake.page_id`, already sent as `page_id` by `/chatbot/data`) is digits only gets "Open page ↗" linking to `https://www.facebook.com/<page_id>` with `target="_blank" rel="noopener noreferrer"`; otherwise the cell is empty.
- The same link in the Edit modal header (beside the name and ID, outside the dialog's label) and in the Connections popup header.
- One helper, `fbLink(id)` (`/^\d+$/` check), and `fbAnchor(id)` that builds the `<a>` through the page's text-safe `el()`. No server, route, setting or table change.

## Files
- `resources/views/chatbot/index.blade.php`: helper, column header and cell, `#pe-fb`, `#conn-fb`.
- `tests/Feature/Chatblast/ChatbotFbLinkTest.php`: S-157.1–.4.
- `qa/stories.md`: S-157.
- `TODO.md`: accepted minors.

## Case table
| Case | Test | Result |
|---|---|---|
| S-157.1 | `test_S_157_1_a_page_id_links_to_its_facebook_page_in_a_new_tab` | pass |
| S-157.2 | `test_S_157_2_no_id_or_a_non_digit_id_gives_no_link` (null, '', '12a3', ' 123', 'javascript:alert(1)', '123/../evil') | pass |
| S-157.3 | `test_S_157_3_the_link_is_in_the_edit_and_connections_headers` | pass |
| S-157.4 | `test_S_157_4_the_fb_link_column_sits_in_the_grid_with_the_other_fixed_columns` | pass |
| S-157.5 (P2) | none (no browser check run) | not checked |

`ChatbotFbLinkTest.php`: `OK (4 tests, 18 assertions)`.
Red run, before the code: all 4 failed. .1 and .2 with "the helper is on the page / Failed asserting that 0 is identical to 1", .3 on the missing source strings, .4 on the missing header. `/chatbot/data` already returned the id, so no server change was needed.

## Suites (Done when)
`php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/PageSettings` on the branch: `OK, but there were issues! Tests: 1155, Assertions: 8063, PHPUnit Deprecations: 48, Skipped: 1`. The run has 0 failures and 0 errors. The 4 new tests account for the difference from 1151. The only skip is `QueueRedisHorizonTest` S-088.4 (redis) because this machine has no Redis server. That skip comes from the environment, not from this change. No new failures vs 816c5baa.

## Story changes
- S-157.4 was "the grid's existing column show/hide works for it". The grid has no per-column show/hide: "+ Column" only adds a botfield. The fixed columns Chats today and Last chat also have no filter. Per decision 2 (no new settings), the case now reads that the column sits in the fixed column order after Last chat, with nothing added.

## Review
skeptic-reviewer, standard depth: no blockers or majors, 6 minors.
- Fixed: the Edit link moved out of `#pe-title` (it was being read as part of the dialog's name). The test now also deletes its `tempnam()` file.
- Accepted in `TODO.md` with reasons: the Node-dependent skip, the source-string checks for .3/.4, the shipped test markers, the Connections link refreshing only on reload, and no automated 390 px check.

## Not done
- Browser check and the 390 px case (P2) were not run in this session. The column is a plain non-frozen cell that scrolls with the grid.
- `npm run build` was not needed: the change is inline Blade JS, not a Vite asset.
- Not pushed and not deployed, per the brief.

## Conflicts
None between the brief and CLAUDE.local.md.

## Commits
- `1f2a4b2a` docs: spec 157 and its result template (spec 157)
- `2744ad80` feat: FB link to the Facebook page on /chatbot/pages, in Edit and in Connections (spec 157)
- `f93f9218` fix: Edit link outside the dialog label, test cleans its temp file, accepted minors logged (spec 157)
- this result commit

## Amendment 157-1: Facebook icon beside the page name, opens Messenger
This replaces the "Open page ↗" text link and the separate FB link column described above.

### What changed
- The FB link column is gone. A small Facebook icon now sits right after the page name in the grid's page-name cell. It is an inline SVG "f", fill `#1877F2`, `h-4 w-4` (16 px).
- The icon links to `https://m.me/<page_id>`, which opens the page's Messenger chat. It opens in a new tab with `rel="noopener noreferrer"`. Its `title` and `aria-label` are "Chat with <page> on Messenger".
- The rule is unchanged: an id that is digits only gets the icon; no id, or any other id, gets no icon.
- Edit modal: the icon is beside the header's name. It sits just outside `#pe-title`, the dialog's label, so screen readers don't read the link text as part of the dialog's name. That puts it after the ID, which shares the name's line there.
- Connections: the icon is right after the `Connections · <page>` title.
- The page name reaches the icon's title and aria-label through `setAttribute`, so it is treated as plain text. The SVG markup is fixed and holds no data.

### Files
- `resources/views/chatbot/index.blade.php`
- `tests/Feature/Chatblast/ChatbotFbLinkTest.php`
- `qa/stories.md`: S-157 rewritten for the icon.

### Case table (after the amendment)
| Case | Test | Result |
|---|---|---|
| S-157.1 | `test_S_157_1_a_page_id_opens_the_pages_messenger_chat_in_a_new_tab` | pass |
| S-157.2 | `test_S_157_2_no_id_or_a_non_digit_id_gives_no_icon` | pass |
| S-157.3 | `test_S_157_3_the_icon_is_beside_the_name_in_the_edit_and_connections_headers` | pass |
| S-157.4 | `test_S_157_4_the_icon_sits_beside_the_page_name_in_the_grid_with_no_separate_column` | pass |
| S-157.5 (P2) | none (no browser check run) | not checked |

`ChatbotFbLinkTest.php`: `OK (4 tests, 19 assertions)`.

Red run, before the code:
- .1 failed with "Failed asserting that two arrays are identical" (it still got the facebook.com link).
- .3 failed with "Connections: icon right after the name / Failed asserting that 0 is identical to 1".
- .4 failed with "icon right after the page name / Failed asserting that 0 is identical to 1".
- .2 already passed, because the digits-only rule did not change.

### Suites
`php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/PageSettings`: `Tests: 1155, Assertions: 8064, PHPUnit Deprecations: 48, Skipped: 1`. The run has 0 failures and 0 errors. The only skip is the same Redis skip as before (S-088.4, no Redis server on this machine). No new failures vs 816c5baa.

### Story changes
- S-157 was rewritten from the amendment:
  - .1 is now the icon linking to m.me, with its title and label.
  - .2 is now "no icon".
  - .3 is now "the icon beside the name in Edit and Connections".
  - .4 is now "no separate column". This replaces the earlier reworded show/hide case.
  - .5 is now "at 390 px the icon stays beside the name".

### Not done
- No browser check, so the 390 px case was not checked.
- Not pushed and not deployed.
- The `TODO.md` minors still apply: the Node-dependent skip, the source-string checks, and the Connections link refreshing only on reload.

### Commits
- `feat: Facebook icon beside the page name opens the page's Messenger chat, separate column removed (spec 157)`
- this result commit

157: done — Facebook icon beside the page name (grid, Edit, Connections) opens https://m.me/<page_id> in a new tab, digits-only id; P1 pass, no new failures.
