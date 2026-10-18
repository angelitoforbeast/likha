# Brief 056: Chatblast as its own app inside likhaaitech.com

## Why

The owner, 2026-10-18: "ayusin mo naman navlink nung chatblast, gawin mo chatblast/page-settings na, tapos parang hiwalay na web, kumbaga iba itsura, tapos siya lang". When asked to confirm: "Oo, tama. Gawin mo na ang 056: Chatblast bilang sariling app na may sariling nav (Dashboard, Messages, Sequences, Page settings), /page-settings lilipat sa /chatblast/page-settings."

Chatblast is modelled on Botcake. It should feel like its own tool, not one more page inside the Likha admin.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| **One Chatblast layout** (a new Blade layout, e.g. `layouts/chatblast.blade.php`) used by all four pages. It has its own look: a left sidebar or top bar with a "Chatblast" brand mark, and the four nav items **Dashboard** (`/chatblast`), **Messages** (`/chatblast/campaigns`), **Sequences** (`/chatblast/sequence`) and **Page settings** (`/chatblast/page-settings`), with the active item highlighted. The main Likha navigation is NOT shown inside it. A small "← Likha" link goes back to the main site, and the signed-in user and logout stay available. | Owner: "parang hiwalay na web, iba itsura, siya lang" |
| **Look:** clean, app-like, consistent across the four pages: one header style, one spacing and card style, one button style. Keep each page's content and behaviour exactly as it is now. This is a layout and chrome change, not a redesign of the page bodies. The /page-settings grid keeps spec 054's layout and its no-horizontal-scroll guarantee at 1366 and 1280 px **inside the new layout** (sidebar width included). Re-check it in a browser. | Owner's no-horizontal-scroll rule |
| **Move /page-settings to /chatblast/page-settings.** The page, all its endpoints (save, history, test token, Test lahat, Sync, Sync lahat with the run panel, the poll) and its JS work at the new path. The old `/page-settings` URL (GET) does a **301 redirect** to `/chatblast/page-settings`, keeping the query string. Old POST/JSON endpoints either keep working at their old paths too or move together with every caller. No caller may be left pointing at a dead route: grep the views, JS, tests and any route() names. | Bookmarks and staff links keep working |
| **Access is unchanged.** Each page keeps its current check: chatblast pages use NavAccess '/chatblast', and page settings uses what /page-settings uses today, including Marketing and Marketing OIC access through nav-settings. The Chatblast nav shows only the items the user may open. /owner/nav-settings must still control page-settings access. If it keys on the path '/page-settings', make it keep working under the new path. A user who could open /page-settings before can open /chatblast/page-settings now, and nobody gains access. | No access change |
| **Main Likha nav:** the separate Chatblast and Page settings entries become **one "Chatblast" link** to `/chatblast`. It shows for anyone with access to either part. | One entry point |
| **All UI text is English.** No migrations. | Owner's standing rule |

## Tests

- Each page renders with the Chatblast layout and its nav, without the main Likha nav.
- The /page-settings 301 redirect keeps the query.
- Every page-settings endpoint works at the new path: save, test token, Sync, history.
- Access: a role that had page-settings access still has it, a role without it gets the same refusal as before, and the nav hides items the user can't open.
- The main nav shows a single Chatblast link.
- Browser check: no horizontal scroll on /chatblast/page-settings at 1366 and 1280 px. Click through the four nav items.
- The whole suite passes except the known `ExampleTest` and `ImportStartTest`.

## Out of scope

- Changing what each page does.
- New features.
- Incepxion-specific branding (same code on both sites).
