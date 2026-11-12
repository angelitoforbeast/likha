# 271: A page needs a working shipping-status setup to be added, and a page with ads or orders but no setup is urgent

Weight: bounded. Risk tier: medium (blocks adding pages; changes what the team sees as urgent). Size: small-medium. Budget: 2 attempts per failing step; stop and report if blocked.

## Why (live data 2026-11-13)
Of 67 pages that spent on ads on 2026-11-12, 29 (e.g. Beatrice Dizon ₱9,297, Isabelle Castillo ₱6,729, Camille Torres ₱3,966) have no "UPDATE SHIPPING STATUS 2026" flow, no "message" field and no "post_order" template in Botcake, so customers there get no shipping updates. They were added through "+ Page" without the setup being required, and the Problems panel shows them as white/not urgent ("Not sending, setup incomplete") because they are not sending — the reason they are not sending is the missing setup. The owner wants the setup required when adding a page and these pages in Urgent.

## Stories
S1 (P1) **"+ Page" requires the shipping setup.** After the token test passes, the setup check (ChatblastSetupCheck run for that page) must be ok: the flow, the custom field and the approved template with {{1}}. If not ok, the page is NOT added to the chatbot list (or is saved but stays Off and is not listed — choose the option that leaves no half state and explain), and the dialog shows exactly what is missing, one line each, using the check's reasons (e.g. "No flow named UPDATE SHIPPING STATUS 2026 in Botcake"), plus a "Check again" action so they can fix it in Botcake and retry without retyping. A Botcake error (no answer, rate limit) is shown as such and does not add the page.
S2 (P1) **Existing pages are not removed or turned off** by this change.
S3 (P1) **Urgent:** in ProblemsPanel, a page whose shipping setup is not ok AND that had ad spend (ads_manager_reports.amount_spent_php > 0 in the last 2 days, matched by page name like the rest of the app does) OR orders in the last ORDER_DAYS is **orange (urgent)** with the text "No shipping status setup: <reasons>" and the Page settings link — even when it is not sending. Pages with no ads and no orders keep today's white line. Keep the panel's caching and query style (one grouped query for ad spend, cached like 'selling').
S4 (P2) The /chatbot/pages list shows a small red "No shipping setup" badge on rows whose setup check is not ok (read from the stored botcake.setup.check; no Botcake call on page load).

## Constraints
- Smallest change; reuse ChatblastSetupCheck results/reasons; no new tables. English UI text like nearby screens.
- Tests: + Page refused with reasons when setup not ok (Botcake faked), added when ok, Botcake error path, existing pages untouched, ProblemsPanel orange for ads-or-orders pages without setup and white otherwise, badge rendering.
- Commit: `feat(chatbot): adding a page needs the shipping status setup, and pages with ads but no setup are urgent (271)`; NO Co-Authored-By, Claude-Session or "Generated with" lines, no person names. Save spec as docs/specs/271-shipping-setup-required.md and a result .result.md.

## Allowed
composer install, php artisan test (new tests + full suite once; known pre-existing failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest), vendor/bin/pint on new PHP files, git add/commit, `git push -u origin feat/271-shipping-setup-required`. Do NOT merge, open a PR, push to main or deploy.

## Amendments
- [Amendment 1](271-shipping-setup-required.amendment-1.md): the long help text on page settings is gone and "Needs setup" starts folded.
- [Amendment 2](271-shipping-setup-required.amendment-2.md): the system finds the flow, field and template by name; missing or duplicated is refused with clear reasons.
- [Amendment 3](271-shipping-setup-required.amendment-3.md): refusal reasons stay visible in a red box; the badge shows its reasons on tap.
