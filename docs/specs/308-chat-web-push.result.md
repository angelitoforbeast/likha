# 308 result: Chat alerts when Likha is closed (web push)

Base: live main 0d70b29f. Branch `feat/308-chat-web-push`.

## Plan

Base 0d70b29f: `composer install` (with `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`), full suite once with `vendor/bin/phpunit` under Herd PHP (no `.env`; phpunit.xml uses sqlite `:memory:`), with no edits to code while it runs (300's base run was spoiled by edits during the run). Base failures listed under Evidence.

Read first: specs 296, 299, 300, 304 and their results; `ChatController`, `ChatMessengerController`, `AiPost`, `ChatMessenger` (`preview`, `alerts`, `notMuted`), `ChatPresenter` (`sender`, `unreadTotal`), `ChatPeople` (active rule), the chat models, `config/chat.php`, `config/queue.php`, `deploy/supervisor/*`, `components/chat-unread.blade.php`, the Alerts popover in `chat/index.blade.php`, the layout head, `ChatTestCase`.

**Data (one migration, additive).** `2026_12_01_308000_add_chat_push_to_users`: `users.chat_push` json null, like `users.nav_pins`. A list of this person's devices: `{endpoint, p256dh, auth, added_at, ua}` (at most 10 per person; the oldest drops). No table, because:
- writes are rare (turn on, turn off, a gone endpoint), and every write runs in a transaction with the user row locked (`lockForUpdate`), so a 410 cleanup and a new device saved at the same time do not lose each other;
- the same browser saved by a second person (shared office PC) moves to that person: saving an endpoint removes it from any other user's list (staff table is small; done in PHP in the same transaction).

**Library.** `minishlink/web-push` via `composer require`. Before use I check its `composer.json` and its JWT/maths dependencies for `ext-gmp`, then run the push tests (real encryption + VAPID headers, no network) under `php -n` with only the needed extensions and no `gmp`.

**Server.**
- `config/chat.php` (appended): `push` → `public_key`, `private_key`, `subject` (`VAPID_*`, subject defaults to `mailto:` + `MAIL_FROM_ADDRESS`), the allowed push hosts, `ttl`, `max_devices`, `retries` (2).
- `App\Services\Chat\ChatPush`: `enabled()` (both keys set), `validEndpoint()` (https, port 443 or none, no user info, host on the allowlist, not an IP or localhost, ≤ 2048 chars; keys base64url of 65 / 16 bytes), `save()`, `remove()`, `devices()`, `forget()` (gone endpoint), `queue(ChatMessage)` and `payloadFor()`.
- `App\Services\Chat\PushSender` (bound in the container): wraps `Minishlink\WebPush`; returns per endpoint `ok`, `gone` (404/410) or `failed`. Tests swap in a fake.
- `App\Jobs\SendChatPush` (queued on the default connection and queue, `afterCommit`): loads the message (skip if removed, edited, system, or gone), recipients = active members of the conversation except the sender, not muted, not deactivated (profile status inactive/resigned/terminated), with devices. One payload per person. `gone` → `forget`. `failed` → one warning log line (host + status, never keys) and a new `SendChatPush` for just those devices, attempt + 1, at most 2 retries, delayed.
- Hook: `ChatMessage::booted()` `created` → `ChatPush::queue($m)` (no-op without keys or for system lines). That covers `ChatController::send`, voice and `AiPost::post` without touching them. Edits and removals are updates, so they never push.
- Routes (inside the `/chat` block, `auth`, own subscriptions only, throttled): `GET /chat/push` (this person's devices, as a short hash of the endpoint, the service and the date; never the endpoint or keys), `POST /chat/push` (save this device, 422 off the allowlist), `DELETE /chat/push` (remove this device by endpoint, only from your own list). 404 for all three when push is off.
- `chat:push-keys`: reads the `.env` file itself; when both keys are missing it writes `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` (and `VAPID_SUBJECT` if missing) and prints `created`; when both are present prints `already set`; one present and one missing → refuses with a message and changes nothing. Never prints a key.

**Payload** (JSON, encrypted by Web Push): `{c, title, body, url: "/chat?c=<id>", tag: "likha-chat-<id>", unread, id}`; title "Ana Cruz" (direct), "Ana Cruz in Packing team" (group, same as 300), "Mira" for AI; body ≤ 80 characters, or "Sent a photo" / "Sent a voice message" / "Sent a file". Headers: TTL 12 h, urgency high, `Topic: c<id>` (the push service also keeps only the newest undelivered one per conversation).

**Browser.**
- `public/sw.js` (scope `/`, no fetch handler, no secret): `push` → if any Likha window is visible, show nothing (300's in-page alert does it); else one notification per conversation (`tag`), the newest text, with "N new messages" when it replaces an earlier one; sets the app badge from `unread` where supported. `notificationclick` → focus an open Likha tab and send it to `/chat?c=<id>`, else open a window.
- `public/manifest.webmanifest` ("Likha", `#2563eb`, `start_url` `/chat`, standalone, icons 192 / 512 + maskable) and `public/icons/` made from the sidebar's "L" tile; `apple-touch-icon` 180 for iPhone. Linked from the layout head (manifest, theme-color, apple-touch-icon, `apple-mobile-web-app-capable`).
- Chat page Alerts popover: with keys, the 300 "Allow notifications" button becomes "Turn on alerts" → permission → register `/sw.js` → subscribe with the public key → `POST /chat/push`. State for this device: "Alerts on for this device" + "Turn off" (unsubscribe + `DELETE`). Never asks on load. iPhone outside the installed app: the button is replaced by "To get alerts on iPhone: Share → Add to Home Screen, then open Likha from the Home Screen". Blocked / unsupported states keep a short line. Without keys the popover is exactly 300's.
- `components/chat-unread.blade.php`: `navigator.setAppBadge(n)` / `clearAppBadge()` where supported, beside the existing count (WP-4).

**Tests first** (`tests/Feature/Chat/`): `PushSubscriptionTest` (save, list, remove, several devices, another user cannot remove yours, the same endpoint moves between people, guests 401, off → 404), `PushEndpointTest` (allowlist: http, unknown host, IP v4/v6, localhost, port, user info, bad keys → 422; each known service → ok), `PushSendTest` (other members' devices only, not sender, muted, removed, edited, system, owner not a member, deactivated, Mira as sender, group title, attachments' text, 80 characters, payload shape), `PushRetryTest` (404/410 removes, other failures retried at most twice, logged), `PushOffTest` (no keys → no button, no job, no error, page as 300), `PushKeysCommandTest` (writes when missing, `already set`, partial refuses, never prints the private key), `PushCryptoTest` (real VAPID headers + payload encryption with the library, no network), `PushPageTest` (manifest, sw.js has no fetch handler / no secret, layout links, button text, iPhone line, badge code).

**Design**: impeccable brief in `docs/design/308-chat-web-push/brief.md` before the view; screenshots at 390 (off, on, iPhone line) and 1280 (alerts on) from `artisan serve` on 127.0.0.1 with a local seeded sqlite file; critique and audit; fixes.

**Files**: new `ChatPush.php`, `PushSender.php`, `SendChatPush.php`, the command, the migration, `public/sw.js`, `public/manifest.webmanifest`, `public/icons/*`, tests, design folder; changed `ChatMessage.php` (the hook), `User.php` (cast), `config/chat.php` (appended), `routes/web.php` (3 lines in the chat block), `ChatController::index` (boot: push key flag), `chat/index.blade.php` (Alerts popover + its JS), `components/chat-unread.blade.php` (badge), `components/layout.blade.php` (head links), `ChatTestCase.php` (migration line), `composer.json` / `composer.lock`.

## What shipped

Likha Chat now alerts a phone or PC even when Likha is closed, like Messenger. The plan held; the differences from it are listed at the end of this section.

- **WP-1, turn on alerts on this device.**
  - On `/chat`, the Alerts popover (sliders button) shows "Turn on alerts" (the old `#lc-notif-allow`). One tap asks the browser for permission, registers `/sw.js`, subscribes with the server's public key and saves the subscription.
  - The popover shows this device's state:
    - "✓ Alerts on for this device" with "Turn off" (unsubscribe + remove on the server);
    - "Turn on alerts" when it is off;
    - blocked: how to allow it again;
    - not supported: the sound still plays while Likha is open;
    - the iPhone line (WP-3).
  - The browser is never asked on load. On load the page only works out the state. If this device had alerts on and the browser dropped or rotated the subscription, it subscribes again quietly (permission is already granted, so nothing is asked).
  - Each device is saved separately (phone, office PC…), up to 10 per person.
  - 404 or 410 from the push service removes that device on the send.
  - Signing out keeps the devices. A deactivated user gets nothing.
- **WP-2, a push for each new message.**
  - Every new message is queued as one `SendChatPush` job. That covers a person's text, photo, file or voice, and Mira's `AiPost`.
  - The job pushes to every device of every active member except the sender. Muted members get nothing, and deactivated people get nothing.
  - Not pushed:
    - system lines;
    - a message removed or edited before the job runs (edits and removals never push);
    - the owner reading a chat he is not a member of.
  - Title: "Ana Cruz", "Ana Cruz in Packing team", or "Mira". Body: the first 80 characters, or "Sent a photo" / "Sent a voice message" / "Sent a file".
  - A tap focuses an open Likha tab and opens the chat there, or opens `/chat?c=<id>`.
  - One notification per conversation: the newest message, with "3 new messages · …" when it replaces earlier ones. The push service also keeps only the newest undelivered one (`Topic`).
  - Nothing shows while a Likha tab is visible on that device, because 300's in-page alert does it.
  - Posting never waits: the job is queued after the commit. A failed device is logged (host only) and retried at most twice, for that device only.
- **WP-3, install on a phone.**
  - `public/manifest.webmanifest`: "Likha", `#2563eb`, `start_url` `/chat`, standalone.
  - Icons 192 / 512 (plus maskable) made from the sidebar's "L" tile, a 180 px `apple-touch-icon` and a 96 px monochrome badge.
  - Linked from the layout head, with `theme-color`.
  - On an iPhone outside the installed app, the button is replaced by "To get alerts on iPhone: Share ⬆ → Add to Home Screen, then open Likha from the Home Screen."
- **WP-4, unread on the app icon.**
  - The every-page unread poll (300) calls `navigator.setAppBadge(n)` / `clearAppBadge()` where the browser has it.
  - Each push carries the person's unread total, and the service worker sets the badge from it.
  - Nothing happens elsewhere.
- **Without keys, push is off.** Push needs `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`. Without them:
  - the popover is exactly 300's ("Allow notifications");
  - `/chat/push` returns 404;
  - nothing is queued or sent, and nothing fails.

**Different from the plan:**
- `PushSender` is not bound in `AppServiceProvider`, a file other specs share. Instead, `ChatPush::sender()` uses a bound fake when there is one (tests) and otherwise the real `WebPushSender`.
- `phpunit.xml` gained two lines that pin the VAPID keys empty in tests (see Merge danger).
- `SendChatPush` uses `RestoresMissingDefaults`, as the repo's queued-job guard requires.

### Files
- **New:**
  - `app/Services/Chat/ChatPush.php`: allowlist, devices, payload, recipients.
  - `app/Services/Chat/PushSender.php` (interface) and `WebPushSender.php`.
  - `app/Jobs/SendChatPush.php`.
  - `app/Http/Controllers/ChatPushController.php`.
  - `app/Console/Commands/ChatPushKeys.php`.
  - `database/migrations/2026_12_01_308000_add_chat_push_to_users.php`.
  - `public/sw.js`, `public/manifest.webmanifest`, `public/icons/*` (6 PNGs).
  - Tests in `tests/Feature/Chat/`:
    - `PushTestCase`, `FakePushSender`;
    - `PushSubscriptionTest`, `PushEndpointTest`, `PushSendTest`, `PushRetryTest`, `PushOffTest`;
    - `PushKeysCommandTest`, `PushCryptoTest`, `PushPageTest`.
  - `docs/design/308-chat-web-push/`.
- **Changed:**
  - `app/Models/ChatMessage.php`: a `created` hook.
  - `app/Models/User.php`: `chat_push` cast, and hidden from serialization.
  - `app/Http/Controllers/ChatController.php`: `push_key` in the page's boot data, only when push is on.
  - `config/chat.php`: the `push` block, appended.
  - `routes/web.php`: 3 routes at the end of the chat block.
  - `resources/views/chat/index.blade.php`: the Alerts popover markup, its CSS and its JS.
  - `resources/views/components/chat-unread.blade.php`: the app badge, and opening a chat from a notification tap.
  - `resources/views/components/layout.blade.php`: 4 head tags and a Blade comment.
  - `composer.json` / `composer.lock`.
  - `phpunit.xml`: VAPID pinned empty.
  - `tests/Feature/Chat/ChatTestCase.php`: the migration line.
  - `tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php`: re-pinned. **Reviewer: confirm**; the proof is under the full suite.
- **Not touched:**
  - `AiPost.php`, `ChatAccess`, `ChatPresenter`, `ChatMessenger`, `ChatMessengerController`, `ChatGroupController`;
  - the sidebar and navlink;
  - `AppServiceProvider`.

### Migration
| File | What |
|---|---|
| `2026_12_01_308000_add_chat_push_to_users` | `users.chat_push` json null: this person's devices `[{endpoint, p256dh, auth, added_at, ua}]`, at most 10. Guarded by `hasColumn`; `down()` drops it. Additive. Not run against any server. |

No new table (Ruling 1).

### Routes (inside the `/chat` block: `['web','auth']`, names `chat.push.*`)
| Method | Path | What | Throttle |
|---|---|---|---|
| GET | `/chat/push` | your own devices: `{devices: [{key (first 16 hex of sha256(endpoint)), service, added_at}]}`, never an endpoint or key | 60/min |
| POST | `/chat/push` | `{endpoint, keys: {p256dh, auth}}` (the browser's `subscription.toJSON()`). 201 new, 200 already saved. 422 off the allowlist or for bad keys | 20/min |
| DELETE | `/chat/push` | `{endpoint}`: removes it from **your** list only, `{removed: bool}` | 20/min |

All three return 404 while push is off, and 401 for guests.

### The queued job
`App\Jobs\SendChatPush(messageId, ?only = null, attempt = 0)`:
- Connection and queue: the default ones (`QUEUE_CONNECTION`, `database` on the server; queue `default`).
- `$tries = 1`, timeout 60 s. It is dispatched `afterCommit` from `ChatMessage::created`.
- It sends one request per device through `minishlink/web-push`: VAPID, aes128gcm, TTL 12 h, urgency high, `Topic: c<id>`, redirects not followed, 10 s timeout.
- Each device ends as one of:
  - **ok**: nothing more;
  - **gone** (404 / 410): removed from that person's list;
  - **failed** (anything else, or the sender threw): one warning line `chat push failed {message, user, host, attempt}` and a new job for just those devices (attempt + 1, after 30 s and then 60 s), at most 2 retries.

### Push payload (encrypted; the push service sees only the size)
```
{ "c": 12, "id": 345, "title": "Ana Cruz in Packing team", "body": "Pickup at 3", "url": "/chat?c=12", "tag": "likha-chat-12", "unread": 4 }
```

### gmp / bcmath
- **`gmp` is not needed.**
  - `minishlink/web-push` 10.0.1 requires `ext-curl`, `ext-json`, `ext-mbstring` and `ext-openssl`, and lists `ext-gmp` and `ext-bcmath` only under `suggest` ("Optional for performance").
  - Its maths runs on `brick/math`, which uses GMP, then BCMath, then pure PHP, whichever is there. It is required by `web-token/jwt-library` 4.2.7 and `spomky-labs/pki-framework` 1.6.3.
- **Proved with gmp off.** `docs/design/308-chat-web-push/no-gmp-run.txt` is `php -n` (no php.ini) with only openssl, mbstring, curl, pdo_sqlite, sqlite3, fileinfo and gd (gd only for the tests' fake photo). That gives "gmp loaded: false, bcmath loaded: true", the server's case. All 62 push tests pass, including `PushCryptoTest`, which runs real VAPID signing and real aes128gcm encryption through the library with only the HTTP mocked.
- **Packages added** (no existing package changed version): `minishlink/web-push` 10.0.1, `web-token/jwt-library` 4.2.7, `spomky-labs/pki-framework` 1.6.3 and `spomky-labs/base64url` 2.0.4.
- **Windows note (dev machines only).** Herd's PHP on Windows cannot make P-256 keys without `OPENSSL_CONF` pointing to an `openssl.cnf` (for example `C:\Users\<you>\.config\herd\bin\php84\extras\ssl\openssl.cnf`). Linux has a default one.
  - Without it, `chat:push-keys` prints one error line and changes nothing.
  - The two tests that need EC keys (`PushKeysCommandTest`, `PushCryptoTest`) skip with that reason.
  - Sending fails, logged and retried.
  - My runs set `OPENSSL_CONF`.

### Deploy steps (for the architect)
1. Deploy the code as usual. Then run `composer install --no-dev --no-interaction` in `/var/www/likha` if the deploy procedure does not already do it; it adds the 4 packages above. No server extension is needed.
2. Run the migration: `php artisan migrate` (adds `users.chat_push`). Push stays off until step 3, so the order of 1 and 2 does not matter, but run 2 before 3.
3. Run `php artisan chat:push-keys` as the web user (`sudo -u www-data php artisan chat:push-keys`). It prints only `created` (it wrote `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` and `VAPID_SUBJECT=mailto:<MAIL_FROM_ADDRESS>` into `.env`) or `already set`. If it prints an error, nothing was written.
4. Run `php artisan config:clear`, then `php artisan config:cache` if the server caches config.
5. Restart the queue workers (`php artisan queue:restart`), so they load the new job class and the keys.
6. **Which worker sends the pushes:** the default-queue worker on the `database` connection (`queue:work` with no `--queue`, or `--queue=default`). Spec 283 says both sites already run one. Check with `ps aux | grep "queue:work"`.
   - If no worker reads `default` on the default connection, pushes wait in the `jobs` table.
   - Horizon (redis) does not read it unless `QUEUE_CONNECTION=redis`.
7. The server must reach `https://fcm.googleapis.com`, `https://web.push.apple.com` and `https://*.push.services.mozilla.com` (port 443).

### How the owner tests it on his phone (after the deploy)
1. **Android (Chrome):**
   - Open Likha, sign in, go to Chat, tap the sliders button (Alerts), tap **Turn on alerts**, then Allow. It should say "Alerts on for this device".
   - Optional: Chrome menu → **Install app** / Add to Home screen.
2. **iPhone (iOS 16.4 or later):**
   - In Safari, open Likha. The Alerts box shows the Home Screen line.
   - Tap Share → Add to Home Screen, then open **Likha from the Home Screen**, sign in, go to Chat → Alerts → **Turn on alerts** → Allow.
3. **Send a test:**
   - Lock the phone, or switch to another app; Likha must not be on screen.
   - From the office PC, signed in as someone else, send him a direct message (or let Mira send a follow-up).
   - The phone should show "<name>" and the text. Tapping it opens that chat.
   - Send two more: they should replace it as "3 new messages · …".
4. **Turn off:** Chat → Alerts → **Turn off**. Send again: nothing arrives.
5. **If nothing arrives:**
   - check that the worker runs (Deploy step 6);
   - check `storage/logs/laravel.log` for `chat push failed` (it shows the host only);
   - check that the phone's system notification setting for Chrome or Likha is on.

## Evidence per done-when item

**1. Tests** (`tests/Feature/Chat/`, the push sender faked; 62 new tests, all passing):

| Case | Test |
|---|---|
| Save, list, remove; several devices per person; another user cannot remove yours | `PushSubscriptionTest`: `…saves_lists_and_removes_a_device` (the listing never carries the endpoint or keys), `…several_devices_and_saving_the_same_one_twice_keeps_one`, `…another_user_cannot_remove_your_device` (`removed: false`, Ana keeps hers), `…same_browser_saved_by_a_second_person_moves_to_that_person`, `…at_most_ten_devices_and_the_oldest_drops`, `…signing_out_keeps_the_devices`, `…guests_get_401…`, `…without_keys_the_push_routes_are_404…` |
| The endpoint allowlist | `PushEndpointTest::test_an_endpoint_off_the_allowlist_is_422`: 422 for each of http, an unknown host, a look-alike host, a suffix without a dot, IPv4 loopback, private IPv4, IPv6 `[::1]`, localhost, port 8443, user info, no scheme, `file:`, `javascript:`, over 2048 characters and a non-string. `…each_known_push_service_is_accepted` (FCM, Mozilla, Apple, WNS); `…bad_keys_are_422` (5 cases) |
| A message pushes to every other member's devices and not the sender's | `PushSendTest::test_a_message_pushes_to_every_other_members_devices_and_not_the_senders` (title, body, url, tag, id, unread, topic, per-device keys) |
| Muted, removed, edited, system | `…no_push_for_a_muted_conversation_until_the_mute_ends`, `…no_push_for_a_removed_or_edited_message`, `…a_message_removed_before_the_job_runs_is_not_pushed`, `…system_lines_and_people_who_left_get_nothing` |
| Owner reading a chat he is not in | `…the_owner_gets_nothing_from_a_conversation_he_is_not_in_but_does_from_his_own` |
| Deactivated user | `…a_deactivated_user_gets_no_pushes` |
| Mira's AI message pushes as "Mira" | `…mira_pushes_with_mira_as_the_sender`; `…three_follow_ups_share_one_tag_so_they_show_as_one_alert` |
| Title and body | `…direct_message_is_titled_with_the_sender_and_the_body_stops_at_80_characters`, `…attachments_say_sent_a_photo_a_voice_message_or_a_file` |
| Queued, never delays posting | `…posting_is_never_held_up_by_the_push` (`Queue::fake`: queued, nothing sent during the post), `PushRetryTest::test_a_sender_that_throws_does_not_break_posting` |
| 404 / 410 removes; other failures retry at most twice | `PushRetryTest`: `…gone_subscription_is_removed_and_the_others_stay` (not retried, and the next message skips it), `…failed_send_is_logged_and_retried_at_most_twice_for_that_device_only` (3 tries in total, the other device once, 3 warnings without keys), `…a_retry_that_works_stops_retrying` |
| No keys: no button, no send, no error | `PushOffTest`: `…no_keys_means_no_button_no_job_no_send_and_no_error` (300's box, no job queued, devices kept), `…a_job_already_queued_when_the_keys_are_removed_sends_nothing`, `…only_one_key_set_is_off` |
| `chat:push-keys` | `PushKeysCommandTest`: creates both keys (87 / 43 base64url characters, P-256) and the subject, prints only `created`, the rest of `.env` kept; `already set` with the file unchanged; empty values count as missing; one key without the other is refused and nothing changes; an existing subject is kept; no `.env` → fails. The output never contains a key |
| Real encryption and SSRF in the sender | `PushCryptoTest`: VAPID `Authorization`, `aes128gcm`, TTL / Urgency / Topic headers; the body is encrypted (no plain text in it); 201 → ok, 410 and 404 → gone, 500 → failed, 302 → failed and not followed; an off-list endpoint is never requested |
| Browser files | `PushPageTest`: the button, the on line, Turn off, the public key in the page and never the private one; the iPhone line; `register('/sw.js', { scope: '/' })`; no top-level `requestPermission`; `sw.js` has no fetch handler, no `caches.`, no key, has push / notificationclick, the visible-tab check, the tag/count collapse and `setAppBadge`; the manifest fields and every icon's real PNG size; the head links; the badge code in the every-page script |

**Real push, end to end (welcome evidence).** Locally, with `artisan serve` and the seeded sqlite file:
- Headless Chrome got a real FCM subscription with "Turn on alerts", and the server saved it.
- The page was left on `about:blank`, so no Likha tab was visible.
- A second headless Chrome, signed in as Ben, sent Ana three messages. The server sent three real pushes to `fcm.googleapis.com`.
- Ana's service worker then had exactly one notification: `{"title":"Ben Reyes","body":"3 new messages · Bring the 14 boxes down please.","tag":"likha-chat-2","data":{"url":"/chat?c=2","c":2,"count":3}}`.
- One finding from this: FCM still accepted pushes (2xx) for two subscriptions from earlier, already-deleted Chrome profiles. FCM reports 404/410 only once it learns the browser is gone. So a dead device can linger on the list until FCM says so; that is normal for web push. The 404/410 path is covered by the tests above.

**Full suite against base**: see below.

**2. Design evidence** in `docs/design/308-chat-web-push/`:
- `brief.md`, written before the build.
- Screenshots at 390 px:
  - `390-alerts-off.png`: Turn on alerts.
  - `390-alerts-on.png`: "Alerts on for this device" and Turn off.
  - `390-alerts-iphone.png`: the Home Screen line, with an iPhone user agent and Safari's missing `PushManager` / `Notification` emulated.
  - `390-alerts-blocked.png`.
- Screenshot at 1280 px: `1280-chat-alerts-on.png`.
- `before-fixes/`: the four shots from before the critique.
- `review.md`: the critique (29/36), the detector (no finding in 308's lines; 3 inherited, mostly false positives) and the audit (16/20 → 19/20). It lists each finding and what was fixed or left, with the reason. Two P1s (focus, 44 px), three P2s and two P3s were fixed.
- `no-gmp-run.txt`.

**3.** Migrations, routes, the job, the payload, gmp, the deploy steps, the owner's phone test: above. Merge danger: below.

**4.** Pushed to `origin/feat/308-chat-web-push`. Not merged, not deployed.

## Story changes
None. Two refinements the stories leave open are in Rulings 7 and 12 (iPhone and Safari, and "visible tab").

## Rulings (choices the spec left to me)
1. **No new table: a json column `users.chat_push`** (the spec's default).
   - The concurrency risk is handled by locking the user row (`lockForUpdate`) in every write: save, remove, and gone cleanup from the job.
   - Writes are rare (turn on, turn off, a 404/410). Sends only read.
   - The one cross-person rule (the same browser saved by a second person, Ruling 2) scans users that have devices. That is the staff table, a few dozen rows.
   - A table would only pay off with hundreds of devices per person or per-device statistics.
2. **Shared PC: one endpoint belongs to one person.** When someone saves a browser that another person had saved, it moves to them. Otherwise, on a shared office PC, B's alerts would also show A's messages after A signed out (the spec keeps subscriptions on sign-out).
3. **At most 10 devices per person**; the oldest drops.
4. **Title and body.**
   - The title follows 300's in-page alert: "Ana Cruz in Packing team" for a group, the name for a direct chat, "Mira" for Mira.
   - The body uses 300's preview at 80 characters, so several files read "Sent 3 photos" / "Sent 2 files". Voice says "Sent a voice message", as the spec asks.
5. **"One notification per conversation, with the count".** The service worker reads the earlier notification with the same tag and shows "N new messages · <newest>", with `renotify` so the phone buzzes again. The count resets when the notification is opened or swiped. The `Topic` header also lets the push service drop older undelivered pushes for the same chat.
6. **The owner** gets pushes only where he is a member. Reading a chat creates no membership (304), so he gets nothing from chats he only reads.
7. **"A Likha tab is open and visible"** means any visible Likha page (any window of the site), not only `/chat`. 300's every-page poll covers those pages.
   - **Risk on Safari / iPhone:** WebKit may revoke a subscription after several pushes that show nothing.
   - Mitigation: when `/chat` is opened on a device that had alerts on, it re-subscribes quietly. **Reviewer:** if the owner's iPhone keeps dropping to "off", the fallback is to always show the notification on iOS.
8. **Retries.** `$tries = 1` on the job. A retry is a new job with only the failed devices, so people who already got the push do not get it twice. The delays are 30 s, then 60 s. A sender that throws counts every device as failed.
9. **Deactivated** = the employee profile's status is inactive, resigned or terminated, as `ChatPeople` defines it. A user with no profile is still treated as active: they can chat (296).
10. **The allowlist** is exactly the four the spec names:
    - `fcm.googleapis.com` (Chrome, Android, Chromium Edge, Samsung, Opera);
    - `*.push.services.mozilla.com`;
    - `web.push.apple.com` (Safari and iPhone);
    - `*.notify.windows.com` (old Edge).

    Also: https only; port 443 or none; no user info; no IP or localhost; at most 2048 characters. The real sender checks the allowlist again, does not follow redirects, and treats a 3xx as a failure. Keys must be base64url of exactly 65 bytes (starting 0x04) and 16 bytes.
11. **Without keys**:
    - the popover is 300's unchanged;
    - devices saved earlier are kept and get nothing;
    - one key without the other counts as off.

    `chat:push-keys` refuses when only one key is set, rather than replacing it, because a new public key would silently break every saved device.
12. **iPhone detection.** The line shows when all three hold:
    - an iOS or iPadOS browser;
    - not opened from the Home Screen;
    - no `PushManager`.

    An installed app on iOS older than 16.4 gets the "cannot get alerts when Likha is closed" line.
13. **Badge** (WP-4). It is set by the every-page poll and by each push (`unread` in the payload). Reading a chat on another device does not clear the phone's notification or badge until that phone's next poll or push. Clearing them would need a second kind of push, which is out of scope.
14. **Notification tap.** It focuses any open Likha tab and asks the page to open the chat: in place on `/chat`, by navigation on other pages. With no tab open, it opens a window at `/chat?c=<id>`.
15. **Icons.** They are drawn from the sidebar's "L" tile (white bold L on `#2563eb`) with headless Chrome on 127.0.0.1, from a throwaway page that was deleted. The badge is a 96 px white L on transparent (Android tints it).
16. **TTL is 12 hours**: a phone that is off for longer does not get stale alerts. **Urgency is high**: chat should wake the phone.
17. **`users.chat_push` is `$hidden`** on the model, so no JSON of a user ever carries endpoints or keys.
18. **`phpunit.xml` pins `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` empty.** Otherwise a developer's `.env` with keys would switch push on in every test that creates a chat message (as `FORBEAST_SITE_KEY` is pinned already).
19. **Comments** are Taglish in PHP, as in the chat files. `sw.js` and the JS are in English, like the chat page's script.

## Merge danger
- **What ships:**
  - 1 additive migration (one nullable column on `users`);
  - 3 routes;
  - 1 queued job, created for every new chat message **only when keys are set**;
  - 1 command;
  - 2 public files plus 6 icons;
  - head tags on every `<x-layout>` page (manifest, theme-color, apple-touch-icon, app title);
  - the Alerts popover;
  - a few lines in the every-page unread script;
  - 4 composer packages.
- **Before the keys exist** (deploy steps 1-2 only), behaviour is exactly 300's. The only visible change is that phones can "Install app" / Add to Home Screen.
- **Load:**
  - one row in `jobs` per chat message;
  - one HTTPS request per device of each recipient, from the queue worker, never from the request;
  - the job reads members, the users with devices, and each recipient's unread total (the same indexed count the unread poll runs).
- **How to switch push off:** remove `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` from `.env`, run `php artisan config:clear` (or `config:cache`), then `php artisan queue:restart`. Then:
  - the button disappears (the popover becomes 300's);
  - jobs already queued send nothing;
  - `/chat/push` returns 404.

  Saved devices stay in `users.chat_push`; putting the **same** keys back turns them on again. New keys break every saved device, and each person has to tap Turn on alerts again.
- **How to revert:**
  - Revert the branch's commits.
  - Roll back `2026_12_01_308000_add_chat_push_to_users`; the devices are lost.
  - Remove the three `VAPID_*` lines.
  - Run `composer install` (removes the 4 packages).
  - Browsers keep the registered `/sw.js` until it 404s. A missing service worker script unregisters itself on the next update check, and it never intercepts pages (no fetch handler), so a revert breaks nothing.
- **Shared files** (other specs may touch them):
  - `routes/web.php`: 4 lines at the end of the chat block.
  - `config/chat.php`: one appended `push` block.
  - `app/Models/User.php`: 1 cast and 1 `$hidden` entry.
  - `components/layout.blade.php`: 5 head lines after `<title>`.
  - `components/chat-unread.blade.php`: two small blocks.
  - `ChatController::index`: the boot array.
  - `phpunit.xml`: 3 lines.
  - `tests/Feature/Chat/ChatTestCase.php`: 1 migration line.
  - `SharedPagesBasePinTest`: 1 hash and the docblock. Any later spec that changes the layout head or the unread script will re-pin it again.
  - `composer.lock`: if another branch also adds packages, take both `composer.json` lines and regenerate the lock (`composer update minishlink/web-push` plus the other package) instead of hand-merging the hash.
- **Untouched:**
  - 305-307's files;
  - `AiPost` / `AiFollowups`;
  - `ChatAccess`, `ChatPresenter`, `ChatMessenger`;
  - the sidebar;
  - `AppServiceProvider`.

## For the reviewer
1. **`SharedPagesBasePinTest` re-pinned** (`/owner/column-settings/owner-private`, `9a2d5fda…` → `f64d3fe3…`). This is outside the spec's file list, as in 300. Proof: a throwaway test (scratchpad, not committed) rendered the page with only `components/layout.blade.php` and `components/chat-unread.blade.php` swapped back to base 0d70b29f, and got `9a2d5fda…` exactly.
2. **`QueuedJobCompatTest` on my base run.** My base run shows it failing, but that was my doing: I created `app/Jobs/SendChatPush.php` (new file, before it used `RestoresMissingDefaults`) while the base suite was still running, and that test scans `app/Jobs`. On the real base it passes. On the branch it passes.
3. **Ruling 7, the iPhone silent-push risk**, is the one behaviour to watch on the owner's phone.
4. **Allowed-commands notes:**
   - A local `.env` (git-ignored, sqlite file in the scratchpad, never a real database) was created for `artisan serve` and `chat:push-keys`, and removed again before the test runs.
   - Throwaway phpunit files in the scratchpad seeded the sqlite file and proved the re-pin.
   - Headless Chrome was driven by a small Node script (built-in WebSocket, CDP), only against 127.0.0.1, plus one `about:blank`.
   - The local push test made real outbound requests from this PC to `fcm.googleapis.com`, which the spec welcomes as evidence.
   - `artisan serve --no-reload` was used so the PHP child kept `OPENSSL_CONF`.
   - The impeccable detector was run by a sub-agent (curl login against 127.0.0.1 and a scan of the saved page).

### Full suite against base
`vendor/bin/phpunit` under Herd PHP 8.4, sqlite `:memory:`, no `.env`. Both runs set `OPENSSL_CONF`, so the two EC-key test files ran instead of skipping. Nothing in the worktree changed during the branch run.

| Run | Tests | Failures | Errors | Skipped |
|---|---|---|---|---|
| Base 0d70b29f | 5899 | 24 | 5 | 6 |
| Branch, last code commit 2a4ec2f8 | 5961 (+62 push tests) | 23 | 5 | 6 |

**No new failures.** The branch's 28 failing tests are, by name, the base's 29 minus one (a `comm` of the sorted names shows nothing new):
- **Listed in the spec:**
  - `ExampleTest`;
  - `ItemTableFitScriptTest`;
  - `SharedPagesBasePinTest` with `/owner/private`;
  - the Chatblast speed tests under load: `SpeedMemoryTest` ×4, `SpeedPrewarmTest` ×10, `ResponseSpeedTest` ×10, `SpeedPrecomputedTest` ×1 (all four are timing-sensitive Chatblast speed files).
- **The one fewer:** `Chatblast\QueuedJobCompatTest::test_S_219_3_…restores_missing_defaults`. It failed on my base run only because I created `app/Jobs/SendChatPush.php` while that run was going (see "For the reviewer", 2). It passes on the branch.
