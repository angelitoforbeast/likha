Amendment 069-1 (merge and release prep). Save this text verbatim as `docs/specs/069-chatbot-flow-builder.amendment-1.md`, commit it, and do the following on `feat/069-chatbot-flow-builder`.

1. **Merge.** Merge `main` (now f953c93f: spec 071, the ACTIVE switch is a sliding On/Off toggle) and `feat/072-chat-ad-id-page-name` (158be8e1: the chat block sends `ad_id` and `page_name`; `ad_id` reaches `botcake_psids`; "+ Page" needs page name, Botcake ID and token and sets the page up) into this branch.
   - Resolve conflicts so that all of these survive together:
     - the 071 switch;
     - 072's "+ Page" (which now lives on `/chatbot/pages`), its body, and its ad_id handling;
     - all of 069.
   - Read both specs and results in `docs/specs/071-*` and `docs/specs/072-*`.

2. **The per-page switch is the reply switch.** This is the owner's decision, 2026-10-19: "dito nga sana yung on off na yung per page".
   - A page answers customers when it is On **and** linked to a flow (FLOW dropdown).
   - `CHATBOT_REPLIES_ENABLED` stays only as an emergency stop, and now **defaults to true**. When it is false, behave as now (skipped and logged).
   - Update the screen text so the page list says this plainly. For example, a short note: "On + a flow = the bot answers. On without a flow = chats are only received."
   - Update the tests.

3. **Clean customer messages before they are stored.** This is the owner's decision: a Like should not store a 420-character link. In `/api/botcake/chat`, before saving, when the message is a Facebook CDN URL (host ends with `fbcdn.net`):
   - **The Like sticker** becomes the message `👍`, and no URL is kept. Recognise it by the file names `39178562_1505197616293642_5411344281094848512_n.png` and `851587_369239346556147_162929011_n.png` (the two sizes seen live), and by any URL containing `369239263222822` or `369239346556147` or `369239383222810` or `369239343222814` (Facebook's like sticker ids).
   - **Any other fbcdn URL** becomes the message `[Photo]`, and the URL goes to `payload.image_url`.
   - Chats and the customer view show `[Photo]` with a small "open" link.
   - The flow's `{{last_input}}` sees `👍` / `[Photo]`.

4. **One-off command for old rows: `botcake:chats-normalize`.**
   - `--dry-run` is the default. It prints the counts: like, photo, untouched.
   - `--apply` applies the same rule to existing `botcake_chats` rows. It is idempotent, and it refuses unless `--expect=N` matches the count of rows to change.
   - Live today there are 48 Like rows and 2 photo rows, out of 81. The owner said yes to fixing the old rows.

5. **Tests and checks.**
   - Add tests for 2, 3 and 4.
   - Re-run `tests/Feature/Chatblast` and the full suite (no new failures beyond ExampleTest and NightRun ImportStartTest).
   - Do a short browser check of `/chatbot/pages` (the switch, "+ Page", FLOW dropdown) at desktop and phone width.

6. **Report.** Report the merge commit, the conflicts and how you resolved them, the test lines, and the command's dry-run output on your test data.

Never push. Never touch remote hosts. Never call the real Botcake, OpenAI or Google APIs. No attribution lines.
