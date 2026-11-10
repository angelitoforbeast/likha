=== SPEC 259: several photos sent at once count as one [Photo] ===

## Why
When a customer sends one photo, the incoming Botcake chat message is a single Facebook CDN link, and app/Services/Chatbot/ChatMessageCleaner::clean() turns it into "[Photo]" (link kept in payload.image_url) or "👍" for the Like sticker. When the customer sends 2 or 3 photos at once, Botcake sends them as one message "link, link" (or "link, link, link"). clean() only accepts a message that is a single URL with no whitespace, so these messages are stored and passed to the flow with the raw links. The links contain long digit runs (e.g. 893577555_1145189771193247_9184405102603098528_n.jpg), so a main-flow condition like "last_input contains 9…/639" matches and the order is uploaded with no phone number. Data over 7 days: 185 such messages on Likha and 335 on Incepxion; every one is 2–3 fbcdn image links (jpg/png, path /v/t1.15752-9/), no text with them, no video; none contained a real phone number. Single photos are already cleaned correctly (0 left uncleaned).

## Stories / cases (done-when)
1. A message made only of 2 or more fbcdn links separated by commas and/or whitespace becomes message "[Photo]"; image_url = the first non-Like link; a new payload key image_urls = all non-Like links (each cut to 2000 chars, at most 10).
2. If every link in such a message is a Like sticker (existing LIKE_FILES / LIKE_IDS marks), the message becomes "👍" with no image_url.
3. A mix of Like links and photo links → "[Photo]", image_urls holds only the photo links.
4. A single fbcdn link keeps today's behaviour exactly (same result array as now).
5. Unchanged (returned as is, kind null): any message with text besides the links (e.g. "magkano?, https://…fbcdn…"), a list where any link is not fbcdn (host fbcdn.net or *.fbcdn.net, http/https), an empty string, plain text, a phone number.
6. BotcakeChatController (around line 156) stores payload image_urls when the cleaner returns more than one link; attachment stays {type: image, url: first}. Everything that compares the message to ChatMessageCleaner::PHOTO (ChatAttachments, ForbeastWatch, Inbox) keeps working because the message is exactly "[Photo]". No change to those files.
7. The botcake:chats-normalize command (NormalizeBotcakeChats) uses the same clean() and therefore also handles these; keep its dry-run default; do NOT run it against any real database.
8. Replay check: write a throwaway script (scratch, not committed) that reads the 5th tab-separated column (message) of every line of
   C:\Users\Forbeast\AppData\Local\Temp\claude\2e760ef270dbe960\07319e29-2afc-45d1-9b8d-9083b1ed643e\scratchpad\links-likha.tsv and links-incep.tsv (520 real messages, data only) through the new clean() and report: how many became "[Photo]", "👍", unchanged. Expected: all 520 become "[Photo]" or "👍"; report any that do not with the reason.

Keep the clean() return shape backward compatible: add 'image_urls' => list|null to the array; existing keys unchanged.

## Constraints
- Smallest change: ChatMessageCleaner.php, BotcakeChatController.php, tests. No migrations, no other behaviour.
- Unit tests in tests/ for every case above (extend the existing ChatMessageCleaner tests if present: search tests/ for ChatMessageCleaner).
- Run the tests with the bootstrap shim C:\Users\Forbeast\AppData\Local\Temp\claude\2e760ef270dbe960\07319e29-2afc-45d1-9b8d-9083b1ed643e\scratchpad\wt247-bootstrap.php (look at how it is meant to be used inside the file). Never pass -d memory_limit=-1. Run the cleaner tests, then the whole suite once. Known failing on the base already: ExampleTest and ItemTableFitScriptTest; Astra timing and BigBroadcast tests are flaky. Report "adds no new failures" with numbers.
- Code comments in the project's existing style (Tagalog comments are used in these files; match them).
- Commit messages: Conventional Commits, no names of people, no "Co-Authored-By", no "Claude-Session", no "Generated with" lines. Spec number in the branch name/commit is fine.
- Do NOT deploy, do NOT push to any server, do NOT merge. Push only the branch to origin: `git push -u origin feat/259-multi-image-photo`.
- Budget: medium job; at most 2 attempts at any failing step, then stop and report.

## Out of scope
Incepxion port (done later by cherry-pick), phone-number rule, AND conditions, Inbox showing several images, video detection.

## Report back
Write docs/specs/259-multi-image-photo.result.md (what changed, tests with numbers, the replay counts, anything unexpected) and commit it. Then reply with: final commit sha, whether it is a fast-forward from d50d030c, files changed, test numbers, replay counts.
