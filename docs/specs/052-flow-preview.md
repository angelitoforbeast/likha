# Spec 052: flow preview on /page-settings

New task, spec 052. This worktree is a new branch `feat/052-flow-preview` from `develop` at 835d89ea, which is live on Likha and Incepxion. Another worker is building spec 050 (chatblast pages) on its own branch at the same time. Don't touch the chatblast pages, services or migrations.

Rules:
- never push, and no remote hosts;
- fake HTTP and a fake clock in tests, and never call Botcake from tests;
- no attribution lines in commits;
- no names or real ids in tests;
- comments in the style of the surrounding code (Tagalog doc comments).

Save this brief as `docs/specs/052-flow-preview.md` in your first commit and `docs/specs/052-flow-preview.result.md` at the end. Write your plan in `docs/plans/052-flow-preview.md` first. Aim at about 1.5 hours, commit after each part, and run a review before the result.

# Brief 052: show what each Botcake flow ID is, on /page-settings

## Why

On /page-settings the owner sees only numbers in the flow ID cells (`botcake.flow.shipping`, `flow.on_delivery`, `flow.in_transit`, `flow.broadcast`). He wants to see what each one actually is. Botcake's public API (checked live, read-only) gives:
- `GET /pages/{page_id}/flows` returns `{"data":{"flows":[{"id","name","is_removed","parent_id"}, ...]},"success":true}`. It gives the name only: there is no endpoint for a flow's blocks or content. `/flows/{id}` returns 400 and `/flows/{id}/content` returns success:false. The list may be paginated: an earlier script passed `?page=N`. Read every page of the list, capped at a sane number of pages.
- `GET /pages/{page_id}/message_templates` returns `data: [{name, status, category, components: [{type:"BODY", text, example}, ...]}]`. The shipping flow sends one of these templates, with the chatblast text as `{{1}}`.
  - On one Likha page the shipping flow is named "CHATBLAST - SHIPPING STATUS (post_order)" and the template `post_order` has the body "May update na po sa order nyo po❤️\n\n{{1}}" (example "Custom Field").
  - On Incepxion the flow is "SHIPPING STATUS" and the template is `chatblast`: "Follow up ko lang po yung sa order❤️ {{1}}".
  - The API gives no link from a flow to its template.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| Under every flow ID cell in the grid, show the flow's **name** from Botcake as a second line. If the ID is not in the page's flow list, show it in red as "Wala sa Botcake". If `is_removed` is true, show it in red as "Bura na sa Botcake". If the ID was never checked, show it in grey as "hindi pa nasusuri". | The owner wants to see what the ID is |
| Under the shipping flow, also show the **template** it sends: its name and body text, with `{{1}}` shown as "(mensahe ng sequence)". | What the customer sees first |
| Template choice: a new **editable** key `botcake.template.shipping` (the template name), shown as its own column "Shipping template". When it is empty, the system fills it automatically, in this order: (1) the name in parentheses at the end of the shipping flow's name, if an APPROVED template has that name; (2) otherwise, the single APPROVED template whose BODY has `{{1}}` with example "Custom Field"; (3) otherwise, leave it empty and show "piliin ang template". The owner may type over it. A name that is not an APPROVED template of the page shows red. | Everything shown and editable; the API has no flow → template link |
| Storage: **rows in `page_settings`, no new table and no new column**. These are system keys, written only by the system through `set(..., system: true)`, and read-only in the grid: `botcake.flow.<x>.name` (one per flow key; the value is the name, or a marker for not found or removed) and `botcake.template.shipping.text` (the body text). Add them to `SYSTEM_KEYS` (a pattern or a list). They must **not** appear as their own grid columns: they render inside their flow or template cell. A write that does not change the value is "unchanged" (no new row), so history stays small. | Lean schema; page_settings is already Page / Provider / Key / Value |
| **When they are refreshed (never during Sync):** (a) right after a `botcake.flow.*` or `botcake.template.shipping` cell is saved, for that page only, with one flows call or one templates call, no retry and a short timeout. A Botcake failure never blocks the save: the line shows "hindi nasuri: <reason>". (b) On **Test token** (row button) and **Test lahat** (one page per request), after the token check, but only when the token is ok: one flows call and one templates call. Test lahat must stay within its per-request time budget. Return the refreshed cells in the JSON, so the row updates without a reload. | Sync is long; the owner: "bakit sa sync, ang haba nung sync" |
| The grid shows it all without a button: the second lines are part of the cells. Hovering the template line shows the full body. | The owner: "need ba ng button?" No |
| Never print or log a token, and store no Botcake response bodies beyond the name and text. Same access as /page-settings today. Works on Likha and Incepxion (same codebase). | Standing rules |

## Tests

- Saving a flow ID fills its name, and handles not found, removed, and Botcake failure without blocking the save.
- Test token refreshes the name and template only when the token is ok.
- Test lahat returns the refreshed cells.
- Template auto-pick covers all three cases, an owner-typed value is kept, and an unknown name shows red.
- The system keys are refused from the page (validation), are not separate grid columns, and an unchanged value makes no new row.
- Flows pagination works.
- Access is unchanged.

Write them red first where it comes naturally. The whole suite must pass, except the known `ExampleTest` and, in a fresh worktree, `ImportStartTest`.

## Out of scope

- Editing flows or templates in Botcake.
- Using the template text in the chatblast preview: spec 050 owns those pages, and a later spec will wire it.
- Migrations.

## Final message

Include:
- the commits;
- the keys added;
- when refreshes happen;
- what the grid shows in each state;
- the test lines and the suite result;
- the review findings and their fixes;
- anything the brief got wrong.
