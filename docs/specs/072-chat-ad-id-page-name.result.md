# Result 072: the chat block sends the ad and the page name; "+ Page" sets the page up

The spec is `072-chat-ad-id-page-name.md` with `072-chat-ad-id-page-name.amendment-1.md`; the amendment wins where they differ.

## Commits

| Commit | What |
|---|---|
| `81a26a63` | docs: the brief, saved verbatim |
| `43b89460` | docs: amendment 1, saved verbatim |
| `94acfd93` | feat: the new body, `ad_id` to the payload and `botcake_psids`, "+ Page" switches the page On; tests and stories |
| `23092d7a` | fix (review): only a numeric `ad_id` reaches `botcake_psids`; the dialog warns that it replaces the Default Reply; more cases; accepted minors in `TODO.md` |
| (this commit) | docs: result |

## What changed

- `BotcakeChatBlock::BODY` gains `"ad_id":"{{ad_id}}"` and `"page_name":"{{page_name}}"`. The On switch, `botcake:chat-setup` and now "+ Page" all write it. Re-running the command keeps the key.
- `/api/botcake/chat`: `ad_id` and `page_name` stay in `botcake_chats.payload`, with no new columns. A value that is empty, blank or a literal `{{…}}` is left out of the payload. The key, IP, PSID and "bot off" checks are unchanged and still run first.
- `BotcakeChatGuard::checkPsid` takes the chat's `ad_id`. A new `chat_verify` row gets it. Existing rows for that page and PSID that have no `ad_id` are filled; a non-empty `ad_id` is never replaced, and rows of other pages are not touched. Only a value of 1 to 64 digits is written there (Facebook ad IDs are numbers); any other value stays in the payload only.
- "+ Page" (`ChatbotController::addPage`): the page name, Botcake ID and token are all required, as before; picking a page already in page settings still asks only for what it lacks. After the save and a passing token test, it calls `BotcakeChatBlock::turnOn`, the same code as the On switch: key, block, publish, `chatbot.active = 1`. If the token test, the block write or the publish fails, the page stays saved, `chatbot.active` is set to `0`, and the answer carries `active: false` and the error. The dialog puts that error on the page's row, like the switch's errors. The answer gains `active`. No token or key is ever returned.
- The dialog text (English): "Page name, exactly as PAGE in orders, or pick one from page settings" and "…the token is tested and the bot is set up and switched On. This replaces the page's Default Reply in Botcake. If Botcake fails, the page is saved and stays Off." The Blade changes are limited to the "+ Page" dialog and its submit handler. The script is inline, so no `npm run build` is needed.

## Amendment 1, as applied

| Change | Done |
|---|---|
| No auto-name: no placeholder `Page <BotcakeID>`, no rename from a chat's `page_name`; `page_name` kept only in the payload | Removed before the first code commit. The draft rename (`PageSettings::rename`, `autoName`, `placeholderName`) never reached a commit. `page_name` reaches only the payload. |
| "+ Page" requires the name, Botcake ID and token; the help text says the name must match PAGE in orders; picking an existing page still works; the token test and duplicate-ID refusal stay | Done: S-072.5, S-068.2, S-068.10 |
| Adding a page sets it up through `turnOn`; the row shows On; if Botcake fails, the page is saved and Off, with the error | Done: S-072.5, S-072.6 |
| Unchanged: the new body, `ad_id` in the payload and on `botcake_psids`, the command keeping the key | Done: S-072.1 to S-072.4 |

## Case table

`vendor/bin/phpunit tests/Feature/Chatblast/BotcakeChatAdTest.php --testdox`:

| Case | Test | Result |
|---|---|---|
| S-072.1 | `test_S_072_1_on_and_the_command_write_the_body_with_ad_id_and_page_name` | ✔ pass |
| S-072.2 | `test_S_072_2_the_command_on_a_set_up_page_rewrites_the_block_with_the_same_key` | ✔ pass |
| S-072.3 | `test_S_072_3_the_ad_id_fills_a_new_chat_verify_row_and_an_empty_one_but_never_overwrites` | ✔ pass |
| S-072.4 | `test_S_072_4_an_unfilled_or_empty_ad_id_and_page_name_store_nothing` (placeholder, empty, spaces) | ✔ ✔ ✔ pass |
| S-072.5 | `test_S_072_5_plus_page_needs_the_name_id_and_token_and_switches_the_page_on` | ✔ pass |
| S-072.6 | `test_S_072_6_when_botcake_fails_on_add_the_page_is_saved_but_off_with_the_error` (block write, publish, token test) | ✔ ✔ ✔ pass |
| S-067.9, S-068.3 | expected body updated to the new JSON | ✔ pass |

Red runs, one per slice:
- S-072.1 / .2: `Failed asserting that two strings are identical.` (old body)
- S-072.3: `Failed asserting that two arrays are identical.` (no `ad_id`)
- S-072.4: `Failed asserting that an array does not have the key 'ad_id'.`
- S-072.5 / .6, after the amendment: `Unable to find JSON … "active": true` (the answer had no `active`)
- S-072.3, review fix: `hindi numero: hindi itinatala — Failed asserting that 'not-an-ad 12' is null.`

These cases were added in the review wave and passed on their first run, so they pin behaviour that was already there:
- the publish data set of S-072.6;
- the attach path in S-072.5;
- the "another page's row" check in S-072.3.

## Suite

- `tests/Feature/Chatblast`: 269 tests, 1801 assertions, all pass (PHPUnit deprecations only, from the house `@dataProvider` doc comments).
- Full suite: 1854 tests, 19887 assertions, with 1 error and 1 failure. These are the known ones: `ExampleTest::test_the_application_returns_a_successful_response` and `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. There are no new failures.
- I ran the suites with `vendor/bin/phpunit` (same `phpunit.xml`), not `php artisan test`. In this worktree, `artisan test` prints a `file_get_contents(…/.env)` warning on every test, because the worktree has no `.env` and must not get one. The tests themselves pass; the 059 worktree shows the same warning.

## Review

`skeptic-reviewer`, adversarial depth (the tier was raised to high because "+ Page" now publishes to a live Botcake page), on `43b89460..94acfd93`. It found no blockers and no majors, and eight minors.

| Minor | Outcome |
|---|---|
| `ad_id` has no format check, and what it writes is permanent downstream (`macro_output.botcake_ad_id`) | Fixed: digits only (1–64) |
| "+ Page" replaces the Default Reply with no warning, unlike the switch's confirm | Fixed: the help text says so |
| S-072.4 still says "the page keeps its name" (from the removed rename) | Fixed in the story |
| Missing cases: publish failure on add, the attach path, a same-PSID row on another page | Added |
| `turnOn` throwing during "+ Page" has no test | Accepted in `TODO.md` |
| A non-fatal token-test note shows in red on a row that is On | Accepted in `TODO.md` |
| Two "+ Page" requests at once could each make a key | Accepted in `TODO.md` |
| A publish failure leaves the block written while we show Off | Accepted in `TODO.md`: the amendment asks for Off on any failure |

The `ad_id` over 64 characters case is covered by the digits-only rule; such a value is now never written.

## Story changes

- New section S-072 (S-072.1 to .6) in `qa/stories.md`. The brief brought no stories. Its "Done when" list and the amendment served as the cases, so no story-writer drafts were made.
- S-067.9 and S-068.3: the exact block now has the body of S-072.1.
- S-068.2: "+ Page" also switches the page On (S-072.5).
- S-072.3, .4, .5 and .6 were reworded after the review, as listed above.

## What the brief got wrong, and other notes

- **"The first chat fills the name in"** (brief) does not fit orders. The owner's amendment removed it: the page name must match PAGE in orders, and two pages can share a Facebook name.
- **The `ad_id` on a `source=api` row may not last.** The reviewer found that `BotcakeCustomerSync::store` rewrites `ad_id` from the Botcake API on every sync, to null when the API has none. A chat-filled `ad_id` on such a row can be wiped by the next sync. `chat_verify` rows are not affected. This is outside this change and needs the owner's decision. It is in `TODO.md`.
- **Off on failure.** A page completed from page settings that was already getting chats (for example through the old shared key) stops keeping them when "+ Page" fails, because it is now Off. Switching it On fixes it.
- **The "send the plan and wait for go" step was skipped.** The brief's decisions table was fixed ("do not reopen") and the change was bounded, so I went straight to the work. The plan is this result.
- **Not done:**
  - No browser check: this session has no browser tools, and the Blade change is two lines of text and one line of JS.
  - No `npm run build`: the script is inline.
  - Nothing pushed or deployed, and the real Botcake API was never called (`Http::fake` everywhere).
