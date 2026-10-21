Small task: spec 085, about 30–45 minutes. Start it after 084 is committed.

**Branch.** In this worktree, run `git checkout -b fix/085-chatbot-chatblast-nav main`. Use the newest `main` available.

**Rules**
- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/085-chatbot-chatblast-nav.md` and the result as `docs/specs/085-chatbot-chatblast-nav.result.md`.
- Do a headless browser check at 1366 and 390 px of `/chatblast`, `/chatblast/page-settings`, `/chatbot` and `/chatbot/pages`, with screenshots in `docs/specs/085-chatbot-chatblast-nav/`.
- Budget: 2 attempts per failing step, then stop and report.

# Brief 085: it is always clear whether you are in Chatblast or Chatbot

## Why

`/chatblast/*` and `/chatbot/*` share `layouts/chatblast.blade.php`:
- the same violet paper-plane logo and the same top bar;
- only the word next to the logo changes;
- both nav lists have "Page settings", "Chats" and "Sequences";
- each links to the other ("Chatbot" sits among Chatblast's tabs, and "Chatblast" among Chatbot's).

The owner, 2026-10-20: "d ko kasi alam kung nasa chatblast ako, or nasa /chatbot ako". The two apps are now almost separate and stay separate for now; they are not to be merged.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **App switcher.** At the left of the top bar, a two-part switch reads **Chatblast \| Chatbot**. The current app is filled, the other is a plain link to its home (`/chatblast` or `/chatbot`). Each part has its own icon: the paper plane for Chatblast, a robot or message-bot icon for Chatbot. It is visible at every width, and at 390 px it may show the icons with short labels. | You can always see where you are and switch in one click. |
| **Remove the cross links.** Remove "Chatbot" from Chatblast's tabs and "Chatblast" from Chatbot's tabs. The switcher replaces them. Access rules (`NavAccess`, `ChatblastNav`) stay as they are. | No duplicate way across. |
| **Each app has its own colour.** Chatblast keeps violet. Chatbot uses teal or emerald for its logo tile, its active tab and the filled part of the switcher. Only the shell changes, not the page content. | Seen at a glance. |
| **The browser tab title starts with the app**, for example "Chatbot · Page settings" and "Chatblast · Page settings". Fix any page whose title doesn't. | Tabs and history are clear too. |
| **Main Likha nav.** Add a separate "Chatbot" link to `nav_links` (key `chatbot`, `/chatbot`, active pattern `chatbot*`), visible to the roles that see Chatblast today. Use an idempotent migration that follows `2026_10_18_300100_add_chatblast_nav_link.php`. The link is visible only where `NavAccess` allows `/chatblast`, as the chatbot routes require today. Check `ChatblastNav::mainLinks` still handles Page settings. | The two apps are separate. |
| Tests: the switcher shows the right current app on a chatblast and a chatbot URL; the cross links are gone; the main nav has both links for a role that has access, and neither for one that doesn't. | — |

## Done when

- `php artisan test tests/Feature/Chatblast` passes.
- The browser check shows the switcher and colours on all four pages, at both widths, with no page-level horizontal scroll.
- Report the commits and the migration.

## Out of scope

- Merging or moving pages between the apps.
- Renaming the tabs.
- `/chatblast/chats` vs `/chatbot/chats`.
