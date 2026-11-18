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

## Evidence

## Story changes

## Rulings

## Merge danger
