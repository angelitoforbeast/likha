# Result 271: A page needs a working shipping-status setup to be added, and a page with ads or orders but no setup is urgent

Branch `feat/271-shipping-setup-required` from `6ff1e4a1` (live). Risk tier kept: medium. No migration, table, route or access change.

## S1 "+ Page" requires the shipping setup (with amendments 2 and 3)

Chosen option: **not added, nothing left behind.** The token and ID have to be saved before the check can run (every Botcake read in `BotcakePageSetup` / `ChatblastSetupCheck` / `BotcakeFlowNames` reads them from page settings). So "+ Page" remembers the last `page_settings.id` and the page's active rows, saves the ID and token, runs `BotcakePageSetup::check` (the token test, then `ChatblastSetupCheck::run`), and when the page is refused it undoes exactly that attempt (`undoAdd`): every active row of the page written by the attempt (`id > last id`: ID, token, token status, check result, options) is deactivated, and every row that was active before and got replaced is active again. A brand-new page leaves no active rows (it is not on the list and not in the page-settings datalist); a page picked from page settings is back to how it was (same active rows, same `setup.check`). "Saved but Off and not listed" was not possible without changing what the list means (a page with a Botcake ID and token is listed), which would have hidden existing pages (S2).

The page is added only when the token test passes AND the setup check is ok. Then, as before, the bot is set up and switched On; if only the On fails (block write, publish), the page is added and stays Off with that error.

Refusals are HTTP 422 with `retry: true` and `token_test`:

| Case | `error` | `missing` |
|---|---|---|
| Setup not complete (missing or duplicated) | `The shipping status setup is not complete in Botcake, so the page was not added. Fix these in Botcake, then Check again:` | one line per reason, from `ChatblastSetupCheck` |
| Token invalid | `The token test failed (token invalid), so the page was not added. Check the Botcake ID and token, then Check again.` | none |
| Botcake error (no answer, rate limit, odd answer) on the token test or the check | `Botcake did not answer (<reason>), so the page was not added. Try Check again in a minute.` (the reason is Botcake's short error, e.g. `Botcake: HTTP 429 …` or `Botcake: HTTP connection error …`) | none |

Reasons in `missing` (amendment 2: the existing "exactly one" rule; only the duplicate text changed):
- `No flow named UPDATE SHIPPING STATUS 2026 in Botcake`
- `2 flows named UPDATE SHIPPING STATUS 2026 in Botcake — keep only one`
- `No custom field named message in Botcake`
- `2 custom fields named message in Botcake — keep only one`
- `No template named post_order in Botcake` / `Template post_order is not approved in Botcake` / `Template post_order has no {{1}}`

The duplicate wording is in `ChatblastSetupCheck::pick`, so page settings, the Ready reason ("Page not ready: …") and the Problems panel show the same clearer text.

Dialog (amendment 3): a red box (`#page-refused`, `role="alert"`) with the sentence and every reason on its own line. It stays until the dialog is closed/reopened or "Check again" is pressed (no timer, no toast). The typed name, ID and token stay in the form; "Check again" resubmits the same form (if the Default Reply move was already confirmed, it is not asked again). Other errors (no name, ID used by another page) keep the plain red line. The dialog never asks for a flow, field or template (amendment 2); the help text under the fields now says the setup is checked and the page is only added when it passes.

## S2 Existing pages
Untouched: nothing turns pages off or removes them; only "+ Page" (which refuses pages already on the list) changed. Test: a listed page with a failing check stays listed and On after another page is refused.

## S3 Urgent rule (ProblemsPanel::setup)
- `ChatblastSetupCheck::pages()` now marks each line with `setup: true` when the stored check is `ok = false` (the setup itself; not "token invalid", not "none delivered").
- New `ProblemsPanel::advertising()`, cached with the panel (`'advertising'` next to `'selling'`, 60 s), one grouped query:
  `SELECT page_name FROM ads_manager_reports WHERE day >= <Manila today - (AD_DAYS-1)> AND page_name IS NOT NULL GROUP BY page_name HAVING SUM(amount_spent_php) > 0`
  with `AD_DAYS = 2` (today and yesterday, Manila), names matched in PHP with `pageKey` like `selling()`.
- Rule: setup not ok AND (ad spend in the last 2 days OR an order in the last 14 days) → **orange** `No shipping status setup: <reasons>` + Page settings link, sending or not. Otherwise the old lines (white "Not sending, setup incomplete: …", white "Quiet …", orange "Setup: …" for token invalid / none delivered on a sending page with orders, and the orange fallback when orders cannot be read).
- Note: a sending page with orders and an X used to read "Setup: <reasons>" and now reads "No shipping status setup: <reasons>". The Acknowledge/Done of a line is keyed by its text, so an earlier ack on such a "Setup: …" line does not carry over; the line shows again until acknowledged again.

## S4 Badge on /chatbot/pages (with amendment 3)
`/chatbot/data` rows carry `no_shipping_setup`: the reasons when the stored `botcake.setup.check` has `ok = false`, else null (read in the same settings query; no Botcake call). The grid shows a red "No shipping setup" badge right after the page name; it is a `<details>`: tap or click opens the reasons as a list under the name (readable on a phone), and the summary has the reasons as its title tooltip.

## Amendment 1: page settings
- The long help paragraph ("Click a value and type: … the sync details, the other keys and the history.") is removed from `page_settings/index.blade.php`.
- "Needs setup" always starts folded: one line "▸ Needs setup (N pages)" that opens on click. The old script remembered "open" in localStorage and opened by itself when the page count grew; both are gone, so it is folded on every load.
- Applied on /chatblast too (same partial): it was always open there; nothing broke. The spec-139 test that asserted "/chatblast has no toggle" was updated to expect the folded toggle on both screens.

## Files
| File | Change |
|---|---|
| `app/Http/Controllers/ChatbotController.php` | `addPage` requires the setup (`setupRefusal`, `undoAdd`); `data()` adds `no_shipping_setup`. |
| `app/Services/ChatblastSetupCheck.php` | Duplicate reason "… in Botcake — keep only one"; `pages()` lines carry `setup`. |
| `app/Services/Chatbot/ProblemsPanel.php` | `AD_DAYS`, `advertising()` (cached), the orange "No shipping status setup" rule. |
| `resources/views/chatbot/index.blade.php` | "+ Page" red box, Check again, help text; `shippingBadge` / `pageRefusalLines` helpers (`shipping-badge:start/end`), badge in the grid. |
| `resources/views/chatblast/partials/needs-setup.blade.php`, `resources/views/page_settings/index.blade.php` | Amendment 1. |
| `tests/Feature/Chatblast/ShippingSetupRequiredTest.php` | New, 16 tests. |
| `tests/Feature/Chatblast/ChatblastTestCase.php` | `setupOkFakes()` for tests that add a page. |
| Existing tests updated for the new rule/text | `ChatbotConnectionsTest`, `ChatbotPagesTest`, `ChatbotDataSpeedTest` (+ snapshot), `PsidCoverageTest`, `BotcakeChatAdTest` (setup faked ok; the "token test failed → saved Off" case moved to the new test as a refusal), `FacebookNameTest`, `ProblemsPanelTest`, `QuietSetupTest`, `SetupProblemsBannerTest`, `AiProblemsTest`, `ChatblastSetupCheckTest`, `SetupCheckEveryTenMinutesTest`, `ChatblastNeedsSetupTest` (+2 amendment-1 tests). |

## Tests
- New `ShippingSetupRequiredTest`: 16 tests, 124 assertions, green (refused with every reason and nothing left behind, then added after the fix; two flows, two fields, template not approved, template without {{1}}; Botcake 429 / no answer on the token test / no answer on the templates; token invalid; a page from page settings goes back to how it was; existing pages untouched; Problems panel orange for ads or orders, white for none, old ads (3 days), zero spend and token invalid; one grouped ad query, cached; `no_shipping_setup` in `/chatbot/data` with no Botcake call; badge and refusal helpers run in Node; dialog markup).
- Amendment 1: 2 new tests in `ChatblastNeedsSetupTest` (folded on both screens, no `open`, count shown, no remembered/auto open; the help text is gone); the file is green (13 tests).
- Full suite once: 5112 tests, 7 failures. 3 are the known pre-existing ones (ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest). The other 4 were tests that assumed the old behaviour and are updated: `ChatbotConnectionsTest` S_148_16 and `ChatbotPagesTest` S_068_2 (+ Page now needs the setup faked ok), `ChatbotDataSpeedTest` S_207_9 (snapshot regenerated; the only diff is `"no_shipping_setup": null` on each of the 117 rows), `PsidCoverageTest` ad-names query count (the panel's ad-spend query and its `Schema::hasTable` are not counted as ad-name queries). Each of those files is green after the update.
- Pint run on the new PHP test file.

## Not done / notes
- Not checked in a browser; the UI parts are covered by markup and Node tests of the helpers.
- Not merged, no PR, not deployed.
