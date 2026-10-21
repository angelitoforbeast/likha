# Brief 105: the Ad Accounts page follows the Ad Payment access, with no menu link of its own

## Why

`/ads_manager/ad_account` (`AdAccountController`: index, edit, store, destroy) has no role check: any logged-in user on an allowed IP can open it and add, rename or delete ad accounts. It is reached from the "Ad Account ID" button on `/ads_manager/payment/upload`. Access to pages is set per role at `/owner/nav-settings` (nav_links + nav_link_role_visibility; see how `/chatblast` and `/chatblast/page-settings` enforce it, "NavAccess, sa controller"). The Ad Payment link (nav_links key `ads_payment`, route `/ads_manager/payment/upload`) is visible to CEO, Marketing and Marketing - OIC today. The owner, 2026-10-21: no menu link for Ad Accounts; it belongs with the existing Ads/Ad Payment link ("wag ilagay sa menu, kasama na dapat dun sa existing ads navlink").

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| All four Ad Accounts routes use the **same access as the `ads_payment` nav link**, through the existing nav-access mechanism (reuse the helper that chatblast uses; do not invent a new one). A role that cannot see Ad Payment gets the same response the other nav-gated pages give (404 or 403, whichever the helper does). CEO always allowed if the helper already does that. | One place to manage access. |
| **No new nav link**, no change to `/owner/nav-settings` rows. If the owner later changes Ad Payment's visibility, Ad Accounts follows automatically. | The owner's word. |
| The "Ad Account ID" button on the payment upload page shows only to users who pass the same check (it already does in practice; make it explicit). | Consistency. |
| No change to spec 103's history and change log. | — |

## Done when

- Tests: a Marketing, a Marketing - OIC and a CEO user can open, add, rename and delete; a role without Ad Payment visibility (e.g. Data Encoder) gets the refusal on all four routes; flipping Ad Payment's visibility for a role flips Ad Accounts access; existing 103 tests still pass.
- `vendor/bin/phpunit tests/Feature/AdAccount tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes; the full suite adds no new failures.
- The result names the helper used (file:line) and the commits. No migration.
