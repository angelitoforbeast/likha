# Result 085: it is always clear whether you are in Chatblast or Chatbot

Branch `fix/085-chatbot-chatblast-nav`, cut from `main` at `69d7c40c`. Nothing was pushed.

## Commits

| Commit | What |
|---|---|
| `b531cbfd` | docs: the brief (verbatim) |
| `5ad6564e` | fix: the app switcher and colours, no cross links, app-first tab titles, the main-nav Chatbot link, tests |
| (this commit) | docs: result, screenshots |

## Migration

`database/migrations/2026_10_27_100000_add_chatbot_nav_link.php`. It is idempotent, following `2026_10_18_300100_add_chatblast_nav_link.php`:
- It adds `nav_links` key `chatbot`, label "Chatbot", `/chatbot`, icon `fa-solid fa-robot`, active pattern `chatbot*`, with the same `sort_order` as the Chatblast link.
- It copies the Chatblast link's role visibility, so the same roles see it. If there is no Chatblast link, only the CEO sees it.
- `down()` removes it.

## What changed

| File | Change |
|---|---|
| `resources/views/layouts/chatblast.blade.php` | **Switcher** at the left of the top bar, "Chatblast \| Chatbot" (`data-app-switch`, `data-current`). The current app is filled with its own tile colour; the other is a plain link to `/chatblast` or `/chatbot`. Icons: the paper plane, and a robot (Lucide "bot"). Under 640 px the labels are "Blast" and "Bot". The Chatbot part shows only where `NavAccess` allows `/chatblast`, as the chatbot routes require.<br>**Colours:** Chatblast keeps violet (tile and active tab); Chatbot uses emerald and teal.<br>**Tab title:** always `<App> · <page>`. The app comes from the URL, and an older "Chatblast · " or "Chatbot · " prefix is replaced, so the shared Chats view says "Chatbot · Chats" on `/chatbot/chats`. |
| `app/Support/ChatblastNav.php` | The "Chatbot" tab is removed from Chatblast and the "Chatblast" tab from Chatbot. `mainLinks()` keeps the `/chatbot` link only when the role also has the `/chatblast` link. The Page settings handling is unchanged: one Chatblast link, or the page-settings link renamed when that is all the role has. |
| `resources/views/page_settings/index.blade.php`, `resources/views/chatbot/index.blade.php` | Titles "Page settings" (was "Page Settings") and "Chatbot · Page settings" (was "Pages"). The layout adds the prefix to the other page-settings pages: Botcake customers, PSID coverage, orders. |
| `tests/Feature/Chatblast/ChatblastChatbotNavTest.php` (new) | 6 tests (below). |
| Four older tests | `ChatblastToSendTest`, `ChatbotInboxTest`, `ChatbotSequencePagesTest` and `ChatbotTabsTest` pinned the cross-link tabs. They now expect no cross link, and `ChatbotTabsTest` checks the switcher links instead. |

## Tests

`vendor/bin/phpunit tests/Feature/Chatblast/ChatblastChatbotNavTest.php`: 6 tests, all pass.

| Case | Test | Result |
|---|---|---|
| The switcher shows the current app, the other app is a link to its home, the title starts with the app, there is no cross link, and the app's colour is used. Run on `/chatblast`, `/chatblast/page-settings`, `/chatbot`, `/chatbot/pages` and `/chatbot/chats` (the shared view). | `test_S_085_1…` (5 data sets) | ✔ ×5 pass |
| Migration: it runs twice and adds one link. The main nav has `/chatblast` and `/chatbot` for a role with access, neither for one without, and no Chatbot for a role given only the Chatbot link | `test_S_085_2…` | ✔ pass |

Red run, before the code: all five S-085.1 data sets failed (no switcher), and S-085.2 failed with `File does not exist at path …/2026_10_27_100000_add_chatbot_nav_link.php`.

**Suites:**
- `tests/Feature/Chatblast tests/Feature/PageSettings`: 572 tests, all pass.
- Full suite: 2016 tests, only the two known failures (`ExampleTest`, `NightRun\ImportStartTest`).

## Browser check

Headless Chrome over CDP, against a scratch app (queue `null`; nothing pressed that calls Botcake). The script is outside the repo: `%TEMP%\likha075\check085.mjs`.

| Page | 1366 px | 390 px |
|---|---|---|
| `/chatblast` | "Chatblast · To send". Switcher current = Chatblast with a violet tile; the active tab is violet; tabs have no Chatbot | same, labels "Blast" and "Bot" |
| `/chatblast/page-settings` | "Chatblast · Page settings", violet | same |
| `/chatbot` | "Chatbot · Flow". Current = Chatbot with an emerald tile; the active tab is emerald; tabs have no Chatblast | same |
| `/chatbot/pages` | "Chatbot · Page settings", emerald | same |
| No page-level horizontal scroll | 1366 = 1366 on all four ✔ | 390 = 390 on all four ✔ |
| One click on "Chatbot" from `/chatblast` | goes to `/chatbot`, current = chatbot ✔ | — |
| Console errors | none ✔ | none ✔ |

At 390 px the tab row scrolls inside its own strip, as it did before. The switcher always stays fully visible.

Screenshots in `docs/specs/085-chatbot-chatblast-nav/`: `desktop-` and `phone-` versions of `chatblast`, `chatblast-page-settings`, `chatbot` and `chatbot-pages`.

## Notes

- **Deploy:** run the migration with the code (`php artisan migrate --force`). Without it, the main nav simply has no Chatbot link.
- **A role that can open only page settings** (no `/chatblast`) sees only the Chatblast part of the switcher, so it never gets a link it would get a 404 on.
- No review agent was run for this small task.
