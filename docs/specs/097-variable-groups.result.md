# Result 097: the flow editor's variable list is grouped: System, Botfields, Customer fields

Branch `feat/097-variable-groups`, cut from `main` as the brief asked. Nothing was pushed, and no server was touched. The files that spec 098 changes (PageSettings, the Botcake rate, error reasons) were not edited; `BotcakeChat::pageMap()` is only read.

## Commits

| Commit | What |
|---|---|
| `44d3cbf3` | docs: the brief, verbatim |
| `80e007a4` | feat: the graph endpoint returns `variable_groups` (name, group, tip, also) beside the flat `variables` list |
| `3aaa3a00` | feat: the editor draws grouped, coloured chips with tooltips, "!" on duplicates, folded Sequences and a search box |
| (this commit) | docs: result, stories S-097, screenshots |

## What changed

| Area | Change |
|---|---|
| Source of truth | The list the editor already used, `ChatbotFlowController::variables()` (fed by `GET /chatbot/flows/{id}/graph`), is now built from `variableGroups()` and split. `variables` (the flat list used by the Condition step's suggestions) keeps its shape: the same names, plus the customer fields saved on this flow's pages. |
| System (gray) | `RUN_VARS`, in order. Tooltip "Built in: <what>", e.g. "Built in: what the customer just typed". |
| Botfields (purple) | The `/chatbot/pages` columns (`ChatbotFields::columns()` labels). Tooltip "Botfield: set per page on /chatbot/pages". |
| Customer fields (teal) | First the fields this flow names (Send "save the reply", AI "save to", Set field ops) in step order, then the keys saved in `chatbot_customer_values` for the pages that use this flow, A–Z, at most 320. Keys starting with `__` (run state) are never listed. Shown in capitals, as before. Tooltip "Customer field: set by n3 (AI), n4 (Set field)", listing the steps that write it (Set field ops `set` and `append`; a `clear` op names the field but does not count as a writer). A field only found on customers reads "saved on customers of this flow's pages; no step of this flow sets it". |
| Duplicates | Names are compared the way the engine looks them up (`ChatbotVars::norm`: case, spaces and `_` ignored), so `last_input` and `LAST INPUT` are the same name. Both chips get an amber "!" and the rule in the tooltip. |
| The real rule | `FlowRun::vars()` is `ChatbotVars::merge(botfields, customer fields, run)`: the last one wins. So the **built-in value always wins** (a customer field or botfield with a run variable's name can't be read), and a **customer field wins over a botfield whenever the customer has it saved, even blank** (a saved empty string still overrides; only a cleared field falls back to the botfield). The tooltips say exactly this. |
| Sequences | In Botfields, Seq Img 1–20 and Seq 1–20 sit behind a "▸ Sequences (40)" button (`aria-expanded`); a click opens them in place, a second click folds them, and focus stays on the button. While searching, matching Seq chips show directly. |
| Search | "Search variables" above the groups filters chips by name (case-insensitive) across all groups; group counts follow; no match shows "No variable matches.". It resets when a step form opens. |
| Insert | A click still puts `{{name}}` at the cursor of the last field you were in, also after typing in the search box (the search box doesn't become the "last field"). |
| Everywhere | The list is the one `#vars` block of the step form, so Send, AI, Set field, Condition and Google Sheet forms all show the same chips. |

## Tests

Case table (`vendor/bin/phpunit tests/Feature/Chatblast/ChatbotVariableGroupsTest.php`, final tree):

| Case | Test | Result |
|---|---|---|
| S-097.1 each name in its group, with its tooltip | `test_S_097_1_each_name_is_in_its_group` | ✔ pass |
| S-097.2 the steps that write a customer field | `test_S_097_2_a_customer_field_lists_the_nodes_that_write_it` | ✔ pass |
| S-097.3 `__` keys and other pages' fields left out | `test_S_097_3_reserved_keys_and_other_pages_are_left_out` | ✔ pass |
| S-097.4 a name in two groups flagged in both, with the real rule | `test_S_097_4_a_name_in_two_groups_is_flagged_in_both` | ✔ pass |
| S-097.5 the editor's chips | headless Chrome, checks 1–12 below | ✔ pass |

Red run: `test_S_097_1…` failed first with `foreach() argument must be of type array|object, null given` (no `variable_groups` in the response).

**Suites:** `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: **761 tests, all pass** (1 skip, as before; 47 PHPUnit deprecation notices, as before). `ChatbotEditorTest` (which reads `variables`) passes unchanged.

## Browser check

Same approach as 092–096: a script outside the repo drove the installed Chrome headless over the DevTools protocol (Node 24 built-ins, no package installed). The app ran with `php artisan serve` on a temporary sqlite database outside the repo, with settings passed as environment variables; no env file was created. Data: one page linked to a 4-step flow (Send saves "chat status", AI saves "gpt input", Set field sets "gpt input" and "price"), and saved customer keys `cxd`, `last input`, `__wait`, `__last_chat`.

Result: **29/29 checks passed** at 1366×768 and 390×844, no console errors. Screenshots in `docs/specs/097-variable-groups/`.

| # | Check (both widths) | Evidence |
|---|---|---|
| 1 | Headings "System (8)", "Botfields · per page (52)", "Customer fields · per customer (5)" | `1366-send-form.png`, `390-send-form.png` |
| 2 | One colour per group, three different: slate-100, purple-100, teal-100 | same |
| 3 | Customer fields are CHAT STATUS, GPT INPUT, PRICE, CXD, LAST INPUT; no `__` key | same |
| 4 | Tooltips (the chips' `title`): "Customer field: set by n3 (AI), n4 (Set field)", "… set by n2 (Send message)", "Built in: what the customer just typed. Also a customer field; the built-in value always wins", "Botfield: set per page on /chatbot/pages" | script output |
| 5 | "!" on `last_input`, PRICE (botfield), PRICE (customer), LAST INPUT, and both PRICE tooltips state that the customer field wins | screenshots, script output |
| 6 | Sequences folded: "▸ Sequences (40)", `aria-expanded=false`, no Seq chip shown | `*-send-form.png` |
| 7 | A click opens all 40 Seq chips, "▾ Sequences (40)", focus stays on the button | `*-sequences-open.png` |
| 8 | Search "gpt" leaves GPT INPUT only; "seq img 2" shows Seq Img 2 and Seq Img 20 without the fold; "zzz" says "No variable matches." | `*-search.png` |
| 9 | With the cursor after "Only " in the message and the search box used in between, a click on PRICE gives "Only {{PRICE}}" and focus returns to the message | script output |
| 10 | No sideways scroll on the page or in the form | script output |
| 11 | The AI and Set field forms show the same three groups | `1366-ai-form.png` |
| 12 | No console errors | script output |

Not shown in a screenshot: the native tooltip popup itself (headless Chrome doesn't draw it); the check reads each chip's `title`, which is what the browser shows on hover.

## Review

One `skeptic-reviewer` pass at standard depth (the task is medium risk: a UI screen and a read on trusted data). **No blocker or major.** What it confirmed:

- The precedence text matches `FlowRun::vars` and `ChatbotVars::merge`.
- `__` keys are excluded at both sources.
- Customer keys are scoped to the flow's pages, and the query is parameterized.
- Names and tips go in through `textContent` and `setAttribute`, never as HTML.
- The query is portable across mysql and pgsql and guarded by `Schema::hasTable`.

All five minors are on trusted paths and accepted with reasons in `TODO.md` (097 section):

- the per-load `DISTINCT` read;
- the 320-key cap;
- a possible doubled "Also" line;
- the Seq label pattern;
- the inline assignment in `graph()`.

## Story changes

- New section S-097 in `qa/stories.md`, five cases. The brief brought no stories and said to start at once, so they were written from its decisions and its "Done when" list, not run past the reviewer first.

## Notes and conflicts

- **Precedence wording:** the brief suggested "the customer field wins when it has a value". The real rule is slightly wider (a saved blank also wins), and run variables beat both, so the tooltips say that instead, as the brief asked ("check it and state the real rule").
- **Flat `variables` list:** it now also carries the customer fields saved on this flow's pages, because it is built from the same groups. It only feeds the Condition step's suggestions.
- **No conflict** between CLAUDE.local.md and the brief.
- **Deploy:** no migration, no new setting, no asset build (the chatbot layout uses the Tailwind CDN, so the new purple and teal classes need no rebuild).
