# Brief 138: the Mira RTS cell on /owner/private shows one tooltip, not two

## Why
Hovering the Mira RTS value on the owner's Daily Summary (/owner/private) shows two popups with the same text: the page's own hover tooltip (built from `analystTip(row)`, pushed around line 2578 of `resources/views/owner/private.blade.php`) and the browser's native tooltip from `:title="analystTip(row)"` on the cell's span (around line 1076). The owner: "isa lang dapat".

## Decisions
| Decision | Why |
|---|---|
| Keep the page's own tooltip; remove the native `:title` from the Mira RTS span. Keep `cursor:help`. | One popup, the page's styled one. |
| Check the other cells in the same table for the same double (a `title`/`:title` on an element that the page's own tooltip also covers). Fix those the same way only if they show the same text twice; list what you found and changed in the result. | Same bug elsewhere. |

## Done when
- The Mira RTS cell has no native title; the page's tooltip still shows the same text (a test that renders the page or checks the Blade output, or the existing owner-private tests plus a grep evidence line in the result).
- `php vendor\phpunit\phpunit\phpunit tests/Feature/OwnerPrivate` adds no new failures.
- Result: the lines changed, any other cells checked/changed, commits. End with "138: <done|blocked> — <one line>".

## Out of scope
Any other change on the page; deploying.
