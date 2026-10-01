# Handoffs

Protocol: Mira writes `handoff/NNN-name/HANDOFF.md` (never edited by the worker); the worker reads CLAUDE.md, then the handoff, sends a plan to Mira and waits for her "go", builds on the handoff's branch, and fills in `RESULT.md` in the same folder with nothing left blank.

| # | Name | Branch | Status |
|---|---|---|---|
| 001 | sourcing-worklist | `feat/sourcing-worklist` | done — RESULT.md filled, waiting for Mira's branch review |
