---
name: ship
description: Finish a task the kit's way — verify with a clean install and the full suite, tidy the commits, add the changelog entry, then either open the pull request against develop with the result as its body (branches flow) or push main (simple flow, one-shot projects). Use when the work is done and reviewed ("ship it", "open the PR", "ship handoff NNN").
---

# /ship

Turns a finished, reviewed branch into a pull request. Run it from the branch's checkout (main
checkout or a worktree under `.claude/worktrees/`). It never merges: merging is Mira's, after her
code review, per `CLAUDE.md` "Git flow"; the guard blocks `gh pr merge` in this repo.

## Steps

1. **Anchor.** `git rev-parse --show-toplevel`, `git branch --show-current`, and the flow from
   `.dev-kit.json` (`flow`: `branches` or `simple`). In the branches flow refuse on `main` or
   `develop`; in the simple flow the branch is `main`. Say whether this is a worktree.
2. **Verify on the tree that ships.** Clean install, then lint, typecheck, build and the full suite,
   with the commands in `CLAUDE.md` `## Stack`. Keep the final summary lines verbatim; name any red
   test even if it's pre-existing. Stop here if anything the task touched is red.
3. **Tidy the commits.** `git status --short`, `git log --oneline develop..HEAD`. Every commit is
   Conventional Commits, no attribution line. Uncommitted changes are grouped into logical commits
   (test with the code they cover; docs with the behaviour they describe). Don't rewrite pushed
   history.
4. **Changelog.** If the repo has `CHANGELOG.md`, add an entry under the unreleased heading: what
   changed for a user or operator, one line per item, and any migration or config step. Skip when
   the repo has no changelog.
5. **Result.** For a handoff, `handoff/NNN-name/RESULT.md` must be complete (no template
   placeholders): summary, done-when checklist with evidence, rulings, deferred minors, merge danger,
   tests, suggestions for Mira. The PR body is built from it.
6. **Simple flow:** `git push origin main` and stop; there is no PR. Report as in step 7.
   **Branches flow:** push and open the PR against `develop`, body from a file (never inline, so
   guard words in the text don't trip the hooks):

   ```bash
   git push -u origin "$(git branch --show-current)"
   gh pr create --base develop --title "<type>: <what shipped> (handoff NNN)" --body-file <file>
   ```

   The body carries: what shipped per task, the final green output (naming any red test), accepted
   findings with reasons, and a **Merge danger** section (one-way or two-way door, blast radius, how
   to revert). Write the body to a file outside the repo or a gitignored path.
7. **Report:** the PR link, the verification summary, and message Mira that the PR is open. Then
   stop: Mira merges it (or sends it back); nothing else to do on this branch.

## Rules

- Never `--force`, never `--no-verify`, never `gh pr merge` here. In the branches flow never push to
  `main` or `develop` (the guard blocks it); in the simple flow push only `main`.
- Never read or edit `.env` files; the verification step runs with whatever the environment already
  provides.
- Branches flow: if the branch has no `develop` base (`git merge-base develop HEAD` fails), stop and
  say so.
