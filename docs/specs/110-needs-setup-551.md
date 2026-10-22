# Spec 110: "Needs setup" lists only pages whose setup is wrong; customers Facebook says can't be reached are shown as information

## Why

`/chatblast/page-settings` shows a red "Needs setup (35 pages)" box (`ChatblastNeedsSetup`). It lists every page with at least one unsent chatblast message in 3 days. Live on 2026-10-21: 33 of the 35 were "Send failed", and almost every failure was Botcake answering the send_flow step with `(#551) This person isn't available right now.` (stored in `botcake_messages.steps`, step `flow`, response message; `reason` = "The flow was not sent (HTTP 200)"). That is Facebook saying the customer can't receive messages (blocked the page, deactivated or restricted); nothing on the page's settings can fix it. Those pages send fine (e.g. Kylene Dellamas 4,146 sent vs 70 failed in 3 days; Grace Bernardo 458 sent vs 1). Only 2 pages really need setup (Beatrice Dizon, Tracy Velasquez: "Missing flow or message field").

## Decisions

| Decision | Why |
|---|---|
| Classify each failed send by its Botcake answer: `customer_unreachable` when the flow (or field) step's response carries Facebook error #551 (also treat #100 "No matching user found" the same), else the existing reasons. Compute from `steps` at read time; store nothing new (no migration). | Know which failures are the customer's side. |
| "Needs setup" lists a page only when: its setup is incomplete (missing flow/field/template/token), its token is invalid, or its sends are failing for setup reasons: setup-side failures (not `customer_unreachable`) are at least 10% of its attempts in the last 3 days, or every attempt in its last hour failed. Keep the existing "Fix" link and wording. | Real problems only. |
| Unreachable customers show as a small grey info line (not red, no "Fix") under the box, folded after the top 5, with a total. | Visible, not alarming. |
| The box title counts only pages that need setup; when none, the red box is hidden. | Correct count. |
| Same rules wherever else the app shows "needs setup" for chatblast, so numbers match. | Consistency. |

## Done when

- Tests: a page with only #551 failures is not in Needs setup and appears in the unreachable info with its count; a page missing its flow is in Needs setup; a page with at least 10% non-#551 failures is in; a page whose last-hour attempts all failed is in; #100 counts as unreachable; the title count; box hidden when none.
- The Chatblast, PageSettings and Botcake test folders pass, plus the new tests; the full suite adds no new failures.
- The result names the service and view changed, any migration, and the commits.
