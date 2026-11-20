# 308 design review: the Alerts popover (web push), critique and audit

Method: dual-agent critique (A: design review, B: detector), as two isolated sub-agents. The audit was done in this session from the code and the rendered page. Screenshots: headless Chrome over CDP of `php artisan serve` on 127.0.0.1, against a local sqlite file seeded with placeholder people (no real data). The first shots are in `before-fixes/`; the shots in this folder were taken after the fixes.

## Critique (A): 29/36 before fixes (Flexibility and efficiency: n/a, set once per device)
| # | Heuristic | Score |
|---|---|---|
| 1 | Visibility of system status | 3 |
| 2 | Match with the real world | 3 |
| 3 | User control and freedom | 3 |
| 4 | Consistency and standards | 3 |
| 5 | Error prevention | 3 |
| 6 | Recognition rather than recall | 3 |
| 7 | Flexibility and efficiency | n/a |
| 8 | Aesthetic and minimalist design | 4 |
| 9 | Error recovery | 2 |
| 10 | Help and documentation | 3 |

**Verdict.** The popover is authored for Likha. It extends the 300 popover without changing its look, writes the state as a sentence (brief §2), and never prompts on load. The weak points are the in-between moments and the touch size.

**Strengths.**
- A calm "on" state: green check, ink text, and a secondary Turn off.
- No permission prompt on load, and a quiet re-subscribe when the browser rotates the subscription.
- The notification collapses per chat with "3 new messages · …". This was proved with a real FCM push to headless Chrome.

## Detector (B)
- Source scan of the three blade files: exit 0, no findings. The source scan is regex-only, so B also scanned the rendered `/chat` page.
- Rendered `/chat`: exit 2, 3 warnings, all **inherited** and none in 308's lines:
  - the pulsing typing dots (300);
  - the recording dot (300);
  - the "New messages" pill's blue shadow (296).
- The first two show live state and are behind `prefers-reduced-motion`, so they are false positives. The third is offset and on a light page, so it is mostly a false positive and outside 308's scope.
- The live overlay could not be injected (B had no browser tool).

## Audit: 16/20 before fixes → 19/20 after
| # | Dimension | Before | After | Key finding |
|---|---|---|---|---|
| 1 | Accessibility | 2 | 4 | Focus fell to `<body>` when the state changed or a button was disabled. "Alerts on" was outside the live region. The blocked state was a dead end. |
| 2 | Performance | 4 | 4 | No fetch handler in the service worker and nothing cached. One `GET /chat/push` when the popover opens. |
| 3 | Theming | 4 | 4 | Only existing tokens (`--lc-online`, `--lc-danger`, `--lc-text`). The app has no dark mode. |
| 4 | Responsive | 3 | 4 | The new buttons were about 36 px high on phones. |
| 5 | Implementation integrity | 3 | 3 | Detector clean on 308's lines. The 3 inherited warnings are left as they are (outside scope). |

## Findings and what was done
| Sev | Finding (source) | Done |
|---|---|---|
| P1 | Focus is lost when opening the popover changes the state, or while the button is busy (A, audit) | **Fixed.** After the refresh, focus moves to the control now shown, if focus was in the popover. While busy, `aria-disabled` replaces `disabled`, so focus stays and the click guard holds. Checked: turn on → Turn off focused; refresh off→on → Turn off; turn off → Turn on alerts. |
| P1 | Buttons about 36 px high at 390 px; Sound switch 26 px (A, audit) | **Fixed.** On phones the alert buttons are 44 px (measured 44) and the switch row is 44 px, with the switch's touch area widened by a `::before`. |
| P2 | Screen readers did not hear "Alerts on"; "Turn off" names no object (A) | **Fixed.** The state line, the text and the iPhone steps sit in one `aria-live="polite"` region. Turn off has `aria-describedby="lc-push-status"` ("Alerts on for this device"). The visible label stays "Turn off", as the spec words it. |
| P2 | The blocked state says "try again" but has no button; "site settings" is vague (A) | **Fixed.** The text now gives the path: "Tap the icon left of the address, open Permissions or Notifications, choose Allow, then open this again." The popover re-checks the state each time it opens. |
| P2 | Dismissing the browser dialog went silently back to off (A) | **Fixed.** It now shows "Alerts were not turned on. Tap Turn on alerts, then Allow." |
| P3 | A failed refresh (offline) showed "off" on a device that has alerts on (A) | **Fixed.** The last state this device knew is kept. Offline errors say "No connection." |
| P3 | The iPhone "→" is read as "right arrow"; no closing period; the line in ink colour looked heavier (A) | **Fixed.** The arrow is `aria-hidden` with ", then" for screen readers. The line has a full stop and uses the popover's muted text colour. |
| Minor | Notifications and the badge are not cleared when the chat is read on another device (A) | **Left.** That needs a "read" push to every device, which is out of the spec's scope. The badge is reset by the next page poll or push. Noted in the result. |
| Minor | The popover title says "Alerts" but the trigger is "Alerts and sound" (A) | **Left.** This is 300's wording, unchanged by 308. |
| Minor | In a group, the "N new messages" count covers several senders while the title names the newest (A) | **Left on purpose.** It works like Messenger's thread notification. |
| Inherited | Detector: pulsing dots ×2, pill shadow (B) | **Left.** These come from 296/300, are not in 308's lines, and are mostly false positives. |
