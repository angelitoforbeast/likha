# Result 071: the ACTIVE column on /chatbot is a real on/off switch

## Commits

| Commit | What |
|---|---|
| `42ad0d5e` | docs: the brief, saved verbatim |
| `2fc6c6b1` | fix: `activeCell()` draws a sliding switch; `toggle()` slides it while busy |
| (this commit) | docs: result and screenshots |

## What changed

Only `resources/views/chatbot/index.blade.php`, inside `activeCell()` and at the top of `toggle()` (+18 −7):
- The ACTIVE cell is a `<button role="switch" aria-checked>` with a 40×22 px track and an 18 px knob, plus an "On"/"Off" label. The track is `bg-slate-300` when Off and `bg-green-500` when On. The knob moves `translate-x-[18px]` with a 200 ms transition. The label's color follows the state. A new helper, `paintSwitch(b, on)`, sets all of that.
- The aria-label and title are unchanged ("Switch on <page>" / "Switch off <page>"). It is a native button, so Space and Enter work. The focus ring is `focus-visible:ring-2 ring-blue-500 ring-offset-2`.
- For non-CEO users there is no active URL, so it is the same switch with `disabled` set. It does not react to clicks.
- After the confirm, the switch becomes disabled, gets `aria-busy="true"` and `opacity-50`, and slides to the requested side. The POST, the row error and the `render()` with the server's answer are unchanged. When Botcake fails, the server returns the old `active` value and the switch snaps back. Before, the busy state replaced the chip with the text "Working…".
- Tailwind comes from the CDN in `layouts.chatblast`, so no `npm run build` is needed for the new classes.

## Tests

The switch is drawn by the page's JavaScript, so a PHP feature test cannot see it. The test is the headless check below, written first. Against the old code it failed 14 of 15 checks; the run aborted at the non-CEO check because there was no `[role=switch]`. The one pass was "Space asks to confirm, then posts", which the old button already did. After the change, 15 of 15 pass.

`php artisan test tests/Feature/Chatblast`: 259 tests, 1703 assertions, all pass. The `!` warnings (`file_get_contents(...)` in the send, auto and history tests, and the doc-comment metadata deprecations) also appear on main without this change. I checked this on `ChatblastSendTest`: 20 such lines before and after the change.

## Headless check

There are no browser tools in this session. As in 068, I drove headless Chrome over CDP with a Node script kept outside the repo. Unlike 068 I used no running server:
- A scratch PHPUnit test, also outside the repo, booted the app on in-memory sqlite. It made 6 pages (3 On, 3 Off, one with a very long name). It saved the real `/chatbot` HTML for a CEO and for a Marketing user, plus the `/chatbot/data` JSON.
- Chrome loaded that HTML through CDP `Fetch` interception. `POST /chatbot/active` was answered after 1.2 s, either OK or with a Botcake error, and `confirm()` was stubbed. No Botcake call was made.

| Check | Result |
|---|---|
| 1366 and 390: On row has a green track (`rgb(34,197,94)`), knob right (20 px in), label On | ✔ ✔ |
| 1366 and 390: Off row has a grey track (`rgb(203,213,225)`), knob left (2 px in), label Off | ✔ ✔ |
| Track 40×22, knob 18 | ✔ |
| aria-label unchanged | ✔ ✔ |
| The switch fits inside the ACTIVE cell's padding, including the long-name row | ✔ ✔ |
| Tab reaches the switch and shows a blue focus ring | ✔ |
| Space opens the confirm, then one POST with the right `on` | ✔ |
| During the request: disabled, `aria-busy`, opacity 0.5, knob already on the new side | ✔ |
| After OK: the server's state, enabled again | ✔ |
| Botcake fails: On while busy, then back to Off, with the row error | ✔ |
| Filter Bot = On leaves only On switches | ✔ |
| Non-CEO: the same switch, disabled; a click opens no confirm | ✔ |

Screenshots are in `docs/specs/071-chatbot-switch/`:
- `desktop.png` and `phone.png`: Off and On rows at 1366 and 390 px;
- `desktop-focus.png`: the keyboard focus ring;
- `desktop-busy.png`: dimmed, mid-request;
- `desktop-error.png`: snapped back Off, with the Botcake error under it;
- `desktop-not-ceo.png`: the disabled switch for a Marketing user.

## Story changes

None. The brief brought no stories, and its decisions table served as the cases.

## Findings and notes

- At phone width the ACTIVE column is not sticky; only Page is, since spec 068. The switch fits the column at both widths.
- The row error under the switch wraps to the column's width, as it did before. Unchanged.
- No review agent ran. This is a small UI change, and the headless check covers each decision in the brief.
- Not done: a keyboard-only walk by hand. The scripted Tab and Space above cover the switch.
