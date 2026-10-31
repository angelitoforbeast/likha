# Spec 208: the page's real Facebook name, with a warning when it differs or the page ID is shared

## Why
On /chatbot/pages a row's name is typed by staff, and nothing checks it against Facebook. On 2026-10-31 (Incepxion, same code) two rows held the same page ID 1085660977960440 ("Alaga Natural Collective" and a duplicate "4RO Alaga Natural Collective"); chats went to the wrong row and the real page's bot answered nothing until the duplicate's ID was cleared. One pair is still open: "Refined Market" and "Refined Optics" both hold 490249500844090 and both pass the Botcake token test, so the token cannot tell which name is right. Staff asked whether the system can find the page's name from its ID and token.
What was checked live (2026-10-31): the Botcake public API returns only {"success": true} for page reads (no name); a Meta app access token cannot read a page name (Graph `GET /{page-id}?fields=name` → 400 "(#100) Object does not exist, cannot be loaded due to missing permission or reviewable feature"); the Forbeast/IncepxionBot gateway probe (`ForbeastPageSetup::probe`) already returns the page's Facebook `name` when the page has a gateway token.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Source of the Facebook name, in order: (a) the page's Forbeast or IncepxionBot gateway probe (the name it already returns); (b) a Meta Ads connection's user token that can read the page (Graph `GET /{page-id}?fields=name`; try the connections the app already stores, stop at the first that answers); (c) none → "Facebook name: unknown" with the existing link to the page on Facebook. Never the Meta app token (it cannot read pages). | Use what already works; no new credentials. |
| 2 | Stored as page settings rows (Page | Provider | Key | Value): provider `facebook`, keys `name` and `name_checked` (time and source: forbeast / incepxionbot / meta-ads / unknown). No new table. | The settings rows are the house pattern for per-page facts. |
| 3 | Fetched on: Botcake or gateway Save and test / Test, "+ Page" when a page ID is given, and the existing nightly connection test. Never on a page load. One Graph call per page per run at most; a failure keeps the last known name and records the attempt. | Keep page loads fast (spec 207). |
| 4 | Warning "Facebook name: <name>" in the Check column and the Connections modal when the stored Facebook name differs from the row's name (compare trimmed, case-insensitive, collapsed spaces; no other fuzzing). | The owner asked for a warning when the name is different. |
| 5 | Warning "Same page ID as <other row(s)>" on every active row whose `botcake.page_id` is held by another active row, in the Check column, the Connections modal, and the top problems banner; each warning says which row's name matches Facebook when that is known. | The owner asked for a warning when it is a duplicate; shows which row to clear (with spec 206's Botcake Clear). |
| 6 | Nothing is renamed, merged or cleared automatically. | Staff decide; the fix is the existing Clear. |

## Stories / cases (S-208)
P1:
- .1 A page with a Forbeast token whose probe returns name "Alaga Natural Collective": after Test, `facebook.name` is stored with source forbeast; the row named "4RO Alaga Natural Collective" with the same ID shows "Facebook name: Alaga Natural Collective".
- .2 A page with no gateway token, and a Meta Ads connection whose token can read it (Http::fake Graph 200 {"name": ...}): the name is stored with source meta-ads.
- .3 No source can read it (Graph 400 #100 for every connection, no gateway token): "Facebook name: unknown" with the Facebook link; the last known name, if any, is kept.
- .4 Names equal except for case and extra spaces: no warning.
- .5 Two active rows with the same Botcake page ID: both show "Same page ID as <other>", the banner counts the pair once, and the row whose name matches the Facebook name is marked as the match.
- .6 Loading /chatbot/pages and /chatbot/data makes no Graph or gateway call (Http::preventStrayRequests).
- .7 Nightly run: one lookup per listed page with an ID, failures recorded, no exception stops the run.
P2: .8 "+ Page" with an ID already used shows the existing "already used by" plus the Facebook name when known.

## Threat model and risk
Trusted: signed-in staff. External: Graph API and the gateways (timeouts, errors). Tokens never reach the page or logs. Risk tier: low-medium (warnings only, no routing change). Run a skeptic reviewer on the token handling and decision 3.

## Done when
P1 pass with tests (failing first); tests/Feature no new failures vs the base; the result shows the rendered warnings for .1 and .5; deploy notes (no migration expected; the nightly command name); the final line.

## Out of scope
Renaming or merging pages, clearing IDs (spec 206's Clear does that), Botcake API changes, new Meta credentials or app review, deploying.

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
