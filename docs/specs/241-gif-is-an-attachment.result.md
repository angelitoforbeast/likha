# Result 241: a GIF from Messenger is an attachment, not text

## Plan

One task, risk tier low, side backend (spec tier kept; no reason to raise it: no new route or input surface, one more shape on the existing classification). Low tier: no skeptic review; the main session runs lint and the tests.

**Where the code is today**

- `ChatAttachments::type()` decides "attachment only" for every caller (`ignore()` in `ChatbotInbound::afterStore`, plus `ChatEchoes` and `ForbeastWatch` orphan checks). It knows `[Photo]` and the bracket markers only.
- `ChatMessageCleaner::clean()` turns a bare `fbcdn.net` link into `[Photo]`; `cdn.fbsbx.com` is not `fbcdn.net`, so the GIF link stays as raw text.
- `BotcakeChatController` writes `payload.attachment` from the cleaner's image link or `ChatAttachments::fromMarker()`; a bare GIF link gets none.

**Change (smallest)**

1. `ChatAttachments`: add `gif` to `IGNORED`; add `gif(string $message): ?array` returning `['type' => 'gif', 'url' => <link capped at 2000>]` only when the whole trimmed message is one `http(s)` URL, host exactly `cdn.fbsbx.com` (case-insensitive), a path segment equal to `t59.2708-21`, and a path ending in `.gif` (case-insensitive; query string and fragment allowed). `type()` returns `gif` when `gif()` matches, before the marker check.
2. `BotcakeChatController`: `payload.attachment` falls back to `ChatAttachments::gif($f['message'])` after `fromMarker()`. The message itself is stored unchanged (the link), as today.
3. `fromMarker()` and `ForbeastWatch::kindOf()` (twin pairing) are not changed, so pairing behaviour stays as today.

Side effect, intended and the same as for a photo: with `ignore`, `ChatEchoes` and the `ForbeastWatch` orphan count also skip a bare GIF chat (they use `type()`). With `reply`, nothing changes anywhere.

**Stories and cases** (added to `qa/stories.md` as Slice 241; tests named by case id)

| Case | Type | Given / When / Then | Test (planned) |
|---|---|---|---|
| S-241.1.1 | happy | Given `ignore`, when Botcake pushes a bare `https://cdn.fbsbx.com/v/t59.2708-21/…_n.gif?…` link, then no flow run is dispatched, one `ignored` event `{attachment: gif}`, `_ignored = gif`, `payload.attachment = {type: gif, url: <link>}`, no reply | `ChatbotAttachmentsTest` data-provider row `gif` in `test_S_149_28_…` plus `test_S_241_1_1_a_messenger_gif_is_ignored_like_a_photo` (feature) |
| S-241.1.2 | negative | Given `reply`, when the same link arrives, then the flow runs once and there is no `ignored` event | `test_S_241_1_2_with_reply_the_gif_runs_the_flow_as_today` (feature) |
| S-241.1.3 | edge | `type()` is `gif` for the link with a query string, with `.GIF`, with `http://`, with an upper-case host | `tests/Unit/ChatAttachmentsGifTest::test_S_241_1_3_…` (table) |
| S-241.2.1 | negative | `type()` is null for `.gif` on `media.giphy.com` and `example.com`; feature: such a chat runs the flow | unit table + feature `test_S_241_2_text_and_other_links_still_run_the_flow` |
| S-241.2.2 | negative | `type()` is null for a `cdn.fbsbx.com` `.mp4`/`.png` in `t59.2708-21`, and a `.gif` in another segment (`t59.3654-21`, `t59.2708-210`) | unit table |
| S-241.2.3 | negative | `type()` is null for `ito po https://cdn.fbsbx.com/…gif` and for the link followed by words; feature: it runs the flow | unit table + feature (same test as 2.1) |
| S-241.2.4 | edge | `[Photo]` → photo, sticker `/t39.1997-6/` → null, Like → null, `[audio](…)` → audio, `[photo] ito` → null: unchanged | unit table (existing S-149.28/29 feature tests stay green) |

The unit test is a plain `PHPUnit\Framework\TestCase` on `type()` (no DB; `type()` reads no setting). `ignore()` writes to the DB, so its cases (event, mark) are the feature tests in `tests/Feature/Chatblast/ChatbotAttachmentsTest.php`, through the real `/api/botcake/chat` route.

**Order:** Red first (unit table and S-241.1.1 feature fail on `null`/run count), then the change, then the tests above green, then `php -l` on the changed files, then `tests/Feature/Chatblast`, `tests/Unit` and the full suite compared with the same runs on `3f5c381b`. One `fix:` commit for code and tests together, then the result. Push the branch to `origin`.

**Not changed:** the flow's phone condition, the gateway (Forbeast) path, `ChatMessageCleaner`, migrations, `.env.example`.

Plan approved as written ("go"), including the side effect on `ChatEchoes` and the `ForbeastWatch` orphan count.

## Done-when evidence

PHP runs as Herd's `php.bat`. Every test in this worktree prints a dotenv warning under `artisan test` (`file_get_contents(…/.env): Failed to open stream`): the worktree has no env file. That is environmental, on every test before and after the change, and the assertions run and pass (plain `vendor/bin/phpunit` reports `OK` for the same tests). The env file was not created or touched.

**Red run** (tests written, code not yet changed): `php artisan test tests/Unit/ChatAttachmentsGifTest.php tests/Feature/Chatblast/ChatbotAttachmentsTest.php`

```
⨯ s 241 1 3 a messenger gif link is a gif with a query upper case or http   → Failed asserting that null is identical to 'gif'.
⨯ s 149 28 … with data set "messenger gif"                                  → Failed asserting that 1 is identical to 0.   (the GIF ran the flow)
⨯ s 241 2 text and other links still run the flow while the gif does not    → Failed asserting that two arrays are identical.
Tests: 3 failed, 17 warnings, 1 passed (109 assertions)
```

S-241.1.2 and the S-241.2 unit table passed before the change, as they should: they pin today's behaviour.

**1. Every case of S1 and S2** (after the change, from the full run's JUnit log, all passing):

| Case | Test | Result |
|---|---|---|
| S-241.1.1 | `ChatbotAttachmentsTest::test_S_149_28_an_attachment_only_message_is_ignored_logged_and_kept_for_later` with data set "messenger gif" (no run, one `ignored` event `gif`, `_ignored = gif`, `payload.attachment = {gif, <link>}`, no reply) | pass |
| S-241.1.2 | `ChatbotAttachmentsTest::test_S_241_1_2_with_reply_the_gif_runs_the_flow_as_today` | pass |
| S-241.1.3 | `ChatAttachmentsGifTest::test_S_241_1_3_a_messenger_gif_link_is_a_gif_with_a_query_upper_case_or_http` (query, `.GIF`, `http://`, upper-case host, spaces; link capped at 2000) | pass |
| S-241.2.1–2.4 (type) | `ChatAttachmentsGifTest::test_S_241_2_other_links_text_and_existing_markers_keep_todays_type` (giphy, example.com, look-alike host, fbsbx mp4/png, other segment, `t59.2708-210`, `.gif` only in query, words before/after, `[Photo]`, sticker, Like, `[audio](…)`, `[photo] ito`, plain digits) | pass |
| S-241.2.1, 2.2, 2.3 (flow) | `ChatbotAttachmentsTest::test_S_241_2_text_and_other_links_still_run_the_flow_while_the_gif_does_not` (GIF + 4 non-GIF chats: 4 runs, ignored events `['gif']`) | pass |
| S-241.2.4 (flow) | existing `test_S_149_28_*` (10 data sets), `test_S_149_29_stickers_likes_and_a_photo_with_text_are_answered_as_today`, `test_S_149_30_*`, `test_S_149_31_*` | pass |

Lint: `php -l` on `app/Services/Chatbot/ChatAttachments.php`, `app/Http/Controllers/BotcakeChatController.php`, `tests/Unit/ChatAttachmentsGifTest.php`, `tests/Feature/Chatblast/ChatbotAttachmentsTest.php` → `No syntax errors detected` (all four).

**2. No new failures compared with `3f5c381b`.** Full suite in one run: `vendor/bin/phpunit -c phpunit.xml --log-junit …` on commit `6c560645`:

```
Tests: 4675, Assertions: 78091, Failures: 2, PHPUnit Deprecations: 99, Skipped: 6.
1) Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response
   Expected response status code [200] but received 302.
2) Tests\Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order
   Failed asserting that actual size 57 matches expected size 45.
```

- Folder `tests/Feature/Chatblast` (the chatbot tests): 2088 test cases in the log, 0 failures, 0 errors.
- Folder `tests/Unit`: 110 tests, 0 failures, 0 errors.
- Both failures are outside the changed area and fail the same way at `3f5c381b`. They were run with the two changed app files checked out at `3f5c381b` (`git checkout 3f5c381b -- <the two files>`, then restored with `git checkout HEAD -- app`):
  `tests/Feature/ExampleTest.php` → `Tests: 1, Assertions: 1, Failures: 1`; `ItemTableFitScriptTest --filter catalog_column_id` → `Tests: 1, Assertions: 2, Failures: 1`. These are the two known failures listed in earlier results.

**3.** `git diff 3f5c381b -- database .env.example | wc -c` → `0` (empty).

**4.** `git merge-base --is-ancestor 3f5c381b HEAD` → true; branch `fix/241-gif-attachment` pushed to `origin` (head commit below).

## Story changes

None to the spec's stories. They were added to `qa/stories.md` as Slice 241 with case ids S-241.1.1–1.3 and S-241.2.1–2.4. Three edge rows were added inside existing cases because the code made them a real question, with no change to any Then:
- S-241.1.3: an upper-case host and spaces around the link are still a GIF. The whole message is trimmed, as for the markers.
- S-241.2.1: a look-alike host `cdn.fbsbx.com.example.com` is text, so the host must be exact.
- S-241.2.2: `t59.2708-210` (a segment that only starts with `t59.2708-21`) and `.gif` only in the query are text, so the match is the whole path segment and the file name's extension.

## Files changed

- `app/Services/Chatbot/ChatAttachments.php`: `gif` added to `IGNORED`; new pattern `GIF` (whole message is an `http(s)` link, host `cdn.fbsbx.com`, a path segment `t59.2708-21`, a file ending `.gif`, optional query/fragment, case-insensitive); new `gif()` returning `{type: gif, url}` (capped at 2000); `type()` returns `gif` before the marker check.
- `app/Http/Controllers/BotcakeChatController.php`: `payload.attachment` falls back to `ChatAttachments::gif()` after `fromMarker()` (one line).
- `tests/Unit/ChatAttachmentsGifTest.php` (new), `tests/Feature/Chatblast/ChatbotAttachmentsTest.php` (one data set, two tests).
- `qa/stories.md` (Slice 241), this result, the spec.

Not changed: `fromMarker()` and `ForbeastWatch::kindOf()` (twin pairing), `ChatMessageCleaner`, the gateway path, the flow, migrations, `.env.example`.

Workflow note: the tier is low, so there was no skeptic review, as the kit says for low. The main session made the change directly instead of handing it to a separate developer agent, because it is one bounded slice. Browser check skipped: no screen changed. The Inbox and Health show "gif" through the existing "ignored" text.

## Head commit

The head of `fix/241-gif-attachment` on `origin` is the commit "docs: result and case table of the GIF as an attachment (spec 241)", the child of the fix commit `6c560645`. A commit cannot hold its own hash, so its hash goes to the reviewer with the report.

## For the reviewer before release

- With `chatbot_attachments = ignore`, a bare Messenger GIF now gets no bot answer at all, the same as a photo. If a customer sends only a GIF in the middle of a flow, the flow waits for their next text, as it does for a photo.
- The live flow's phone condition is unchanged (out of scope). A typed message that contains digits and a link still goes to it.
- Twin pairing (`ForbeastWatch::kindOf`) still treats the Botcake GIF copy as text. A page that gets both Botcake standby and Forbeast copies pairs GIFs the same way as before this change. Not in scope; noted only.
- After deploy, the "Ignored" count on Health goes up by the GIF volume (the spec counted 118 bare GIF links in 7 days). That is expected, not a fault.
- No migration and no setting to run.
