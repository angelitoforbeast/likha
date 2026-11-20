# 308 · Chat alerts when Likha is closed (web push)

## Why
The owner, 2026-11-20: "ok, gawin mo yung web push". Spec 300 (MP-2) plays a sound and shows a notification only while a Likha tab is open and visible. Staff are used to Messenger, which alerts them with the app closed and the phone locked. Since 299, Mira's follow-ups also arrive in Likha Chat, so a closed tab means a missed follow-up. This spec adds real push notifications.

## Stories and cases
**WP-1 Turn on alerts on this device (P1).** On `/chat`, the existing "Allow notifications" button (300's MP-2, `#lc-notif-allow`) becomes "Turn on alerts". It asks the browser for permission, registers a service worker, subscribes with the server's public key and saves the subscription.
- One person can have several devices (phone and office PC); each is saved separately.
- The chat page shows the state for this device: "Alerts on for this device" with a "Turn off" button, or the button when off. Never ask on page load.
- "Turn off" unsubscribes and removes this device's subscription on the server.
- A subscription the push service reports as gone (HTTP 404 or 410) is removed on the next send.
- Signing out does not remove subscriptions. A deactivated user gets no pushes.

**WP-2 A push for each new message (P1).** When a message is posted in a conversation (by a person, or by Mira through 299's `AiPost`), every member except the sender gets a push on each of their devices:
- title: the sender ("Mira" for AI messages) and, for a group, the group name; body: the first 80 characters, or "Sent a photo" / "Sent a voice message" / "Sent a file" for attachments;
- clicking it opens `/chat?c=<id>` (focusing an open Likha tab if there is one);
- no push for a muted conversation (300's `muted_until`), for your own messages, or for a removed or edited message;
- no push for the owner reading a conversation he is not a member of (304: he leaves no trace and gets nothing from it);
- pushes from one conversation replace each other on the device (one notification per conversation, showing the newest, with the count when there are several), so three Mira follow-ups in a minute show as one alert, not three;
- when a Likha tab is open and visible on that device, the service worker shows nothing, because 300's in-page alert already does. No double alerts;
- sending is queued and never delays posting the message. A failed send is logged and retried at most twice.

**WP-3 Install Likha on a phone (P1).** Add a web app manifest (name "Likha", the Likha blue, icons at 192 and 512 px made from the existing logo, `start_url` `/chat`, `display` standalone) linked from the main layout.
- Android Chrome: push works from the browser and from the installed app.
- iPhone: Safari allows web push only for a site added to the Home Screen (iOS 16.4+). When the chat page runs on an iPhone outside the installed app, the "Turn on alerts" button is replaced by a short line: "To get alerts on iPhone: Share → Add to Home Screen, then open Likha from the Home Screen".

**WP-4 Unread on the app icon (P2).** Where the browser supports the Badging API, the installed app's icon shows the unread count (300's MP-1 count), set by the page poll and by each push. Where it is not supported, nothing happens.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Standard Web Push with VAPID keys, sent from Likha's own server | No Firebase account or outside service to manage |
| Keys in `.env`: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` (`mailto:` the app's mail-from address) | Secrets live in `.env`, never in the database or the repo |
| An artisan command `chat:push-keys` that creates the two keys and writes them into `.env` only when they are missing, and prints only "created" or "already set" (never the private key) | The architect runs it on the server at deploy without seeing a secret |
| Without keys, push is off: the button is hidden, nothing is sent, nothing fails | The release can deploy before the keys exist |
| Lean schema: no new table unless the shape needs one. Default: a json column on `users` (like `users.nav_pins`) holding the person's subscriptions. If the shape truly needs a table (for example many devices with per-device cleanup under concurrent sends), say why in the result | His rule |
| Library: `minishlink/web-push` (composer), unless the result shows a reason not to | The common, maintained PHP library |
| Message text is shown in the notification, like Messenger | Same as 300's in-page alert |

## Constraints
- **Server PHP has `bcmath` but not `gmp`** (AWS, PHP 8.4). The chosen library must work without `gmp`. Prove it in a test run with `gmp` disabled if Herd has it (`php -n` with only the needed extensions, or equivalent), or show from the library's requirements that `bcmath` is enough. If it needs `gmp`, stop and report: a server package install needs the owner's yes.
- **No SSRF.** The subscription endpoint is a URL the browser sends; the server will POST to it. Accept only `https` endpoints on the known push services (`fcm.googleapis.com`, `*.push.services.mozilla.com`, `web.push.apple.com`, `*.notify.windows.com`, and any other the result justifies), and reject everything else with 422. Test it.
- Every new route is behind `auth`, acts only on the signed-in user's own subscriptions, and is tested (another user cannot remove yours).
- The service worker file is served from the site root with scope `/` and contains no secret. It must not cache pages or break the existing site (no fetch handler, or a pass-through only).
- Keep the 300 in-page alert, sound setting and badge working as they are.
- Changes in the chat views and JS are expected. Keep them to the alert button area, the page's alert code and the manifest link in the layout.
- Other sessions work on other Likha specs (305-307 just merged). Stay inside chat, push, the layout's head and the new files.
- Comments in the language and style of the file around them. Tests go in `tests/Feature/Chat/`. The full suite adds no new failures compared with base 0d70b29f. Known base failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest `/owner/private`, and the Chatblast speed tests (SpeedMemory, SpeedPrewarm, ResponseSpeed, BigBroadcast) that fail under load but pass alone.

## Threat model and risk
- New input: subscription endpoint and keys (allowlisted hosts, size limits, https only).
- New outbound traffic from the server to push services, queued.
- Message text leaves the server encrypted (Web Push encryption) and shows on the lock screen of devices the person turned on.
- New public file: the service worker and the manifest (no secrets).
- Risk tier: high (new outbound requests, new browser surface on phones).

## Allowed commands
- Through Herd PHP (`C:\Users\Forbeast\.config\herd\bin\php.bat`):
  - `composer.bat install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`
  - `composer.bat require minishlink/web-push --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`
  - `php.bat artisan test` (with filters) and `vendor/bin/phpunit`
  - `php.bat artisan route:list`, `php.bat artisan view:cache`, and the new `chat:push-keys` against a local `.env` only
- `npm ci` and `npm run build`.
- `php.bat artisan serve` on 127.0.0.1, with a local seeded sqlite database only (never the real database).
- A headless browser for screenshots of 127.0.0.1 pages only. A real push to a local browser is welcome evidence if it works; it is not required.
- The `impeccable` skill for the alert button states and the iPhone line.
- `git add`, `git commit` and `git push -u origin feat/308-chat-web-push`.

## Done when
1. Tests pass for WP-1 to WP-4, with the push sender faked. They include:
   - saving, listing and removing a subscription, several devices per person, another user cannot remove yours;
   - the endpoint allowlist (422 for http, an unknown host, an IP address, localhost);
   - a message pushes to every other member's devices and not the sender's, muted, removed or edited messages, or the owner reading a chat he is not in;
   - Mira's AI message pushes, with "Mira" as the sender;
   - 404 or 410 from the push service removes the subscription; other failures retry at most twice;
   - no keys → no button, no send, no error;
   - `chat:push-keys` writes keys only when missing and never prints the private key.

   The full suite adds no new failures compared with base 0d70b29f.
2. Evidence in `docs/design/308-chat-web-push/`: screenshots at 390 px of the alert states (off, on, iPhone line) and at 1280 px of the chat page with alerts on; the impeccable critique and fixes.
3. The result lists:
   - migrations, routes, the queued job and the push payload;
   - the `gmp` / `bcmath` finding;
   - **the exact deploy steps** for the architect: composer install on the server if the deploy hook does not, `chat:push-keys`, `config:clear`, the migration, and which worker processes the push queue;
   - how the owner tests it on his phone after the deploy;
   - a merge-danger section: what ships, how to switch push off (remove the keys), how to revert.
4. The branch is pushed. No merge, no deploy.

## Out of scope
- Pushes for anything other than chat messages (checklist cards already arrive through Mira's chat message).
- A push for the owner on conversations he is not a member of.
- Native apps.

## Budget
Large job. At most 2 attempts at the same failing step, then stop and report.
