# Plan 001: Sourcing worklists

Spec: `docs/specs/001-sourcing-worklist.md`. Branch `feat/sourcing-worklist` (no worktrees are needed for the sequential tasks; T1 and T3 are the parallel-safe ones).

| # | Task | Tier | Side | Parallel | Depends |
|---|---|---|---|---|---|
| T1 | Untrack `latest.dump` (`git rm --cached`), add to `.gitignore`. `chore:` commit. | low | backend | parallel-safe | — |
| T2 | HOLD rule: `ItemTestCase` harness; characterisation test of `/item/data` (no date range); then test-first `HoldService::liveHoldQuery` (approved STATUS exclusion + `ts_date` range) and `groupUnitsByBaseItem` extraction (Blue); `ItemController::data` uses it. | medium | backend | — | — |
| T3 | `SourcingClassifier` pure class + one table-driven unit test. | medium | backend | parallel-safe | — |
| T4 | `GET /item/worklist` route + controller method (PO lines, quotes, settings, photo, open qty, classifier) + `WorklistTest`. | medium | backend | — | T2, T3 |
| T5 | Migration A `item_supplier_quote_history`; history writes in `quoteSave`/`quoteDelete`; `prev_price`/`prev_date` in `quoteRows`; `QuoteHistoryTest`. | medium | backend | — | T2 (harness) |
| T6 | Migration B `photo_path`; optional `photo` upload in `quoteSave`, old-file cleanup on replace/delete, `photo_url` in `quoteRows`; `QuotePhotoTest`. | **high** (raised: a new upload field is a new way in for untrusted files, CLAUDE.md tier rule) | backend | — | T5 |
| T7 | Page: chips with counts, worklist table, `?list=` in URL, quote form photo input (FormData), "dati ₱X (date)", quote thumbnail. `npm run build`. | medium | frontend | — | T4, T5, T6 |

Spec review (`skeptic-reviewer`, spec-review mode, because of T6): needs revision. Its 2 majors (no open-status question; a save without a photo would wipe the photo) and the minors are folded into the spec: questions section, PO-line definition, summed open-PO numbers, history locking/null compare, file order, FormData nulls, scoped x-ref, `safeLink`, the missing test cases, and the T7 check method.

Per task: the developer does Red → Green → Blue and reports the red line. Review: T1 gets lint/tests by the main session; T2–T5 and T7 get `skeptic-reviewer` standard depth (sonnet); T6 gets adversarial depth (opus). After T7: an optional `browser-checker` if browser tools exist, then the full suite and `php -l` on every changed PHP file, `npm run build`, RESULT.md. No push, no PR (no `gh`): Mira reviews the branch.

Files touched: `app/Services/HoldService.php`, `app/Services/SourcingClassifier.php` (new), `app/Http/Controllers/ItemController.php`, `app/Models/ItemSupplierQuote.php` (fillable `photo_path`), `routes/web.php` (one line), two migrations (new), `resources/views/item/index.blade.php`, `tests/Feature/Item/*` + `tests/Unit/SourcingClassifierTest.php` (new), `.gitignore`, `TODO.md` (if findings are accepted), handoff RESULT/README.
