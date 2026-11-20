# Design brief: chat alerts when Likha is closed (`/chat` Alerts popover, spec 308)

Surface: the Alerts popover in the `/chat` list header (`#lc-alerts` in `resources/views/chat/index.blade.php`, opened by the sliders button), plus what the phone shows outside the page: the push notification, the installed app's icon and its badge.
Mode: **Operate.** A staff member sets this up once per device, then forgets it. Ambition here means one clear button, a state they can trust at a glance, and a notification that looks like Messenger's.

Visual authority: the shipped 296/300 chat page and `docs/design/300-chat-messenger/brief.md`. That means white panels on gray-50, one blue accent `#2563eb`, the system font, Font Awesome icons, and the 280 px popover with its 13 px muted text and Sound switch. This brief **extends** that page; nothing outside the popover changes look.

Assumptions (non-interactive run: impeccable's interview could not be asked, and no PRODUCT.md exists, so I inferred these from spec 308 and the shipped page; the reviewer may correct them):
- A1. The existing popover is the right home: one place for "alerts on this device" and "sound". No banner on the page and no prompt on load (the spec forbids asking on load).
- A2. "Turn on alerts" reads as one action that ends in the browser's own permission dialog. The button gives no further explanation; the line above it says what you get.
- A3. The app icon is the sidebar's "L" tile (white L on Likha blue, rounded square). There is no other logo in the repo.

## 1. Job and audience
- **Who:** 10-30 staff, mostly on Android phones, some on iPhones, plus an office PC each. They are used to Messenger alerts arriving with the phone locked.
- **Need:** turn alerts on once per device, see that it worked, and turn it off on a device they no longer use.
- **Success:** a locked phone lights up with "Mira" and the follow-up text, and a tap opens that chat in Likha. Three follow-ups in a minute show as one alert.

## 2. Direction: "one switch per device, said plainly"
- The popover keeps its two parts: **this device's alerts** on top and **Sound** below the hairline. Only the top part changes.
- The state is a sentence, not a toggle. Turning alerts on involves a browser dialog that can be refused, so a switch would lie while the dialog is open. The sentence says what is true now.
- Colour only where meaning needs it. "On" gets a small green check icon (Font Awesome `fa-circle-check`, the green of the active dot) before "Alerts on for this device". Everything else stays gray text and the blue primary button. "Turn off" is a quiet text button (`lc-btn`, not primary, not red): turning off is safe and easy to undo.

## 3. States (top part of the popover)
| State | Line (13 px muted, except "on") | Control |
|---|---|---|
| Off, can push | "Get alerts on this device for new messages, even when Likha is closed." | **Turn on alerts** (primary, full width on phones) |
| Working | the same line | button disabled, label "Turning on…" |
| On (this device saved) | ✓ **Alerts on for this device** (ink, 14 px, semibold) + "New messages show here even when Likha is closed. Muted chats stay quiet." | **Turn off** (secondary) |
| iPhone, not installed | "To get alerts on iPhone: Share → Add to Home Screen, then open Likha from the Home Screen" (with the Font Awesome share icon inline after "Share", decorative) | none |
| Blocked by the browser | "Alerts are blocked for Likha in this browser. Allow notifications in the site settings, then try again." | none |
| Not supported (old browser) | "This browser cannot get alerts when Likha is closed. Likha plays the sound while it is open." | none |
| Failed (save or subscribe error) | the off line + a red 13 px error under the button: "Could not turn on alerts. Try again." | button back |
| Push off on the server (no keys) | exactly 300's popover ("Allow notifications" for in-page notifications) | as 300 |

- Focus: when the popover opens, focus goes to the state's control (Turn on / Turn off), or to the Sound switch when there is none (as 300). After Turn on/off finishes, focus stays on the new control.
- Status changes are announced (`aria-live="polite"` on the line).
- On phones (≤ 1023 px) the buttons are 44 px high. The 280 px popover fits inside 390 px with the 12 px right offset.

## 4. The notification (outside the page)
- Title: the sender ("Ana Cruz", "Mira"). In a group: "Ana Cruz in Packing team" (same as 300's in-page alert).
- Body: the newest message, up to 80 characters, or "Sent a photo" / "Sent a voice message" / "Sent a file". When it replaces an earlier one from the same chat: "3 new messages · <newest text>".
- Icon: the 192 px app icon; badge (Android status bar): the same icon. One notification per conversation (tag), with a fresh sound and buzz when it is replaced (renotify).
- Tap: focuses an open Likha tab and opens the chat there, or opens `/chat?c=<id>` in a new window or the installed app.
- Nothing appears while a Likha tab is visible on that device: 300's chime and in-page alert cover that.

## 5. Install (manifest)
- Name and short name "Likha"; `theme_color` and `background_color`: the Likha blue `#2563eb` and white. Opens at `/chat`, standalone.
- Icons: 192 and 512 (white "L", system bold, centred on the blue rounded tile) and maskable versions with the L inside the 80 % safe zone. Also a 180 px `apple-touch-icon` for the iPhone Home Screen.

## 6. Anti-goals
- No permission prompt on page load, no nagging banner, no red "off" state.
- No new colours beyond the existing green dot colour for the check.
- Nothing outside the popover, the head links and the badge call changes.

## 7. Evidence to take
390 px: off, on, iPhone line (each with the popover open). 1280 px: the chat page with alerts on. Then critique and audit; fixes listed in `review.md`.
