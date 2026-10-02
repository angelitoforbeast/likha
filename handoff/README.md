# Handoffs

Protocol: Mira writes `handoff/NNN-name/HANDOFF.md` (never edited by the worker); the worker reads CLAUDE.md, then the handoff, sends a plan to Mira and waits for her "go", builds on the handoff's branch, and fills in `RESULT.md` in the same folder with nothing left blank.

| # | Name | Branch | Status |
|---|---|---|---|
| 001 | sourcing-worklist | `feat/sourcing-worklist` | Done — live 2026-10-01 |
| 002 | worklist-filters-table | `fix/worklist-filters-table` | done — RESULT.md filled, waiting for Mira's branch review |
| 003 | stock-doi-category | `feat/stock-doi-category` | done — RESULT.md filled, waiting for Mira's branch review |
| 004 | lifecycle-restock | `feat/lifecycle-restock` | in progress — plan sent to Mira |
