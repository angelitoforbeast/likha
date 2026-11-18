# 304 result: only the owner sees "The owner can read every conversation"

Base: live fd835bca. Branch `feat/304-owner-only-lock-line`.

## Plan

Base fd835bca: full suite once with `vendor/bin/phpunit` under Herd PHP (sqlite in memory, no .env) before any code change; base failures listed under Evidence.

OL-1, in `resources/views/chat/index.blade.php` only:
- Wrap both lock lines (the `<p>` in the empty pane `#lc-placeholder` and the `.lc-lock` bar under the thread header) in `@if ($boot['me']['ceo'])`. That flag is already set by the controller from `ChatAccess::isCeo($me)` (the same check as "All conversations"), so no controller change. Staff get no lock markup at all in the HTML; the CEO's HTML is unchanged. The `.lc-lock` CSS rule stays (harmless, used by the CEO).
- Tests first: staff get neither line nor the `lc-lock` element (on `/chat` and `/chat?c=`); the CEO gets both. Update `ConversationsTest::test_the_page_opens_for_any_signed_in_person_and_states_the_owner_can_read` and `CeoTest::test_every_thread_states_that_the_owner_can_read` to the new rule.

OL-2, reading the code first (ChatController, ChatMessengerController, ChatPresenter, ChatMessenger, ChatLive):
- Seen: `ChatPresenter::reads` lists only active member rows and `markRead` returns early without a member row, so the CEO can never appear. Expected already true.
- Typing: `POST /chat/{c}/typing` needs an active member (`postable`, 403 for the non-member CEO) and `ChatMessenger::typing` keeps only active members. Expected already true.
- Active dot: `active_at` is only computed for the conversation's members (thread) and the other member of a direct chat (list). Expected already true.
- Member row / joined line / system message: show, poll and read create nothing; only `join` does. Expected already true.
- Payloads: the members' `/chat/{c}`, `/chat/poll`, `/chat/conversations` and `/chat/unread?alerts=1` answers are compared before and after the CEO reads (and pings typing); his id and name must appear nowhere in them.
- Tests in `tests/Feature/Chat/OwnerReadsTraceTest.php` prove each case; any case that fails gets the smallest fix in the chat services/controllers and is listed in the result. Control case: where the CEO is a member, members see his Seen head (last_read_message_id) as before.

Order: plan commit; OL-1 tests (red), view change (green), commit; OL-2 tests, fix only if red, commit; full suite vs base; screenshots (local seeded sqlite, `artisan serve` on 127.0.0.1, headless browser) at 390 px (staff) and 1280 px (CEO) into `docs/design/304-owner-only-lock-line/`; result; push.

## What shipped

- **OL-1, the line is for the owner only.** In `resources/views/chat/index.blade.php` both lock lines are now inside `@if ($boot['me']['ceo'])`: the line in the empty pane ("The owner can read every conversation in Likha Chat.") and the `.lc-lock` bar under the thread header. `ChatController::index` sets `$boot['me']['ceo']` from `ChatAccess::isCeo($me)`, the same check that shows "All conversations". Staff get no lock markup at all in their HTML: it is not sent, not hidden. The CEO's markup for both lines is the same as before. Nothing else changed: 9 lines in one view, and no controller, service, route, migration or setting.
- **OL-2, the owner's reading leaves no trace.** All five cases were already true on base, so no code change was needed. Tests now pin them (below), and a mutation check showed that the tests catch a leak.
- **Changed rule (recorded as the spec asks).** Spec 296's decision "the page says so" (everyone sees that the owner can read every conversation) is now **"the owner's page says so"**: only the CEO sees the lock line, and the page no longer tells staff.

### What OL-2 found already true (and why)

| Case | Already true because | Test (`OwnerReadsTraceTest`) |
|---|---|---|
| No "Seen" head or "Seen by" for him | `ChatPresenter::reads` lists only active member rows. `ChatController::markRead` returns at once when there is no member row, so his reading writes no `last_read_message_id` anywhere | `test_members_see_no_seen_head_for_the_owner` (thread and poll) |
| No typing line from him | `POST /chat/{c}/typing` needs an active member (`postable`, so he gets 403), and `ChatMessenger::typing` also keeps only active members | `test_members_see_no_typing_line_or_active_dot_from_the_owner` |
| No active dot from him | `active_at` is only computed for the conversation's members (thread) and for the other member of a direct chat (list). He is neither | same test: he is not in the group's members list, and the direct chat's dot stays empty after he used Likha |
| No member row, "joined" line or system message | `show`, `poll`, `read` and the page create nothing. Only `POST /chat/{c}/join` (his explicit Join) does | `test_no_member_row_joined_line_or_system_message_is_created` (row counts before and after) |
| Members' payloads carry nothing about him | follows from the cases above | `test_the_members_payloads_carry_nothing_about_the_owner`: he opens, polls, marks read, tries to type, lists all conversations and reads alerts. A member's `/chat/conversations`, `/chat/{group}`, `/chat/{direct}`, both polls (with `since`), `/chat/unread` and `/chat/unread?alerts=1` are byte for byte the same before and after, and none contains his name or initials |
| Control: a conversation he IS a member of | unchanged | `test_where_the_owner_is_a_member_his_seen_head_shows_as_before` (his Seen head, with his name and last read id) |

Mutation check (not committed): letting a non-member call the typing route, and dropping the member filter in `ChatMessenger::typing`, turned 4 of the 5 OL-2 tests red. The code was then restored with `git checkout -- app`.

## Evidence

**1. Tests.**
- OL-1, `tests/Feature/Chat/OwnerOnlyLockTest.php` (new):
  - `test_staff_get_no_lock_line_in_the_empty_pane_or_the_thread_header` checks a Data Encoder and an OIC on `/chat` and `/chat?c=`: the HTML has no line text, no `<div class="lc-lock">` and no lock icon. It was red before the view change and green after.
  - `test_the_ceo_sees_the_line_in_both_places_as_before` checks the exact old markup of both lines on both URLs.
  - `test_nothing_else_on_the_page_changes` compares an OIC's and the CEO's chat markup (from `<div id="lc"` to the end of its script). They are identical once the lock lines, the CEO's "All conversations" filter, the boot JSON and the CSRF token are taken out (whitespace ignored).
- Tests that pinned the old rule, updated: `ConversationsTest::test_the_page_opens_for_any_signed_in_person_and_only_the_owner_sees_the_lock_line` (was `…_states_the_owner_can_read`) and `CeoTest::test_only_the_owner_sees_the_lock_line_on_a_thread` (was `test_every_thread_states_that_the_owner_can_read`). Both now check that staff do NOT see the line and the CEO does.
- OL-2, `tests/Feature/Chat/OwnerReadsTraceTest.php` (new, 5 tests, table above).
- `tests/Feature/Chat`: 133 tests, all green (125 on base, plus 3 for OL-1 and 5 for OL-2).

**Full suite against base** (`vendor/bin/phpunit`, Herd PHP 8.4, sqlite `:memory:`, no `.env`):

| Run | Tests | Failures | Errors | Skipped |
|---|---|---|---|---|
| Base fd835bca | 5848 | 22 | 5 | 6 |
| Branch (last code commit b93a8b34) | 5856 (+8 new chat tests) | 22 | 5 | 6 |

The failing set is the same 27 tests by name on both runs (a `diff` of the sorted names is empty), so there are **no new failures**:
- the spec's known failures: `ExampleTest`, `ItemTableFitScriptTest`, and `SharedPagesBasePinTest` (`/owner/private`);
- the Chatblast speed tests: `SpeedMemoryTest` (4), `SpeedPrewarmTest` (10), and also `ResponseSpeedTest` (10). The spec does not list `ResponseSpeedTest`, but it fails the same way on base and on the branch, in the same Speed area.

None of them is in chat. The base run overlapped with my first view edit; no chat test failed in it, so the edit did not distort the base numbers.

**2. Screenshots** in `docs/design/304-owner-only-lock-line/`:
- `390-staff-thread-no-lock.png`: Ana (Marketing, a member) in "Packing team" at 390 px. The header is followed directly by the messages, with no lock line.
- `1280-ceo-thread-with-lock.png`: the owner (not a member) on the same thread at 1280 px. It shows the lock bar under the header and "You are reading as the owner. Join to post." at the bottom. Under the last message there is only Ana's Seen head, none for him.
- The red "Server storage is at 93%" banner on the CEO shot comes from the storage banner reading this laptop's own disk. It is not part of this change.
- How they were taken:
  - Server: `php artisan serve` on 127.0.0.1:8304, against a local sqlite file in the session scratchpad.
  - Data: a throwaway test seeded the file with placeholder people (Owner Boss, Ana Cruz, Ben Reyes, Cara Santos). The chat tables came from the chat migrations themselves, as in 296 and 300.
  - Config: a temporary `.env` pointed only at that file while the server ran, and was deleted afterwards. It was needed because `artisan serve` does not pass DB variables from the shell to its PHP server.
  - Browser: headless Chrome over CDP.
  - Sign-in: done with curl, and the session cookie handed to Chrome. The login POST answers 500 on this small seed (a login listener reads `everyday_tasks`, which the seed lacks), but the session is still signed in.
  - None of this is in the repo, and no real database was touched.

**3.** What changed and what OL-2 found true are above; nothing needed a fix. Merge danger is below.

**4.** Pushed to `origin/feat/304-owner-only-lock-line`. Not merged, not deployed.

## Story changes

None. OL-1 and OL-2 shipped as written. OL-2's "fix it in the smallest way" was not needed.

## Rulings

1. **Which check counts as "the owner".** The view uses `$boot['me']['ceo']`, which is `ChatAccess::isCeo($me)` (role exactly `CEO`). It is the same value that already controls "All conversations" in this view, so no new helper was added.
2. **The `.lc-lock` CSS rule stays** in the page's `<style>` for everyone. It is a style rule with no text, and removing it for staff would need a second `@if` in the CSS for no gain. The staff test checks for the element (`<div class="lc-lock">`), the text and the lock icon, not for the class name in the CSS.
3. **The "nothing else changes" test uses an OIC**, not a Data Encoder. The "New group" part shows for the CEO and for an OIC, so it is the same on both sides. The only other CEO-only part, the "All conversations" filter, is taken out explicitly. The site menu around the chat differs by role anyway ("View nav as" for the CEO), so only the chat markup is compared.
4. **No code change for OL-2.** Every case was already true, and adding guards "just in case" would be a change the spec did not ask for. Tests pin the behavior instead.
5. **His global "active" state is unchanged.** When the CEO uses Likha he is still marked active. Members of a direct chat or group he IS in still see his dot there, as before (the same as his Seen head). In conversations he is not in, he never shows.

## Merge danger

- **What ships:** one view (`resources/views/chat/index.blade.php`, 2 `@if` blocks), the updated and new tests, docs and screenshots. There is no migration, route, config, setting, JS or CSS change, and no `npm run build` is needed.
- **Who sees a difference:** staff stop seeing the lock line on `/chat`, in both the empty pane and the thread header. The CEO sees no difference. No JSON payload changes.
- **Deploy note:** the deploy's usual `view:cache` / `optimize` step refreshes the compiled Blade view. Without it, the old compiled view would keep showing the line until views are cleared.
- **Conflicts:** only `chat/index.blade.php` (around lines 405–435) and two tests in `tests/Feature/Chat/`. Spec 303 (sequence lanes) touches none of these files.
- **Revert:** `git revert` the commit `feat(chat): show the lock line to the owner only (304 OL-1)`. That reverts the view and the new OL-1 test together. The two updated tests were changed in the earlier test commit (`test: 304 lock line is for the owner only`), so revert that commit too. The OL-2 tests can stay, since they pin base behavior. There is no data to undo.
