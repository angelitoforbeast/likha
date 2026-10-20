# Brief 097: the flow editor's variable list is grouped: System, Botfields, Customer fields

## Why

In `/chatbot` flow editor forms, "Variables (click to put one in the last field you were in)" shows about 70 chips in one flat list. Built-in run values (`last_input`, `full_name` …), page botfields (`PRICE`, `Prompt 1`, `Seq 1` …) and per-customer fields (`GPT INPUT`, `CHAT STATUS`, `CXD` …) are mixed, so the owner can't tell where a value comes from. Some names are confusingly close: `{{last_input}}` (built in) and `{{LAST INPUT}}` (a customer field).

The owner, 2026-10-21: "nakaindicate kung custom field or botfield … magkahiwalay … para lang madali makita".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Three groups, each with a heading and its own chip colour:** **System** (gray): the run variables `ChatbotFlowController::RUN_VARS`; **Botfields, per page** (purple): the `/chatbot/pages` columns, `ChatbotFields`; **Customer fields, per customer** (teal): fields set by the flow (Set field ops, AI "save to", send "save the reply") plus fields found in `chatbot_customer_values` for this flow's pages, excluding reserved `__` keys. Pick the source of truth that the editor already uses for its list, and split it. | The owner. |
| **Tooltip on each chip:** System says "Built in: <what>"; Botfield says "Botfield: set per page on /chatbot/pages"; Customer field says "Customer field: set by n109 (Set field), n22 (AI) …", listing the nodes of this flow that write it. | Where a value comes from. |
| **Seq Img 1–20 and Seq 1–20 collapse** into a "Sequences (40)" row that expands on click. | A shorter list. |
| **A search box** above the groups filters chips by name. | Fast find. |
| **A name that exists in two groups** (e.g. a botfield and a customer field with the same name) shows in both, with a small "!" and the tooltip "Also a <group>; the customer field wins when it has a value", per the current precedence in `ChatbotVars::merge` (check it and state the real rule). | Clarity. |
| **Same chips everywhere the list appears** (Send, AI, Set field, Condition, Sheet columns). Clicking still inserts into the last focused field. | Consistency. |

## Done when

- **Tests:**
  - the grouping data: each name in the right group;
  - the writer nodes listed for a customer field;
  - reserved keys excluded;
  - the duplicate-name flag.
- **Browser check:** groups, colours, tooltip, Sequences collapsed and expanded, search, and insert on click, at both widths.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes.
- Report the commits.
