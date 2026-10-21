# Brief 071: the ACTIVE column on /chatbot is a real on/off switch

## Why

On `/chatbot`, ACTIVE is currently a button holding an "On"/"Off" chip (`activeCell()` in `resources/views/chatbot/index.blade.php`). The owner wants a sliding toggle switch:
- **Off:** the knob is on the left and the track is grey.
- **On:** the knob is on the right and the track is green.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| A toggle switch: track about 40×22 px, knob about 18 px. Grey `slate-300` when Off, `green-500` when On. The knob slides with a short CSS transition. Add a small "On"/"Off" text label next to it. | Owner's description. Color alone is not enough for accessibility. |
| Use `role="switch"` and `aria-checked`. The aria-label stays as now, e.g. "Switch on <page>". It is keyboard operable (Space/Enter) and has a visible focus ring. | Accessibility. |
| The behaviour stays the same: the confirm dialog, the POST to `chatbot.active`, the row error message, and CEO-only (non-CEO users see the same switch, disabled and not clickable). While the request runs, the switch is disabled and shows a "busy" state (dimmed). It shows the server's answer afterwards: when Botcake fails, it snaps back. | Nothing else changes. |
| The filter "Bot = On/Off" keeps working. Phone width: the switch must fit the sticky ACTIVE column. | — |

## Done when

- The switch looks right Off and On at 1366 and 390 px. Do a headless check like spec 068's, with screenshots in `docs/specs/071-chatbot-switch/`.
- The 068 tests still pass: `php artisan test tests/Feature/Chatblast`.
- Report the commits and the screenshots.
