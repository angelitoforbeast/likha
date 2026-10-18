# Plan 052: flow preview on /page-settings

Spec: `docs/specs/052-flow-preview.md`. Threat model: untrusted = all request input on /page-settings (page name, key, value) and every Botcake answer (flow names, template names, bodies, statuses: shown as text, never as HTML or instructions); trusted = config, migrations, the stored token (used only in the request header). No new route, no new table or column, no migration.

## Design decisions the spec leaves open

- **New service `App\Services\BotcakeFlowNames`** for /page-settings only. `ChatblastFlowInfo` (chatblast pages, spec 050's area) is left untouched; its template parsing is similar but changes for a different reason (the preview cache), so it is not shared.
- **System keys**: `PageSettings::isSystemKey($full)` = the list `SYSTEM_KEYS` (now `botcake.token_status`, `botcake.template.shipping.text`) plus the pattern `/^botcake\.flow\.[a-z0-9_]+\.name$/`. The three places that read `SYSTEM_KEYS` (validate, clearing a cell, the grid's readonly flag) call it. `PageSettings::isDetailKey($full)` = the name and text keys: the grid drops them from the columns but keeps them in each row's cells (and in the poll's changes), so the browser draws them inside the parent cell.
- **Stored values** (`page_settings.value` is text): the flow name; `!wala` (ID not in the list); `!bura` (`is_removed`); `!hindi nasuri: <reason>` (the call after a save failed; it replaces a name that may belong to the old ID). For the template text: the BODY text, `!wala` (the name is not an APPROVED template of the page), or `!hindi nasuri: <reason>`. Reasons are our own short strings (HTTP code, success:false, not a list, out of time), never a response body. The grid's `cell()` adds `line` (display text, `{{1}}` shown as "(mensahe ng sequence)") and `tone` (red, grey or none) for these keys; the browser prints them as text only.
- **Flows read**: `GET /pages/{id}/flows?page=N`, N = 1.., stopping on an empty list, a page with no new IDs (the API ignoring `page`), or 10 pages (cap). Every write uses `set(..., system: true)`, so an equal value is "unchanged".
- **Template pick** (only when `botcake.template.shipping` is empty): (1) the `(name)` at the end of the shipping flow name, when an APPROVED template has that name (trim, case-insensitive); (2) else the only APPROVED template whose BODY has `{{1}}` and whose example holds "Custom Field" (any string in the example, case-insensitive); (3) else nothing (the text row is deactivated; the grid says "piliin ang template"). The pick is written as `botcake.template.shipping` with no user (shown as "system"), so the owner may type over it.
- **Refresh triggers**: (a) after `saveCell` of a `botcake.flow.<x>`: one flows read (all flow keys of the page get their names; on failure only the saved key gets `!hindi nasuri`); after `saveCell` (or clear) of `botcake.template.shipping`: one templates call (pick when empty, then the text). (b) After Test token (row) and Test lahat, when the token is `ok`: the flows read, then the templates call; a failure here keeps the stored lines and is returned as `refresh_error`. Never in Sync.
- **Time**: each call is `once()` (no retry) through `shared(sha1(token))` (one rate key per page). Test lahat ends its Botcake calls within 30 s of the request start (the token check has 20 s of that): each later call gets `min(8 s, time left)` and is skipped as "kulang sa oras" when under 2 s is left. After a save: the same 8 s per call within 20 s.
- **JSON**: `saveCell` and `testTokenJson` return `cells` = `{fullKey: cell|null}` for every key the refresh wrote (names, template, text). The row button keeps its redirect; the reload shows the lines.
- **Grid**: a "Shipping template" column (`botcake.template.shipping`) after the four flow columns. Under a flow cell with a value: the name line (grey "hindi pa nasusuri" when there is no name row). Under the shipping flow also "Template: <name> — <body>" (one truncated line, the full body on hover). Under the template cell: the body line, red when not APPROVED; "piliin ang template" (grey) when empty.

## Stories

The brief brings a test list but no stories; slice 052 in `qa/stories.md` is written from it. The one-shot brief gives no chance to wait for a "go", so this plan is the record and goes with the result.

## Tasks

| # | Task | Side | Risk | Covers |
|---|---|---|---|---|
| 1 | System and detail keys: pattern, validation, not a column, unchanged | backend | medium | S-052.6, S-052.7 |
| 2 | `BotcakeFlowNames`: flows read with pages, names and markers, template pick and text | backend | medium | S-052.1, S-052.2, S-052.4, S-052.5, S-052.8 |
| 3 | Controller: refresh after save, after Test token and Test lahat (ok only, time budget), `cells` in the JSON; access unchanged | backend | medium | S-052.1, S-052.3, S-052.9 |
| 4 | Grid view: the second lines, the template column, poll and JSON updates | frontend | medium | S-052.10 (render, checked by hand) |

The tasks share files, so they run in order, in this session. One review (skeptic-reviewer, standard depth) on the whole diff before the result.
