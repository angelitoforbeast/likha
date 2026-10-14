# Spec 025: JNT hold page label "JNT Hold Item"

Weight: bounded. Shape: change. Risk tier: low (one visible text on a signed-in staff page; no input, no data, no query).

## Why
The owner wants the page at /jnt/hold to show "JNT Hold Item" instead of "JNT Hold Items".

## Where
- resources/views/jnt/hold.blade.php line 3: `<x-slot name="heading">JNT Hold Items</x-slot>`. A search of the repo (outside vendor, docs and handoff) found no other place with this exact text. Check the page's title and any navigation/sidebar label that names this page; if one of them shows "JNT Hold Items" too, change it the same way so they agree. Do not change other wording.

## Decisions already made (do not reopen)
| Decision | Who, why |
|---|---|
| The label becomes exactly `JNT Hold Item` | The owner asked for it in these words |
| No other text, style, route or behaviour changes | Owner's request is this one label |

## Stories
- S-025.1 (P1): Given a signed-in user opens /jnt/hold, the page heading reads "JNT Hold Item" and not "JNT Hold Items".

## Threat model
Nothing untrusted is touched: a static string in a Blade view.

## Done when
1. `git -C <worktree> grep -n "JNT Hold Items" -- . ':!docs/specs' ':!handoff' ':!vendor'` prints nothing.
2. `git -C <worktree> grep -n "JNT Hold Item" -- resources` shows the new heading in resources/views/jnt/hold.blade.php.
3. A feature test (or an assertion added to an existing test of this page, if one exists) that renders /jnt/hold as a signed-in user with access and asserts it sees "JNT Hold Item" and does not see "JNT Hold Items"; it passes. If rendering the page needs data or setup that makes a test heavy, a view render test of the heading is enough: say which in the result.
4. The full suite adds no new failures against the base (known red: ExampleTest, ImportStartTest in a fresh worktree). Paste the summary line.
5. The branch holds exactly one commit on top of 3d2b69a: `git -C <worktree> log --oneline 3d2b69a..HEAD` shows one line.

## Out of scope
Every other file, wording, style or route; no migration, no dependency, no config change, no push, no merge, no deploy.

## Budget
Small job. At most 2 attempts at any one failing step; then write what you tried in the result file, commit, stop.

## Report back
Fill docs/specs/025-jnt-hold-label.result.md: the case table, the done-when checklist with the command output for each, the test summary line, and the commit.
