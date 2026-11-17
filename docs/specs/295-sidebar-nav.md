# 295 — Navigation: a labelled sidebar in eight sections, with search and pins

## Why
The owner: "ampanget na navlink niyan, suggest ka navlink", and "ang dami na laman neto". Today the CEO sees about 40 icon-only 40×40 buttons in one horizontally scrolling row in a `bg-gray-800` top bar (`resources/views/components/layout.blade.php`, `components/navlink.blade.php`), with nothing grouped or labelled; links come from `nav_links` (no group column) and `nav_link_role_visibility`. His plan ("LDT Virtual Office", Likha overhaul) replaces it with a left sidebar in eight labelled sections, Ctrl+K search and pins. "gomo na lahat", "deploy na agad".

## Stories and cases
**NV-1 Sections (P1).** `nav_links` gains a nullable `section` column. A migration assigns every existing link by `route_url` (keys may differ in production; URLs are what the inventory lists):
- **Home:** /checklist
- **Orders:** /likha_order_import, /macro/gsheet/import, /encoder/summary, /encoder/checker_1, /encoder/pending-rate, /encoder/timetable, /data_encoder/mes-segregator, /jnt/address
- **Shipping:** /jnt_upload, /jnt_upload_v2, /encoded_vs_upload, /jnt/stickers, /jnt/checker, /jnt/hold, /jnt_rts, /jnt/supply, /pancake/retrieve2
- **Marketing:** /ads_manager/report, /ads_manager/cpp, /ads_manager/daily-output, /ads_manager/payment/upload, /ads_manager/pancake-subscription-checker, /conversation/tracker, /owner/private, /playbook, /prompt-generator
- **Chat:** /chatbot, /chatblast, /page-settings, /chatbot/botcake-accounts, /pancake/page-id-mapping, /chat
- **AI:** /checklist/ai-followups, /astra, /boardroom
- **Money:** /finance, /finance/supply
- **Settings:** /assign-roles, /owner/users, /owner/nav-settings, /owner/column-settings, /allowed-ips, /jnt/config, /jnt/fee-settings, /jnt/supply/excluded-pages, /queue-manager, /system-monitor/storage, /owner/access-report
- Any link the migration does not know (rows added by hand in production) goes to a **More** section at the end, so nothing disappears. Section order and section icons live in code, not in the table.
- Visibility does not change: a person sees exactly the links they see today, now grouped. A section with no visible link is not shown.
- Nav settings (`/owner/nav-settings`) gets a Section dropdown per link; "Reset" (`NavLink::defaultData()`) includes every link with its section, including the 10 links added after May 2026 that it misses today.

**NV-2 The sidebar (P1).** Desktop (≥ 1024 px): a fixed left sidebar, 248 px, white or very light, with Likha's mark at the top, sections as small uppercase headers that collapse (state remembered per browser), each link an icon plus its label, the active page highlighted, existing badges kept (the checklist badge in the layout). A collapse button turns it into a 64 px icon rail with tooltips. The top bar becomes slim: page title, the search button, and the avatar menu (name, role, View as for the CEO, Logout). The ~25 pages the layout renders full width keep their full width beside the sidebar. Phones and tablets: the sidebar is a drawer from a menu button.
- H: CEO sees all eight sections; a Data Encoder sees only the sections that hold their links.
- E: View as (CEO) still changes the nav only.
- E: every page using `<x-layout>` (174 views) renders: test a representative set renders with the new layout, and screenshot five of them (checklist, ads report, J&T checker, finance, nav settings) at 1280 px and 390 px before and after.

**NV-3 Search (P1).** Ctrl+K (and the search button) opens a command palette listing every link the user can see, searchable by label, section and URL words, with the last 5 pages visited (per browser) on top. Enter opens; Esc closes; arrows move. Works on phones from the search button.

**NV-4 Pins (P2).** A pin on hover pins a link to a **Pinned** group at the top of the sidebar, at most 6, reorderable by drag. Stored per user in a nullable json column `nav_pins` on `users` (ids of nav links); a pin to a link the user can no longer see is dropped silently.

**NV-5 Design (P1).** Design first with the `impeccable` skill: brief and renders in `docs/design/295-sidebar-nav/` like `docs/design/199-live/`. Likha's look (Tailwind gray scale, white surfaces, one accent), English labels, visible focus, keyboard reachable, contrast AA. It must look finished and calm, not like an admin template.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Eight sections as listed | The owner's plan, every current link placed |
| Visibility rows unchanged | Access is spec 294 and later; this spec only groups |
| Duplicates (Waybill and Waybill V2, Tally Sticker and Tally Sticker 2) stay | The owner chooses later from usage |
| Unknown links go to More | Production has rows no migration made |

## Constraints
- Only the main layout (`components/layout.blade.php`, `components/navlink.blade.php`, a new sidebar and palette component). The Chatbot/Chatblast layout (`layouts/chatblast.blade.php`) is not touched (a later spec).
- Keep the current Tailwind loading as it is (moving off the Play CDN is a later spec); write classes the CDN build supports.
- Schema changes guarded with `Schema::hasColumn`. Comments in the language and style of the file around them.
- Tests in `tests/Feature/Nav/`; the full suite adds no new failures compared with base 8164024f (list the base failures).

## Threat model and risk
No new data exposure: the sidebar shows the same links as today. New write surface: pins (own user only, ids validated against links the user can see) and the section field in nav settings (CEO only). Risk tier: medium (the layout of 174 pages).

## Allowed commands
`composer.bat install`, `php.bat artisan test` (with filters) and `vendor/bin/phpunit` via Herd PHP (`C:\Users\Forbeast\.config\herd\bin\php.bat`); `php.bat artisan route:list`; `php.bat artisan view:cache`; `npm ci` and `npm run build`; `php.bat artisan serve` on 127.0.0.1 with a local seeded sqlite database (never the real database); a headless browser for screenshots of 127.0.0.1 pages and local files only; the `impeccable` skill; git add, git commit, git push -u origin feat/295-sidebar-nav.

## Done when
1. Tests pass for: the section migration (every listed URL lands in its section; an unknown link lands in More; nothing visible before is hidden after), the sidebar per role (CEO all, Data Encoder only theirs, a role with no links sees an empty state, not a broken page), View as, the palette's list per role, pins (limit 6, own user, dropped when the link is hidden), nav settings' section field (CEO only). The full suite adds no new failures compared with base 8164024f.
2. Evidence in `docs/design/295-sidebar-nav/`: the brief, the five pages before and after at 1280 and 390 px, the palette open, the rail collapsed, the phone drawer open, and the impeccable critique and audit findings with what was fixed.
3. The result lists the migration, the new components, and a merge-danger section (what ships, the migration, how to revert to the old nav).
4. The branch is pushed. No merge, no deploy.

## Out of scope
Changing access or roles; the "Today" page; Chatbot/Chatblast's layout; moving off the Tailwind Play CDN; renaming or merging links.

## Budget
Medium-to-large job. At most 2 attempts at the same failing step, then stop and report.
