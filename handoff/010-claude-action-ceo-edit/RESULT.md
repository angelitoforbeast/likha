# Result 010: Claude Action and Claude Reason on the owner private page become editable by the CEO

Status: **in progress** on branch `feat/010-claude-action-ceo-edit`, cut from `develop` at `1b04668`.

## Plan

1. First commit: this handoff saved verbatim, this file, and the row in `handoff/README.md`.
2. Backend, test first: the route `POST owner/private/claude-action` (`owner.private.claude-action.save`) and a small `saveClaudeAction` next to `saveAction` in `OwnerPrivateController`: CEO only (404 otherwise), validation (422), `PageDayClaudeActionService::save()` / `clear()` with source `ceo:<user name>`, JSON with the saved values, source and time; the D6 route test replaces `test_no_write_route_mentions_claude`.
3. Frontend, test first: in each of the three places 009 put the columns (main table, per-date breakdown, page card of the new item layout), the Claude cells get the same edit pattern the Action cell has in that place (none where Action has none), all inside the CEO-only blocks; the main table's two columns get the neighbours' cell borders and row background, TOTAL row included (D5).
4. Review: `skeptic-reviewer` at standard depth (medium tier) on the finished diff with the three named checks; majors get one fix loop, the rest go to `TODO.md` with reasons.
5. Verify every done-when item with the command and its output, fill this file, commit; no push, no PR, no deploy.

## Summary

Pending.

## Done-when evidence

Pending.

## Where and how the cells are edited

Pending.

## Review findings

Pending.

## Rulings

Pending.

## Deploy notes for Mira

Pending.

## Proposed tasks

Pending.
