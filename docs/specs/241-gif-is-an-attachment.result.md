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

## Done-when evidence

## Story changes

## Files changed

## Head commit

## For the reviewer before release
