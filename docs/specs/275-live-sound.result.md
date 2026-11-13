# 275: The live chat page plays a sound when a customer writes — result

## Summary
`/chatbot/live` now plays a short two-tone chime when a poll brings a new customer message. A burst rings once
(at most one chime per 3 seconds). A header button "Sound on / Sound off" (default on) is remembered in this
browser under localStorage key `chatbot-live-sound` (wrapped in try/catch; without storage it is simply on).
When the browser blocks audio, a gold chip "Click to enable sound" appears next to the LIVE dot; one click plays a
test chime and hides it. Turning sound on with the button also plays a test chime. While sound is on and the tab is
hidden, the tab title gets the unread count, e.g. "(3) Chatbot · Live", cleared when the tab is visible again.

## How new inbound messages are detected
No new endpoint and no new query. The page already polls `/chatbot/live/feed` every 5 s with a cursor taken when
the page was rendered, so the first poll only returns rows written after the page loaded. Events of type `message`
come from `botcake_chats` (`LiveFeed::sources()`), which holds only messages a customer sent: page echoes are
stored by `ChatEchoes`, never in `botcake_chats` (`HandleGatewayEvent`), bot and staff replies live in
`chatbot_replies` (types `confirmed` / `follow_up`), and standby and ad-open rows are already excluded. So
"new inbound" = the count of `type === 'message'` events in a poll (`LiveSound.inbound`). The chime follows the
page's own filters (page, bot), like everything else on the page.

One change to the poll was needed for S4 (and for the sound to help when the tab is in the background): before,
the poll stopped while the tab was hidden. Now, while sound is on, the poll keeps running when hidden, updating the
cursor, the counts, the chime and the title, but the animations are not played until the tab is visible (the page
already paints numbers directly while hidden). With sound off the old behaviour stays: the poll pauses and the
status says PAUSED. Note: browsers throttle timers in background tabs, so after some minutes hidden Chrome may poll
only about once a minute; the chime then comes on that poll.

## Where the sound lives
`public/sounds/new-message.wav` (18.5 KB, 0.42 s, 22.05 kHz mono, generated locally: 880 Hz then 1318.5 Hz with a
quick decay, peak about 0.6 of full scale). It is played with `new Audio()` at volume 0.6, referenced from the
button's `data-live-sound-src` with the same content-hash `?v=` as the page's scripts. No CDN.

## Files changed
- `public/js/chatbot-live-sound.js` (new): the pure rules, `LiveSound` — `inbound`, `ring` (3 s gap), `arrive`
  (one poll: ring?, new last-ring time, unread count), `title`, `readOn` / `saveOn`.
- `public/sounds/new-message.wav` (new): the chime.
- `resources/views/chatbot/live.blade.php`: the button and the chip in the header, their CSS, the script tag, the
  glue (chime, toggle, chip, title) and three lines in the poll / visibility handler (keep polling while hidden
  when sound is on; skip animations while hidden; clear the title count on return).
- `tests/Feature/Chatblast/ChatbotLiveSoundTest.php` (new).

## Tests and evidence
`tests/Feature/Chatblast/ChatbotLiveSoundTest.php` (4 tests): the page renders the toggle ("Sound on", data-on
true), the hidden chip, the sound file reference with its version hash and the versioned script; the WAV exists, is
small and is RIFF/WAVE; the only audio URL on the page is the app's own file. In node (`tests/js/call.cjs`): only
`message` events ring (confirmed, follow_up, ai, order do not), three messages in one poll ring once, 2 s after a
chime no ring, at 3 s ring again, an empty poll does not ring, sound off never rings and has no count; the unread
count grows only while hidden and prefixes the title; the choice defaults to on, "off" is read back, storage
missing or broken still means on.

Live-page tests together: `Tests:    34 passed (530 assertions)`.
Full suite: `Tests:    3 failed, 6 skipped, 5150 passed (81375 assertions)` — the three failures are the known ExampleTest, ItemTableFitScriptTest and SharedPagesBasePinTest.

## Manual check
1. Open `/chatbot/live` as CEO (or anyone with the Chatblast nav link). The header shows "Sound on" left of the
   filter button; before any click, the gold "Click to enable sound" chip shows next to the LIVE dot.
2. Click the chip: a short two-tone chime plays and the chip disappears.
3. From a customer account, send a message to a page in Likha. Within about 5 s the chime plays once. Send three
   messages quickly: one chime per poll, never two within 3 s.
4. A bot reply, a follow-up, an AI answer or an order alone does not chime.
5. Switch to another tab and send two messages: the chime plays and the tab reads "(2) Chatbot · Live"; come back
   and the title is plain again.
6. Click "Sound on": it reads "Sound off" (speaker with an x), no chime on new messages, and a hidden tab pauses the
   poll as before. Reload: still "Sound off". Click again: "Sound on" with a test chime.
7. Reload without clicking anything and wait for a message: if the browser blocks audio the chip appears; one
   click enables it.

## Open points
- Background-tab timer throttling (browser behaviour) can delay the chime to about once a minute after the tab
  has been hidden for a while; a visible tab or a TV screen polls every 5 s as before.
- The chip is shown at load when `navigator.userActivation` says nobody has clicked the page yet (Chrome, Edge,
  Firefox, Safari 16.4+). On a browser where autoplay is already allowed, clicking it just plays the test chime.
