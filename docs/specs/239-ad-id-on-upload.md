# Spec 239: the order gets its ad id when the flow uploads it

**Project:** Likha (the same code runs incepxion.pro). **Shape:** change · **Weight:** bounded · **Risk tier:** medium (it feeds the per-ad reports and CPP the owner acts on)

## Why
Orders reach macro_output through the flow's "Upload to macro" step (app/Services/OrderEncode/Upload.php, LiveOrder::insertRaw). That step writes the customer's PSID (`botcake_psid`) but not the ad that brought them (`botcake_ad_id`). The ad id is only filled later by `botcake:sync-customers` at 01:00 Manila (BotcakeCustomerMatcher, the "ad_filled" block). So every per-ad report for today is wrong until the next night. On 2026-11-06, of 1,086 orders with a PSID, 33 had an ad id. The same day, 1,069 of them already had an ad id in `botcake_psids`, saved from the chat (source `api` or `chat_verify`). The owner wants the ad id written with the order, shown and editable in the step like the other fields.

## Decisions already made (settled with the owner; don't reopen them unless something is actually broken)
| # | Decision |
|---|---|
| 1 | A new system field `{{ad_id}}`: the ad that brought this customer to the page. Use exactly the rule of the nightly matcher's ad query: the `botcake_psids` row of the same page and PSID. The page is matched by page_id, or by name when the row has no page_id. The source is `api` or `chat_verify`, and ad_id is not empty. When more than one row qualifies, use the same choice the matcher's UPDATE makes; read it and reuse one shared query, not a copy. It is empty when there is none. It is read only when the flow or the botfields mention it, like the order_* variables (no query on other runs). |
| 2 | `{{ad_id}}` is listed with the other system fields in the flow editor's variable picker. Its label is "Ad ID (the ad that brought the customer)". |
| 3 | The "Upload to macro" step gets a field "Ad ID (I)" next to Price (H), defaulting to `{{ad_id}}` for new steps. Existing steps that have no such key behave as `{{ad_id}}`, so no flow needs editing. A step whose field was cleared on purpose writes no ad id. |
| 4 | On Insert, the row is created with `botcake_ad_id` set to the field's value when it is an ad id (digits only, 1 to 64 characters). Anything else is left null and noted in the run's event. On Update, `botcake_ad_id` is filled only when the row has none; it is never replaced. The nightly matcher stays as it is: it never replaces an ad id, and it still fills the orders this missed. |
| 5 | Every place where the flow creates a macro_output row writes the ad id this way. That includes LiveOrder::insertRaw and the other MacroOutput::create in LiveOrder (line ~39) if a flow step reaches it. Say in the result which paths you covered. |
| 6 | A one-off command `orders:fill-ad-id {--date=} {--page=} {--dry-run}` fills `botcake_ad_id` for orders of that date that have a PSID and no ad id, with the same rule and the same never-replace guard. It works in chunks of 1,000 by id, as the matcher does. It prints counts only: checked, filled, no ad found. Read-only with --dry-run. |
| 7 | No change to the nightly schedule, the matcher's PSID matching, or anything Botcake-side. |

## Stories
Add these under `## Slice 239 – Ad id on upload` in qa/stories.md, with one test per case:
- S-239.1.1: A customer with a `chat_verify` row carrying ad 1202…, on the same page and PSID, passes Upload to macro (Insert). The new row has that ad id.
- S-239.1.2: Same page name but another PSID, or same PSID on another page: no ad id written.
- S-239.1.3: The row has a page_id that differs from the run's page, while the name matches: not used. A row with no page_id and the same name is used. This is the matcher's page rule.
- S-239.1.4: An existing step with no "Ad ID" key writes the ad id. A step whose field is set empty writes none. A step whose field is "{{ad_id}}x" or "abc" writes none, and the event says why.
- S-239.1.5: Update onto an open row that already has an ad id: the ad id is unchanged. Update onto one that has none: it is filled.
- S-239.1.6: `{{ad_id}}` interpolates in a Send message. A flow that never mentions it makes no `botcake_psids` query (assert the query count).
- S-239.1.7: `orders:fill-ad-id --date=… --dry-run` changes nothing and prints the counts. Without --dry-run it fills only empty ones.
- S-239.1.8: After the upload, the nightly matcher run leaves the uploaded ad id as it is.

## Done when
- [ ] The cases pass, each with a test named after it.
- [ ] The existing OrderEncode, Upload to macro, FlowRun vars and BotcakeCustomerMatcher tests pass.
- [ ] The full suite adds no new failures (known: ExampleTest, ItemTableFitScriptTest; BigBroadcastTest and AstraAddressRulesTest S-26.18 are flaky).
- [ ] The result lists the paths covered (decision 5), the files changed, and the exact command to backfill today's orders.

## Out of scope
The chatblast and auto-switch timeouts. The owner/private page row's CPP for pages with many items. Any Forbeast change.
