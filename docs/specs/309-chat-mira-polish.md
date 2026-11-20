# 309 · Chat polish: Mira's subtitle, Mira's colour, Chat stays on top after Reset

## Why
The owner, 2026-11-20, "Oo, isunod mo", for three small things left open after 299 and 300 went live:
1. In a staff member's conversation with Mira, the header subtitle reads "Direct chat".
2. Mira's avatar and AI mark are amber in Likha Chat but violet on the checklist card, the follow-ups page and the 3D office.
3. 299 moved Chat to the top of the sidebar with a migration, but `NavLink::defaultData()` and `NavSections` still place it in the Chat section, so Reset in `/owner/nav-settings` puts it back there (299 result, ruling 13).

## Stories and cases
**PM-1 Mira's subtitle (P1).** In a conversation with Mira (299's `direct_key 'ai:mira:<user_id>'`), the thread header subtitle reads "AI marketing head" for the staff member, instead of "Direct chat". The conversation list row is unchanged. For the owner reading that conversation, the subtitle is unchanged (it shows the staff member, as today). Every other direct chat keeps today's subtitle (active text, else the role, else "Direct chat").
- The subtitle comes from the server's conversation data (for example an `ai` field the presenter already has, or a new small field), not from matching the title text in JS.

**PM-2 One colour for AI (P1).** In Likha Chat, the AI avatar (`.lc-av.ai`) and the AI mark (`.lc-ai`) use the violet the checklist card and follow-ups page use (Tailwind violet: a violet-50/100 background with violet-800 text, or the solid violet-600 with white text of the card's icon; pick the one that reads best at avatar size, contrast at least 4.5:1). The conversation list's avatar for Mira's conversation shows the same AI avatar.
- Only the AI colours change. Human avatar tones, the blue accent and everything else stay.
- Update the 296/300 design brief note in this spec's result, not the old brief.

**PM-3 Chat stays first after Reset (P1).** Change the defaults so the factory order matches what 299 did: the Chat link is the first link of the Home section, above Checklist, in `NavLink::defaultData()` and `NavSections` (whichever decides the section and order on Reset and on a fresh database).
- After Reset, Chat is first in Home for every role that sees it; no other link changes section, order or visibility compared with Reset today.
- A fresh database (migrations plus seed) gives the same result.
- The 299 migration stays as it is.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Subtitle text "AI marketing head" | The name the follow-ups page uses for Mira ("Mira, the AI marketing head") |
| Violet for AI everywhere | The checklist, the follow-ups page and the 3D office's LDT AI building already use violet; chat is the odd one out |
| No migration | PM-3 changes only the defaults used by Reset and the seed; production already has Chat on top |

## Constraints
- This branch starts from `feat/308-chat-web-push` (spec 308, web push), because both change `resources/views/chat/index.blade.php`. Do not change 308's alert code; keep your changes to the subtitle function, the AI colour rules and the nav defaults.
- `NavLink::defaultData()` and `NavSections` are spec 295 files; change only the Chat entry's section and position. Test that Reset leaves every other link as Reset does today (compare the full list before and after your change, minus Chat).
- Tests go in `tests/Feature/Chat/` and `tests/Feature/` next to the existing nav tests. Comments in the language and style of the file around them.
- The full suite adds no new failures compared with the 308 branch tip you start from. Known base failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest `/owner/private`, and the Chatblast speed tests (SpeedMemory, SpeedPrewarm, ResponseSpeed, BigBroadcast) that fail under load but pass alone.

## Threat model and risk
- No new input, route or data. Risk tier: low.

## Allowed commands
- Through Herd PHP (`C:\Users\Forbeast\.config\herd\bin\php.bat`):
  - `composer.bat install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`
  - `php.bat artisan test` (with filters) and `vendor/bin/phpunit`
  - `php.bat artisan route:list` and `php.bat artisan view:cache`
- `npm ci` and `npm run build`.
- `php.bat artisan serve` on 127.0.0.1, with a local seeded sqlite database only (never the real database).
- A headless browser for screenshots of 127.0.0.1 pages only.
- `git add`, `git commit` and `git push -u origin feat/309-chat-mira-polish`.

## Done when
1. Tests pass for PM-1 (staff see "AI marketing head"; the owner and other direct chats unchanged), PM-2 (the AI avatar and mark use the violet values; human tones unchanged) and PM-3 (Reset and a fresh database put Chat first in Home; every other link as before). The full suite adds no new failures compared with the starting tip.
2. Screenshots in `docs/design/309-chat-mira-polish/`: a staff member's Mira conversation at 390 px (subtitle and violet avatar), the chat list at 1280 px with Mira's row, and the sidebar after Reset.
3. The result lists what changed and a merge-danger section (what ships, how to revert).
4. The branch is pushed. No merge, no deploy.

## Out of scope
- Mira answering replies.
- Any other nav change (the Supply icon is a separate item).

## Budget
Small job. At most 2 attempts at the same failing step, then stop and report.
