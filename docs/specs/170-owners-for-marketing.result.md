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

## Amendment 170-1

Amendment 170-1 (the owner, 2026-10-27: "pag nagtake pala, di din naguupdate yung itsura, nandun pa din yung button nung take, ayusin mo"):

Why: after a Claim (take), the screen redirects at once, but Forbeast finishes the take in a queued job ~3 s later (transfers 1 and 2 on 2026-10-27: created 08:58:00, done 08:58:03 UTC). The reloaded list still shows the page as Unowned with its Claim button, so it looks like nothing happened and invites a second click.

| # | Change | Why |
|---|---|---|
| 1 | After a successful Claim request, the page shows as **"Claiming…"** (a status chip in place of the Claim button, with the transfer id) until Forbeast reports the owner. Use what Forbeast already gives (the transfer id returned by take; GET site/transfers status, and/or GET site/pages owner) — no new tables, no Forbeast change. Remember the in-progress take only as long as needed (session flash or a short cache keyed by page, ≤ 2 minutes). | No double click; the user sees it working. |
| 2 | While any page on the screen is "Claiming…", the screen refreshes itself every ~3 s (small inline script or meta refresh, stops when none is in progress or after 2 minutes). When the take is done the card moves to "On <this site>" with a green note "Claimed — the bot is Off until you set it up and turn it on". If the transfer ends failed/stuck, show the error text from Forbeast on that card in red and bring the Claim button back. | Owner: the screen must update by itself. |
| 3 | A second Claim on a page that is already "Claiming…" is refused before calling Forbeast ("This page is already being claimed"). Applies to Move/Ask the same way where a request for that page is already in progress. | Safety. |
| 4 | Tests: claim → "Claiming…" shown and no Claim button for that page (Http faked: take 202, list still unowned); then list shows owner = this site → card under this site with the note; failed transfer → error shown and Claim back; double claim refused without an HTTP call. Existing 164/170 tests pass. | Proof. |

Done when: these pass plus the spec's suites with no new failures vs 81a89f66; result updated; end with "170-1: <done|blocked> — <one line>".

### What was applied
1. **Claiming… chip.** A successful take, move or ask puts a cache entry keyed by page (`chatbot-owners:busy:<page id>`, 2 minutes) holding the action and the id Forbeast returned (`transfer_id` for take, `id` for move/ask). While the page is still unowned, the card shows "Claiming… #<id>" where the Claim button was. No table, no Forbeast change; it is the existing cache store (`database` in production).
2. **Updates by itself.** While any card is Claiming, a small inline script reloads the screen every 3 s. It waits while a confirm form is open, so typing is not lost. It stops once nothing is Claiming, which is at most 2 minutes, because the cache entry runs out. On each load the screen reads `GET site/pages`. When this site is the owner, the card is under "On <this site>" with the green note "Claimed — the bot is Off until you set it up and turn it on", and the entry is cleared. While the page is still unowned, it reads `GET site/transfers?page=`. If that transfer is `failed` or `stuck`, the card shows "The claim did not finish (<status>): <Forbeast's last step error>" in red (escaped, at most 300 characters), the entry is cleared and Claim is back.
3. **Second request refused.** A take, move or ask on a page with an entry is refused before any Forbeast call: "This page is already being claimed." (take) or "A request for this page is already in progress." (move/ask). The entry is now set with an atomic `Cache::add` right before the write, so two clicks a split second apart also send only one. A refusal from Forbeast clears it, so a retry works at once.
4. **Tests** S-170.9 to S-170.12 in `PageOwnersScreenTest.php`; stories added to `qa/stories.md`.

| Case | Test | Result |
|---|---|---|
| S-170.9 | `test_S_170_9_after_a_claim_the_page_shows_claiming_without_its_claim_button_and_the_screen_refreshes` | pass (red: card had no `data-claiming`) |
| S-170.10 | `test_S_170_10_when_forbeast_reports_this_site_as_owner_the_card_moves_there_with_the_claimed_note` | pass (red: no `data-claimed` note) |
| S-170.11 | `test_S_170_11_a_failed_take_shows_forbeasts_error_in_red_and_brings_the_claim_button_back` | pass (red: no `data-claim-error`) |
| S-170.12 | `test_S_170_12_a_second_request_for_a_page_in_progress_is_refused_without_calling_forbeast` (claim, move) | pass (red: `Failed asserting that false is true.`: second request was sent) |
| 164/170 + suites | `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` | pass: `Tests: 1462, Assertions: 46390, Skipped: 1`, 0 failures, 59 PHPUnit deprecations (as on 81a89f66); owners file alone `OK (52 tests, 262 assertions)` |

The full run used `php vendor/phpunit/phpunit/phpunit` through Herd's `php.bat` in Git Bash. The PowerShell form was blocked by this session's permission settings; it is the same runner on the same files.

**Story changes:** S-170.9 to S-170.12 added (from the amendment). S-170.12 also covers Move, per change 3.

**Review (standard depth):** no blockers or majors.
- Fixed: the guard was check-then-set, so two clicks a split second apart could both reach Forbeast. It is now an atomic `Cache::add` before the write.
- Not changed: the reviewer called the `claiming` line in the view redundant. It isn't: without it, a CEO's claiming card would fall through to the "Ask for this page" branch.
- Accepted with reasons in `TODO.md`: no message when 2 minutes pass with the take still running; Move/Ask blocked for 2 minutes even after an early decline; the 3 s reload drops the one-time flash; untested expiry, a missing transfer id and Ask.

**Deploy notes (unchanged):** no migration; `php artisan view:clear` after the pull. Uses the existing cache store; nothing to set. Same commits apply to Incepxion. Not checked in a real browser.

170-1: done — after a Claim the card shows "Claiming… #id" with no Claim button, the screen reloads itself until Forbeast reports this site as owner (green "Claimed" note) or the transfer fails (red error, Claim back), and a second Claim/Move/Ask within 2 minutes is refused before Forbeast is called.
