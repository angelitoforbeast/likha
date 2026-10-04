# Result 008: The classic AI checker stops writing response bodies and exception messages into the log

Status: **in progress** on branch `feat/008-checker-log-cleanup`, cut from `develop` at `82fd74e`.

## Plan

1. First commit: this handoff saved verbatim, this file, and the row in `handoff/README.md`.
2. Red: one test file with cases (a) to (d) and the non-JSON case, through `MacroChecker::callOpenAI`, the search step's smallest reachable seam and `AiCheckerRowRunner::run`, with `Http::fake()` and a captured `Log`.
3. Green: the four log calls take the `ASTRA_ENCODER_HTTP` / `ASTRA_ENCODER_EX` shapes (one small private helper in `MacroChecker` for `error.type` / `error.code`; `sqlstate` on `AI_CHECKER_LOG_FAIL`); every other `Log::` call in the two files is swept and treated the same or listed as left as is.
4. Review: `skeptic-reviewer` at standard depth (medium tier) on the finished diff with the two named checks; majors get one fix loop, the rest go to `TODO.md` with reasons.
5. Verify every done-when item with the command and its output, fill this file, commit; no push, no PR, no deploy.

## Summary

Pending.

## Done-when evidence

Pending.

## Sweep of Log calls

Pending.

## Review findings

Pending.

## Rulings

Pending.

## Deploy notes for Mira

Pending.

## Proposed tasks

Pending.
