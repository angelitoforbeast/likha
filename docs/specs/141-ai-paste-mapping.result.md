# 141 result: AI chooses the field for each pasted value (chatbot page Edit)

## Plan

Risk: **high** (a new route that untrusted pasted text reaches, and it goes to OpenAI). Threat model: pasted cells and
the header line are untrusted; the AI's answer is untrusted too. Trusted: the field list (ChatbotFields), config.

1. **Backend, high.** `ContentAi::ask()` gets an optional timeout (default stays 40 s; the paste map uses 20 s).
   New `App\Services\Chatbot\PasteFieldAi`: builds the prompt (fields with label, key, one-line description; cells
   by index, each shortened; header as a hint), calls ContentAi, validates the answer against the given field keys
   (unknown key, bad index, bad confidence, wrong shape → null), logs model/tokens/cost.
   New `POST /chatbot/paste-map` (`ChatbotController::pasteMap`): same `checkAccess()` as Edit/Save, page must be on
   the chatbot list, cells must be non-empty text, returns only `{mapping: [{cell, key|null, confidence}]}` or
   `{fallback: true, note}`. Never returns or changes values. Cases .1 .2 .6 .7 .8 .9 .10 .13 (server side).
2. **Frontend pure logic, medium.** In `public/js/chatbot-paste.js` (node-tested like S-092): `aiRow(text, columns)`
   (the cells and header hint to send, using today's header detection), `mapConflicts(mapping)`,
   `applyMapping(cells, choices, blanksEmpty)` (the exact value per chosen field). Cases .3 .4 .5 .11 .13.
3. **Frontend modal, medium.** Edit modal: on paste (debounced) call the endpoint, show the preview list (shortened
   value via textContent, field dropdown pre-set, low/conflict highlighted), Apply / "Use header/position instead";
   fallback runs today's mapping with a note. Wrapping layout for 390 px. Case .12.
4. Stories S-141 in `qa/stories.md`, skeptic-reviewer (adversarial) on the diff, fix loop, result.

## Progress

- [ ] 1 backend  - [ ] 2 pure JS  - [ ] 3 modal  - [ ] 4 review + result

## Files

## Endpoint

## Prompt

## Validation rules

## Fallback

## Cost per call

## Evidence per case

## Commits

## Open points
