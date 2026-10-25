# Plan 152: the Edit modal paste puts follow-up texts and images into Seq 1…N in sheet order

Threat model: the pasted cells and header line are untrusted (staff paste from a sheet; the AI reads them as data).
The position guesses come from the client too, so the server keeps only guesses that are known field keys; a guess,
like the AI answer, can only pick a field, never a value. Trusted: config, the field list, the OpenAI answer's shape
(validated as today). Risk tier: medium (behind the existing paste-map route and its access check; the "Add Seq"
button reuses the existing "+ Column" route; no new way in).

## Design

- **Position guess (decision 1).** New pure JS `ChatbotPaste.positionKeys(text, columns, startKey)`: per cell of the
  AI row, the key the header/position mapping gives (same logic as `plan`: header names, else from "Starting at
  field"). The client sends it as `guesses` with `cells`. `pasteMap` keeps a guess only when it is a modal field key
  (others become null). `PasteFieldAi` adds a "Position guesses" block (keys only, never cell text) and the prompt
  says: keep the position guess unless the cell clearly belongs to another field.
- **Model (decision 4).** `services.openai.chatbot_paste_map_model` (`CHATBOT_PASTE_MAP_MODEL`, default `gpt-5.2`, the
  order-encode checker model's default); blank = the content-check model. `ContentAi::ask()` gets an optional model;
  the cost uses the same price table; the log line is unchanged.
- **Continuation (decision 2)** in `PasteFieldAi`, after the answer is validated: walk the cells in order; a cell the AI
  put on `seq_k` (or Picture / `seq_img_k`) sets the next text (image) slot; a later non-blank cell that is unassigned
  or low becomes the next slot when it is a text (not a link, not a price/number, not an order form) or an image link,
  confidence medium, note "in order after Seq k" / "in order after Picture" / "in order after Seq Img k". A slot
  already taken by another cell is left alone. A slot with no column goes to `missing` (`{cell, key, label}`), the
  cell stays None.
- **Missing columns (decision 3), UI.** The preview lists them ("2 more follow-up texts: no Seq 21, Seq 22 column")
  with a button "Add Seq 21–22" (pure JS `ChatbotPaste.missingText`); confirm says only the empty columns are made
  now; it posts each name to the existing `/chatbot/column` ("+ Column": same permission, same name pattern, so
  `seq_21` long / `seq_img_21` image), reloads the columns, adds the new fields to the modal, and maps the cells.
- **Item mismatch (decision 5), UI.** Pure JS `ChatbotPaste.itemMismatch(cells, keys, pageItem)`: the page item is the
  page's ITEM NAME IN JNT DASHBOARD; the pasted item is the cell mapped to that field; normalised words (lowercase,
  letters/digits, 3+ letters, trailing "s" dropped) with no word in common = the yellow note above Apply.

## Tasks

| # | Task | Side | Tier | Cases | Files |
|---|---|---|---|---|---|
| 1 | Position guesses, prompt, model setting, continuation and missing list on the server | backend | medium | S-152.1, .2, .3 (server), .4, .5, .6, .8 | `PasteFieldAi`, `ContentAi`, `ChatbotController::pasteMap`, `config/services.php`, `.env.example`, `ChatbotPasteMapTest` |
| 2 | Preview: send guesses, notes, missing list with "Add Seq" button, item mismatch note | frontend | medium | S-152.3 (UI), .7, .9 | `public/js/chatbot-paste.js`, `chatbot/index.blade.php`, `ChatbotPasteScriptTest`, `ChatbotPasteMapTest` |

Task 2 depends on task 1's response shape; not parallel-safe (shared test file).

## Story changes

- S-152.3 uses 22 follow-up texts and Seq 21–22: every page has Seq 1–20 by default (`ChatbotFields::defaults()`), so
  "a page with Seq 1–10" cannot happen; the behaviour tested is the same (texts past the last Seq column).
