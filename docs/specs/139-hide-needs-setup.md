# Brief 139: the "Needs setup" box on /chatblast/page-settings can be hidden

## Why
On /chatblast/page-settings the red "Needs setup (N pages)" box (pages whose chatblast messages of the last 3 days were not sent because of the page's setup, each with a Fix link) can list dozens of pages (56 on incepxion.pro) and pushes the settings table far down. The owner: "pwede bang hideable to".

## Decisions
| Decision | Why |
|---|---|
| The box header "Needs setup (N pages)" becomes a toggle (button, keyboard accessible, `aria-expanded`), with a chevron. Clicking it shows or hides the list; the count stays visible when hidden. | Hideable, but the count is never lost. |
| Default: **hidden** (collapsed). The viewer's choice is remembered in the browser (`localStorage`, wrapped in try/catch; works without it). | The list is long; open it when needed. |
| When the count goes up compared with the last time the viewer saw it (store the last seen count with the choice), show the box expanded again once. | A new problem is not hidden silently. |
| Nothing else on the page changes; no server data change. | Small UI change. |

## Done when
- A feature test or Blade render test shows the toggle markup (button, `aria-expanded`, the count in the header) and that the list is still rendered (hidden by default via the page's JS/CSS, not removed).
- `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast` adds no new failures.
- Result: files and lines changed, how the default and the "count went up" rule work, commits. End with "139: <done|blocked> — <one line>".

## Out of scope
Any other change on the page; deploying.
