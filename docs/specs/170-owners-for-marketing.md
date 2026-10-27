# Brief 170: marketing claims available pages from the Owners screen

## Why
Spec 164 added /chatbot/owners (ChatbotOwnersController, resources/views/chatbot/owners.blade.php, partials/owners-confirm.blade.php, EnsureCeo): every page connected in Forbeast with its owner, and Take / Move / Ask / Accept / Decline / Cancel, CEO only, confirmed with the CEO's own password and a typed phrase. On 2026-10-27 the first real takes worked, but the first try failed with "The phrase does not match": Chrome autofilled the saved login email into the phrase box (a text input right before a password input is treated as the username) and the user saw no place to type. The owner wants marketing to claim new pages themselves, with a simpler screen.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Roles "Marketing" and "Marketing - OIC" (employeeProfile->role, the same roles that may use a page's Connections) may open /chatbot/owners and use **take** only. move, ask, accept, decline, cancel stay CEO only (server-side, not just hidden buttons). Other roles: 403 and no menu link, as today. | Owner: marketing claims pages. |
| 2 | What marketing sees: only pages that are **Unowned** and pages owned by **this site**. Pages owned by the other site and requests/transfers about them are not shown to marketing (not in the HTML). History (`?history=`) for marketing only for pages they can see. The CEO sees everything as today. | Owner: "makikita lang is yung free at existing". |
| 3 | Layout for everyone: **one page per row** (no two-column grid), grouped: "Available to claim" (unowned) at the **top**, then "On <this site>", then (CEO only) "On <other site>". Each group sorted by name; empty group shows a one-line note ("No pages to claim right now"). The button label for take becomes **Claim**; the typed phrase stays exactly what Forbeast expects (`ForbeastSite::phrase('TAKE', …)`). | Owner: free/available on top, 1 per row. |
| 4 | Fix the autofill: in owners-confirm, Chrome must not fill the phrase box. Put a hidden `autocomplete="username"` input holding the signed-in user's email before the password, place the phrase input after the password input, and give it `autocomplete="off"` plus a name that doesn't look like a login field (keep the server reading it correctly). Open the confirm form so the fields are clearly visible (not cut off) when Claim is clicked. | First real take failed this way. |
| 5 | Same strong confirmation for marketing: their **own** password (`Hash::check`) + the phrase; same 10/min per-user rate limit; the actor sent to Forbeast and recorded ("taken by <name>") is the marketing user. No new tables; no change to Forbeast, the transfer contract, sending, flows or sequences. | Security and lean schema. |

## Cases (S-170)
P1: .1 Marketing and Marketing - OIC see the Owners link and the screen; another non-CEO role → 403, no link; .2 marketing sees only Unowned + this site's pages (an other-site page and its pending request are absent from the HTML); CEO still sees all three groups and all actions; .3 marketing Claim with right password + phrase → the Part S take call with that user as actor (Http faked); wrong password or phrase → refused, nothing sent; .4 marketing POST to move/ask/accept/decline/cancel → 403, nothing sent; .5 page order: Available group first, then this site, then (CEO) the other site; one card per row; .6 the confirm form: a hidden username field precedes the password, the phrase input comes after the password with autocomplete off, and posting still validates the phrase; .7 existing owners tests (164) and Chatblast/Botcake/PageSettings suites pass. P2: .8 390 px.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` plus the owners tests add no new failures vs 81a89f66. Result with deploy notes (expected: no migration, view:clear) and that the same commits apply to Incepxion. End with "170: <done|blocked> — <one line>".

## Out of scope
Forbeast changes; renaming the Forbeast phrase; any change to transfers/moves; deploying.
