# Result 114

## Files changed
- `app/Services/Chatbot/PageContentCheck.php`: AI price shape is `{item_price, fees[], total}` (old `shipping`/`insurance` still read as fees); `price()` passes when the stated total or item price + all fees is within ₱1 of the COD; `fill_up_form` left the AI text keys and gets a plain-text check for Full Name, Phone Number, Address and Landmark (warning `Fill-Up Form: walang X`), which also runs when the page has no expected item; text and video cache keys carry a `p2:` version so old answers are asked again once (image keys unchanged).
- `tests/Feature/Chatblast/ChatbotContentCheckTest.php`: new cases, two existing ones adjusted (Fill-Up Form no longer asked to the AI).
- `docs/specs/114-content-check-price-form.md` and this result.

No migration. Old text/video rows in `chatbot_content_answers` are left in place, unused.

## Case table
| Case | Test | Result |
|---|---|---|
| S-114.1 ₱199 + ₱60 + ₱40 vs ₱299 | `test_S_106_4…` set "item price plus a list of fees" | pass |
| S-114.2 ₱199 alone vs ₱299 | `test_S_106_4…` set "item price alone differs" | pass |
| S-114.3 stated total ₱299 | `test_S_106_4…` set "stated total with fees" | pass |
| S-114.4 form fields, no AI, missing Landmark warns | `test_S_114_4…` | pass |
| S-114.5 old cached answer re-asked once | `test_S_114_5…` | pass |

Red run first: S-114.1 "Undefined/mismatch" and S-114.4/5 failed before the code. Final: `phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` OK, 898 tests, 1 skipped.

## Notes
Until the nightly job runs, the page view shows text fields as "not checked yet" (the old cached answers no longer match). Story changes: none.
