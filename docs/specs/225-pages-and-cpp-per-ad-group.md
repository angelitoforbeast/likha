# Spec 225: one "Replies via" choice, sticky columns that keep their width, and CPP rows per ad group

**Project:** Likha. **Shape:** change · **Weight:** medium · **Risk tier:** medium (a reporting page and a settings screen; no sending logic)

> Committed with the work as `docs/specs/225-pages-and-cpp-per-ad-group.md`. Names no people and no decision ids.

## Why

On `/chatbot/pages` a page's bot is chosen in two dropdowns (Bot: Botcake/Forbeast, then App: Forbeast/Incepxion from spec 223). The owner wants one choice. When the table scrolls sideways, the pinned columns (Page, Active/Bot, Flow, Check) shrink and cover the column next to them. On `/ads_manager/cpp`, ad-based pages (reply values per ad group, spec 217) show one row per page; the owner wants their numbers split per ad group.

## Stories

**S1 (P1) One "Replies via" dropdown.** Each page row has one select: **Botcake**, **Bot 1 (Forbeast)**, **Bot 2 (Incepxion)** (one option per configured gateway address from `Gateways::routes()`, labelled "Bot N (<name>)", Botcake first). Choosing one sets `chatbot.provider` and `chatbot.gateway_app` together in one save (Botcake → provider botcake, address unchanged; Bot N → provider gateway + that address). The bulk "Replies via … Apply to selected" offers the same options and does the same. The separate "App:" select is removed. With only one address configured the options are Botcake / Forbeast as today. The existing endpoints keep working (other callers); add or extend one endpoint for the combined save, same access rules and change records.

**S2 (P1) Pinned columns keep their width.** On `/chatbot/pages` the pinned columns stay pinned while scrolling sideways, never shrink, and never cover the next column: give each pinned column a fixed width (min = max) and compute each `left` offset from the widths before it; the first scrolling column starts after the last pinned one. Check in a browser at 1366 px and 1920 px wide with a long page name and long flow name.

**S3 (P1) CPP rows per ad group for ad-based pages.** On `/ads_manager/cpp`, a page whose reply values are ad-based shows its usual page row (the total) plus one indented row per ad group under it: `Ad group: <name>` with Amount Spent, Orders, CPP, CPI, CPM, TCPR, Item Names for that ad group only. Page-based pages are unchanged.
- **Spend per ad group:** the sum of `ads_manager_reports` for the ad IDs in that ad group, same date range and filters as the page row.
- **Orders per ad group:** an order belongs to the ad group of the ad its customer came from: match the order's customer (psid / chat) to the ad id recorded for that customer (`chatbot_replies.ad_id` or `botcake_psids.ad_id` — your plan picks the source and the rule when a customer has more than one ad, e.g. the last ad before the order). Orders with no known ad go to an "Unknown ad" row so the ad-group rows add up to the page total.
- The other metrics use the same formulas as the page row, on the ad group's numbers.
- Cases: ad-based page with two ad groups → page row + 2 rows (+ Unknown if any), sums equal the page row; page-based page → one row as today; ad group with spend and no orders → CPP shows the page row's "no orders" convention.

## Constraints

- Base: Likha live `af110811`. Branch `feat/225-pages-and-cpp-per-ad-group`. Another worker is building spec 224 S2/S3 (a `route` column and "via" labels in the Inbox) on `feat/224-show-the-real-route`; don't touch the Inbox or `chatbot_replies` writes.
- No migration unless a read is too slow without an index; then say why in the plan.
- The CPP page must not get slower than today by more than ~20% on the live data size; measure the query on a copy or explain the plan.
- Commit messages and comments name no people, sessions or decision ids; no attribution lines. Don't push or deploy.

## Done when

The suite adds no new failures vs `af110811`; S1–S3 have tests; a browser check (screenshots) of S1 and S2 in the result file; the spec and `.result.md` committed.

Budget: medium; at most 2 attempts per step.
