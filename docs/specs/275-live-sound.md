# 275: The live chat page plays a sound when a customer writes

Weight: bounded. Risk tier: low (browser-only). Size: small. Budget: 2 attempts per failing step.

## Why
Staff watch https://likhaaitech.com/chatbot/live (ChatbotLiveController and its view) for incoming chats. The owner wants a sound so a new customer message is noticed without staring at the screen.

## Stories
S1 (P1) When the live page receives a NEW inbound customer message (not bot, not staff/agent, not echoes; only messages that arrived after the page loaded), it plays a short notification sound. Several messages arriving together play the sound once (at most one sound per 3 seconds).
S2 (P1) A speaker toggle in the page header: Sound on / Sound off. Default on. The choice is remembered in this browser (localStorage, wrapped in try/catch; works without it).
S3 (P1) Browsers block audio until the user interacts: if the first play is blocked, show a small "Click to enable sound" chip; one click enables it and plays a short test beep.
S4 (P2) While sound is on and a new message arrives and the tab is not visible, also prefix the tab title with the unread count, e.g. "(3) Live chats", cleared when the tab is visible again.
- The sound is a small file served by the app (e.g. public/sounds/new-message.mp3 or a WebAudio-generated beep — no external CDN), volume moderate.
- Use whatever the page already uses to learn about new messages (polling/streaming); do not add a new server endpoint unless the page has no way to tell new inbound messages apart — then explain in the result.

## Constraints
- Smallest change in the live page's view/JS; no change to how messages are fetched unless needed; English UI text.
- Tests: a feature test that the page renders the toggle and the sound asset reference; if the page's JS has a testable helper (the repo has JS unit tests? check), test the "only new inbound, once per 3 s" rule; otherwise describe a manual check in the result.
- Local test `.env`: comment out CHATBOT_QUEUE_CONNECTION and CHATBLAST_QUEUE_CONNECTION.
- Commit: `feat(chatbot): the live page plays a sound when a customer writes (275)`; no Co-Authored-By, Claude-Session or "Generated with" lines, no person names. Save this spec as `docs/specs/275-live-sound.md` and `docs/specs/275-live-sound.result.md`.

## Allowed
composer install, php artisan test (new tests + full suite once; known pre-existing failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest), vendor/bin/pint on new files, git add/commit, `git push -u origin feat/275-live-sound`. Do NOT merge, push to main or deploy.

## Done when
- Tests pass; full suite adds no new failures beyond the three known ones (paste the summary line).
- The result says how new inbound messages are detected, where the sound file lives, and how to check it by hand.
