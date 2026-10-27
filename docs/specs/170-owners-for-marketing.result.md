# Result 170: marketing claims available pages from the Owners screen

## What shipped
- **Who opens Owners** (`ChatbotOwnersController::opens()`, used by the controller and by the Chatbot nav): CEO, Marketing and Marketing - OIC. The role is read as Connections reads it: `employeeProfile->role`, then `users.role`, with spaces squashed. Every other role gets 403 and no Owners link, as before. The route lost `EnsureCeo`. The controller checks the role first in `index` and in `act`, before any password check or Forbeast call.
- **Marketing does take only.** A POST to move, ask, accept, decline or cancel from marketing returns 403, and nothing is sent. Take works as it does for the CEO. It checks the user's own password (`Hash::check`) and the phrase `ForbeastSite::phrase('TAKE', …)` (unchanged), and the page is read again from Forbeast. The actor sent to Forbeast is the marketing user's name and email. The rate limit is unchanged (10 a minute per user).
- **What marketing sees:** only Unowned pages and this site's pages, without any pending requests. Pages owned by the other site, every request with its names, and the move and ask buttons are not in the HTML. `?history=` works for marketing only on a page they can see (strict id match). For any other page, nothing is asked of Forbeast. The CEO sees everything as before.
- **Layout (everyone):** one page per row (the two-column grid is gone), in groups: "Available to claim" (unowned) at the top, then "On <this site>", then, for the CEO only, "On <other site>". Each group has a count and is sorted by name. An empty group says so, e.g. "No pages to claim right now." The take button now reads **Claim**. The note under the title is shorter for marketing.
- **Autofill fix** (`partials/owners-confirm`):
  - a hidden `autocomplete="username"` field holds the signed-in user's email, before the password;
  - the phrase box now comes after the password and is named `confirm_text`, with autocomplete, autocapitalize and autocorrect off and password-manager ignore hints;
  - the server reads `confirm_text` and falls back to `phrase` for confirm forms already open at deploy.
- **Opening a confirm form** scrolls the whole form into view inside the scrolling list and puts the cursor in the password field (a small script on the Owners screen).
- Forbeast, the transfer contract, sending, flows and sequences are unchanged. No new tables.

## Case table
| Case | Test | Result |
|---|---|---|
| S-170.1 | `test_S_170_1_marketing_sees_the_owners_link_and_the_screen` (Marketing, Marketing - OIC, extra spaces); `test_S_164_1_other_roles_get_403_and_no_owners_tab` (now with Encoder) | pass (red before the code: `Expected response status code [200] but received 403.`) |
| S-170.2 | `test_S_170_2_marketing_sees_only_unowned_and_this_sites_pages_and_no_requests`; CEO: `test_S_164_1_the_ceo_sees_every_page…` unchanged | pass (red: 403; the `?history=01001` row, added in review, was red with the loose match) |
| S-170.3 | `test_S_170_3_marketing_claims_with_their_own_password_and_the_phrase` (claim, wrong password, wrong phrase, a page this site owns, a page the other site owns) | pass (red: `received 403`) |
| S-170.4 | `test_S_170_4_marketing_cannot_move_ask_accept_decline_or_cancel` (5 actions) | pass (already 403 under `EnsureCeo` before the change; the test keeps it true now that the route lets marketing in) |
| S-170.5 | `test_S_170_5_available_pages_come_first_then_this_site_then_the_other_site_one_per_row`, `test_S_170_5_an_empty_available_group_says_there_is_nothing_to_claim` | pass (red: `Failed asserting that an array does not contain false.`: no groups) |
| S-170.6 | `test_S_170_6_the_confirm_form_has_a_hidden_username_then_the_password_then_the_phrase`; posting `confirm_text` is checked in S-170.3 | pass (red: 403; the autocapitalize check, added in review, was red with `autocapitalize="characters"`) |
| S-170.7 | `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` (includes the owners tests) | pass: this branch `Tests: 1457, Assertions: 46359, Skipped: 1`, 0 failures (59 PHPUnit deprecations, the same as on 81a89f66). The baseline run on 81a89f66 ran while the tests were being edited, so its single failure (in `PageOwnersScreenTest`) is not a real baseline; every other test passed there (`Tests: 1440`, 1 failure) |
| S-170.8 (P2) | 390 px: not run in a browser (this session has no browser on a running app with Forbeast data). By markup: one card per row, `min-w-0` cards, `w-full` inputs and confirm form on phones (`sm:w-96` from 640 px up), no tables or grids, wrapping names (`break-words`) | not run |

## Story changes
- S-170.3 now also covers marketing trying to take a page this site or the other site owns: refused, nothing sent. The reviewer pointed out that no test covered it.
- S-170.6 also requires autocapitalize and autocorrect off on the phrase box: the phrase is case-sensitive.
- S-164.1 "other roles" now uses Encoder instead of Marketing, because Marketing is allowed now.

## Conflict note
The brief named the two people whose pages were the first real takes. Specs must not name people, so the saved spec leaves the names out. Everything else is verbatim.

## Review
Skeptic reviewer: adversarial depth on the permission change, standard depth on the rest. It found no blockers and no way for marketing to reach the CEO-only actions or the other site's data.
- **1 major, fixed** (cacc897d): `autocapitalize="characters"` on the phrase box. On a phone the keyboard typed `TAKE PAGE TRES`, but the page name in the phrase keeps its case, so the claim failed. It is now `off`, with `autocorrect="off"`.
- **Minors fixed:**
  - the history filter used a loose `contains`, so `01001` matched `1001`; it is now a strict match;
  - the role was read differently from Connections; it is now the same;
  - the missing test for taking an owned page was added.
- **Accepted, with reasons in `TODO.md`:** `confirm_text` wins over `phrase`; real-browser autofill was not checked; the throttle now runs before the role check.

## Deploy notes
- No migration. After the pull, run `php artisan view:clear` (and `route:clear` / `route:cache` if routes are cached: `routes/web.php` changed). No new environment values.
- The same commits apply to Incepxion (same code; `ForbeastSite::self()` picks the site from `APP_URL`, so the groups read "On Incepxion" / "On Likha" there).
- Marketing users need the Chatbot menu (`/chatblast` in nav settings) to see the Owners link, as for every Chatbot screen. The screen itself checks only the role.

170: done — Marketing and Marketing - OIC can claim unowned pages from /chatbot/owners (take only, own password + phrase) and see only free and this site's pages; the screen is grouped one per row with Claim on top; the phrase box no longer gets autofilled.
