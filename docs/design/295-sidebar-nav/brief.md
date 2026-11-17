# Design brief: Likha navigation, "the labelled office plan" (spec 295)

Surface: the main layout every `<x-layout>` page uses (`resources/views/components/layout.blade.php`, new `components/sidebar.blade.php` and `components/nav-palette.blade.php`, `components/navlink.blade.php`).
Mode: **Operate.** The visitor is mid-task (encoding orders, checking J&T, reading ad spend) and wants the next page in one glance or three keystrokes. Code-led (Operate surfaces are built, not pictured).
How this brief was made: the spec is precise and pins the look ("Likha's look: Tailwind gray scale, white surfaces, one accent"), and this session runs unattended, so impeccable's discovery interview was answered from the spec and the owner's quoted words instead of a live round. Assumptions are marked (A). `PRODUCT.md` was not written: the spec limits the files this worker may touch (the 199 brief made the same call).
Files in this folder: `before-*.png` (live main, local seeded sqlite, 1280 and 390 px), `after-*.png` (this build), this brief, and the critique and audit notes at the end.

---

## 1. What is there today (inventory, signed in as CEO on a local copy)

| # | Element | Detail | Weak |
|---|---|---|---|
| 1 | Top bar | `bg-gray-800`, fixed, 64 px, broken logo (a remote Facebook PNG) | Dark slab over a light app; logo is someone else's mark and fails offline |
| 2 | Links | ~40 icon-only 40×40 buttons in one horizontally scrolling row, tooltip on hover only | No labels, no groups; at 1280 px the row runs under View as, the name and Logout (`before-ads-report-1280.png`: icons sit on top of "Angelito Forbes" and the Logout button) |
| 3 | View as (CEO) | select in the bar | Fine, but crammed |
| 4 | Profile + Logout | name, role, avatar, red Logout button always on screen | A destructive red button is the loudest thing in the bar |
| 5 | Phone | menu button opens one flat list of ~40 labelled rows, dark | Usable but 40 undifferentiated rows (`before-menu-open-390.png`) |
| 6 | Page header | white band with a 30 px h1 under the bar on most pages | Repeats what a title in the bar could say; costs ~90 px of every page |
| 7 | Badges | checklist badge (red / neutral count, orange team count) on the checklist icon | Keep exactly |

The pages under the bar are white cards on `bg-gray-100`, blue-600 primary buttons, Font Awesome icons, system sans. That is Likha's world; this work extends it, it does not replace it.

## 2. Job, people, outcome

- **Who:** the CEO (sees everything, ~50 links, uses View as to check what staff see), Marketing and Marketing OIC (~25 links), Data Encoders (5-8 links), on office desktops at 1280-1920 and on phones on the floor.
- **Job:** get to a page fast; know where you are; for the CEO, find a rarely used settings page without remembering its icon.
- **Success:** a new staff member can read the sidebar like a floor plan of the office (Orders, Shipping, Marketing, Chat, AI, Money, Settings); a regular never takes their hand off the keyboard (Ctrl+K, two letters, Enter); the daily five live at the top as pins.
- **What must not change:** who sees which link (visibility rows), every page's own content and width, the checklist badge, View as.

## 3. Direction

**"The labelled office plan."** The office's departments, in the order work flows through Likha (Home → Orders in → Shipping out → Marketing → Chat → AI → Money → Settings, More last), each a quiet uppercase label with its links under it. The sidebar is a white plan on the gray floor of the app: no dark slab, no coloured section icons, no cards. One accent (Likha's existing blue-600, already the primary button colour on the pages) marks only three things: the page you are on, focus, and the search key hint's active state. Everything else is gray scale.

What makes it Likha's and not a template:
- **The order of sections is the order of the business day** (orders come in, are shipped, are marketed, chats answered, money counted), not alphabetical and not by "importance". New staff learn the business from the nav.
- **Pins are personal, at most six**, sitting above the plan like the papers on your own desk; each person's six differ.
- **Recent pages lead the palette**, so Ctrl+K then Enter returns to the last page: the palette is a "back to where I was" key as much as a search.
- **The rail keeps the plan:** collapsed to 64 px, sections become thin dividers, not lost, and every icon has a label tooltip.

Refused: the dark admin-template sidebar with coloured icon tiles; an accent stripe down the left of the active link; badges and counts on every section; a kicker or logo lockup that shouts; motion beyond state changes.

## 4. Layout and behaviour

```
≥1024 px
┌──────────────┬──────────────────────────────────────────────────────┐
│ [L] Likha  « │ Page title                     [⌕ Search  Ctrl K] (AF▾)│ 64 px, white, hairline bottom
│              ├──────────────────────────────────────────────────────┤
│ PINNED       │                                                      │
│  ★ Checklist │   page content (unchanged widths; full-width pages   │
│  ★ Ads       │   stay full width beside the sidebar)                │
│ HOME       ▾ │                                                      │
│  Checklist 3 │                                                      │
│ ORDERS     ▾ │                                                      │
│  Likha       │                                                      │
│  Macro …     │                                                      │
│ SHIPPING   ▸ │  (collapsed section: header only)                    │
│ …            │                                                      │
└──────────────┴──────────────────────────────────────────────────────┘
 248 px white, hairline right, own scroll; « collapses to a 64 px rail
```

- **Sidebar (≥ 1024):** fixed, 248 px, white, `border-gray-200` hairline right, scrolls on its own. Top: Likha mark (a blue-600 rounded square with a white "L", then "Likha" in semibold gray-900) and the collapse button. Sections: 11 px uppercase gray-500 semibold label with tracking, a chevron on the right; click (or Enter/Space) collapses, remembered per browser. Links: 36 px rows, 16 px icon in a 20 px box (gray-400), 14 px label (gray-700), rounded-md, hover gray-100 / gray-900; active: blue-50 fill, blue-700 label, blue-600 icon, `aria-current="page"`, font-medium. Badges right-aligned in the row (checklist red/neutral + orange team count, same attributes as today). A pin button (thumbtack, gray-400 → blue-600) appears at the right on row hover and on keyboard focus within the row; pinned rows show a filled pin only inside the Pinned group.
- **Pinned group:** top of the list when the user has pins, same row style, drag to reorder (handle = the row), unpin from the same button. At six, the pin buttons on other rows disable with the tooltip "Six pins is the most; unpin one first".
- **Rail (64 px):** labels, section names and badges' numbers hide; icons centre; section headers become a 1 px divider; tooltips (dark gray-900 chip, right of the icon) on hover and focus; the checklist badge becomes a dot on the icon. The collapse button flips to ».
- **Top bar:** fixed, 64 px (kept: ~10 pages offset themselves with `mt-16` / `top-16`), white, hairline bottom, starts after the sidebar. Left: menu button (< 1024 only), page title (18 px semibold gray-900, truncates). Right: search button (input-like, gray-50, "Search pages", `Ctrl K` key hint; icon-only under 640 px), avatar button opening a menu: name, role, View as (CEO only, with a "Previewing" chip when on), Profile, Log out (plain text row, not a red button).
- **Page heading band:** removed; the title moves into the top bar. Pages keep their own content and widths.
- **Phone / tablet (< 1024):** sidebar is an off-canvas drawer (280 px, max 85vw) over a gray-900/40 scrim, opened by the menu button, closed by the scrim, Esc, the close button or following a link; focus moves into it and back. Pins and sections as on desktop.
- **Palette:** Ctrl+K / Cmd+K or the search button. A centred dialog (max 560 px, 15vh from top; full width minus 16 px on phones), white, rounded-xl, shadow-xl over a gray-900/40 scrim. Input on top ("Go to a page…"), then "Recent" (last 5 visited, this browser) and all visible links grouped by section, each row icon + label + section in gray-500 at the right. Typing filters by label, section and URL words (every word must match). ↑/↓ move, Enter opens, Esc closes, mouse hover moves the selection. Empty result: "No page matches "xyz"." Footer hints: ↑↓ to move, Enter to open, Esc to close.

## 5. States and ranges

- Links per role: 0 (empty state in the sidebar: "No pages are shared with your role yet. Ask the owner to open them in Nav settings."), 5 (Data Encoder), ~50 (CEO). Labels 2-20 chars, truncate with title at 248 px.
- Sections empty for a role are not rendered. More appears only when a link is unknown.
- Pins 0-6. Pin save failure: the pin reverts and a small inline message shows under Pinned ("Could not save pins. Try again.").
- View as on: the avatar shows a blue "Preview" chip; the nav is the chosen role's.
- Storage banner (spec 242): unchanged, under the top bar.

## 6. Constraints

Tailwind Play CDN as loaded today (classes it generates, arbitrary values allowed), Alpine as loaded today, Font Awesome 6.5 icons, system sans. AA contrast: gray-500 on white is 4.8:1 for section labels; gray-700 labels; blue-700 on blue-50 for active (8.6:1). Visible focus: 2 px blue-600 ring with 2 px offset on every control. Every control reachable by keyboard; the drawer and palette trap focus while open and return it. Motion: 150-200 ms ease-out on drawer slide, rail width and palette fade only; `prefers-reduced-motion` turns them off. No new fonts, no new libraries.

---

## 7. Critique and audit (after the build) and what was fixed

Run as impeccable prescribes: two isolated reviewers, A (design review, no detector) and B (detector + rendered checks in headless Chrome against the local server, as CEO and Data Encoder). Detector (`impeccable detect --json` on the four changed templates): **0 findings**. Critique score **28/40** (Good); audit score **14/20** (Good). After-shots in this folder are the fixed state.

| # | Finding (source) | Sev | Fix |
|---|---|---|---|
| 1 | Palette search row squashed to ~20 px when the list is long (A) | P1 | `flex-shrink: 0` on the search row and footer |
| 2 | Recent opened on the current page, so Ctrl+K → Enter did nothing (A) | P1 | Recent leaves out the page you are on |
| 3 | Invisible pin buttons caught taps at the right of every row; no hover on touch (A, B) | P1 | Pin takes no pointer events while hidden; shown at low emphasis on `hover: none`; rows reserve a pin lane so it never covers a badge |
| 4 | A pinned link showed no pinned state in its section (B) | P2 | Pins that are on stay visible (blue) |
| 5 | Current page filled twice when pinned; a closed section hid where you are (A) | P2 | Pinned copy gets a quieter blue text/icon only; a section holding the current page has a blue header |
| 6 | Double 64 px gap on checklist-style pages when the storage banner shows (A) | P2 | Banner pulls the page's own offset back (`-mb-16`) on those pages |
| 7 | Rail: 9 section buttons still tabbable as 12 px hairlines (B) | P2 | Out of tab order and `aria-hidden` while in the rail |
| 8 | Drawer not modal for screen readers (B) | P2 | Page and top bar `inert` while the drawer is open |
| 9 | Pin icon 2.31:1 on its hover chip (B) | P2 | gray-500 (≥ 3:1) |
| 10 | Avatar `role="menu"` holding a form and a select, no arrow keys (A) | P3 | Plain disclosure (`aria-expanded`/`aria-controls`) |
| 11 | Profile icon blank (`fa-regular fa-user`) (A) | P3 | `fa-solid fa-user` |
| 12 | Palette and menu fades not covered by reduced motion (A) | P3 | Added to the reduced-motion rule |
| 13 | Selected palette row's section text 4.44:1 (B) | P3 | gray-600 |
| 14 | 36 px buttons under the 44 px touch guideline (B) | P3 | 44 px buttons and 40 px rows on `pointer: coarse` |
| 15 | Six-pin limit only in a `title` (A) | P3 | Visible note under Pinned at six |
| 16 | Search not ranked (A) | P3 | Label-prefix matches first, then label, then section/url |
| 17 | Palette at 12vh, brief says 15vh (A) | P3 | 15vh |

Not fixed, with reason:
- "Supply" shows no icon: its stored class `fa-boxes-stacking` does not exist in Font Awesome 6.5 (should be `fa-boxes-stacked`). It is data in production `nav_links`, blank before this work too; the owner can change it, or a later spec can (this one does not rename or edit links).
- Link labels that differ from page titles ("Ads" → "Import Ads Manager Reports", "Waybill" → "UPDATE FROM JNT") and cryptic labels ("MES SEG", "IP", "Checker 1"): renaming links is out of scope; the nav now makes them visible, which helps the owner choose later.
- Near-duplicate links (Waybill / Waybill V2, Tally Sticker / Tally Sticker 2): kept by the spec's decision.
- Each pin button is its own tab stop (B, P3): kept; it is what makes pinning reachable by keyboard.
- Dashboard shows two h1s (bar + the page's own "Welcome"): the old layout had the same; only own-header pages are listed.
- Not verified headless: pin drag on touch, real screen-reader output, the pin-save failure path, mouse-hover tooltips (focus tooltips verified).
