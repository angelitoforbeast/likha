# Result 120: Check column checks the FLOW; Changes shows cleared values and only real auto-switches

## What shipped
- `ChatbotPageCheck`: for a bot that is On, no flow → warning "No flow: the bot only receives chats" (status stays `none`); a flow id that is missing or not published → error "Flow not found / not published"; a main flow that is a sequence step → error `Flow "X" is a sequence step, not a main flow`. Bot Off: no FLOW rule.
- Changes (`ChatbotController::changes`): a cleared value (inactive row, `replaced_at` set, no newer row for the page/provider/key) is an entry "<old> → (none)" at `replaced_at`, by the user who cleared it. Cleared entries and writes are merged by time; filters (page, field, user, dates) and the total cover both.
- "auto-switch" only for the `auto_switch` event rows, and for a provider row with no user that has an `auto_switch` event of the same page within a minute (the switch writes both together); any other system provider row is "system".

## Migration
`2026_11_03_100000_add_replaced_by_to_page_settings.php`: nullable `replaced_by` on `page_settings` (guarded with `hasTable`/`hasColumn`). Run `php artisan migrate --force` by hand. `PageSettings::deactivate` writes it when a user is given (and the column exists); rows cleared before the migration show "system" as who.

## Files
app/Services/Chatbot/ChatbotPageCheck.php, app/Http/Controllers/ChatbotController.php, app/Services/PageSettings.php, app/Models/PageSetting.php, the migration; tests in ChatbotPageCheckTest (S-120.1), ChatbotPageHistoryTest (S-120.2), ForbeastAutoSwitchTest (S-120.3), plus the migration added to BotcakeApiTestCase.

## Tests
`phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: OK, 939 tests, 1 skipped (existing). Red runs before the code: all three S-120 tests failed on the missing behaviour (empty warnings; no "(none)" entry; old row labelled "auto-switch").

## Suggestions (not done)
Pre-migration clears have no "who"; the frontend needs no change (it renders `(none)` as the new value).

## Amendment 1: wrong kind of value
- Check column (`ChatbotPageCheck`): for every botfield with a value, including the optional Seq 11-20 and After Order fields, a text/long column (kind not `image`/`flow`) whose value is only one http(s) link gives `Seq 11 is an image link: put it in Seq Img 11` (the matching `seq_img_N` / `after_order_img_N` label when that column exists), else `<Label> is a link: this column needs text`. An image column whose value is not a link at all gives `Seq Img 11 is not an image link`; a non-http link (ftp) keeps the old "link not allowed". Kinds come from `ChatbotFields::kind`; no names are hard-coded except the Seq/After Order to image pairing. A link inside a sentence is not reported; empty fields are not reported.
- Grid: `public/js/chatbot-paste.js` gets `isLinkOnly` and `linkWarning`. The page edit panel and the long-text editor show the same message inline in amber (saving still allowed); paste counts such values in its notice (they are still pasted).
- Tests: S-120.4 (ChatbotPageCheckTest, red first: only "link not allowed" was reported), S-120.5 (ChatbotPasteScriptTest, node). `phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: OK, 940 tests, 1 skipped (existing). Ran `npm run build`; the inline warning was not checked in a browser.
