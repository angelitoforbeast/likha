# Result: spec 018 Astra identifies the J&T address like the classic checker

> Committed beside the spec: names no people and no decision ids.

**Status:** partial: the plan is written and waits for the reviewer's "go". No product code and
no test exists yet.
**Date:** 2026-10-08
**Branch / PR:** `feat/018-astra-address-rules` (base `90b900f`), no PR, not pushed
**Preview or run link:** n/a

## Summary

This run produced the plan only: `docs/plans/018-astra-address-rules.md`. It says what the code
shows today (with measurements), how each known trap is handled, the design of the matcher, the
rule function, the switch, the log's `replay` block and the replay command, and the tasks with
the cases each one covers. A spec review by the separate reviewing agent ran on the first draft;
its ten "needs revision" items are worked into the plan (table under "Tests"). Nine questions for
the reviewer are under "Open questions", each with a recommended answer and what is built without
an answer. The job fits the budget, and the switch-off path can stay identical: with the switch
off the only differences are one more SELECT per row (reading the switch) and the `replay` key in
the log.

## Case table

Not built yet. The plan (section 5) assigns every case of S-22 to S-33 to a task.

## Story changes

None made yet. Planned, for the reviewer to see with the plan (each is added only on "go"):

- S-24.8 gains two rows: a form city "Naga" and "Danao" with no province gives no line (Q4).
- S-26 gains the cases found by the spec review: a number on the next line after "Brgy
  Poblacion"; "Zone 1, B. Aquino St"; "Poblacion 1 2 boxes"; "Poblacion Wst" against POBLACION
  EAST; "Dugui San Vicente" against SAN VICENTE; "Brgy. V. Luna" against a BARANGAY 5 label;
  "Holy Spirit Q.C." (Q3, Q9).
- S-25.9 gains a text with invalid UTF-8 (no exception, not confirmed).
- S-33.4 gains `human_kind` as an array, a number and null with the switch on.
- S-32.7 gains a sixth blocking reason, "a required field is blank" (name, phone or address), so
  that the parts sum to the held count.
- S-30.1 gains the key `list_crc` if Q7 is answered yes.

## Done-when checklist

- [ ] Every case of S-22 to S-33 passes, each with a test named after it. Not built.
- [ ] Red line per feat slice; characterisation values captured on the base in a test-only commit.
      Not built; it is task 0 of the plan.
- [ ] Switch off: request, fields and log (apart from `replay`) are those of the base. Not built;
      the plan (end of section 1) says why it can hold.
- [ ] `git diff 90b900f --stat` lists only the allowed files. So far: the spec, this result and
      the plan. `composer.json` and `composer.lock` show no diff after the one install.
- [ ] The replay command runs SELECT only and prints no customer text. Not built.
- [ ] `php.bat -l` on every changed PHP file. No PHP file changed yet.
- [x] The full suite before: `Tests: 589, Assertions: 6575, Errors: 1, Failures: 1, Skipped: 3.`
      on `90b900f` in this worktree, the two red tests being the two the spec names
      (`ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`
      and `ExampleTest`). The "after" run comes with the build.
- [ ] Adversarial review of the matcher, the rule function and the command. Not built; the spec
      review of the plan is under "Tests".
- [ ] The exact command for a night on the server, each output line explained. Drafted in the
      plan (3.6); final text comes with the build.
- [ ] The result is filled in. This is the plan state of it.

## How to run

Nothing to run yet. For the build (from this worktree, after the one install that is already
done): `"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit
tests/Feature/NightRun/AstraAddressRulesTest.php` and the same with
`AstraReplayCommandTest.php`; the full suite with no file argument.

## The replay command

Not built. Planned form: `php artisan astra:replay-address-rules --night=YYYY-MM-DD` (the night's
date, the morning the run happened, Manila time) or `--step=<id>`. The planned lines of its output
are listed in the plan, section 3.6.

## Rulings

None made in code yet. The rulings the plan intends are its section 4 (R1 to R9); they are
recorded here when built. Two already apply to this run:

- Ruling: the throwaway test that measured the claims of the plan's section 1 (the mapper on the
  stories' examples, the similarity scores, the duplicate-phone query on sqlite, wrong types in
  the model's answer, the timing on a 200,000-character text) was written, run and deleted;
  nothing of it is committed — the start prompt allows it for measuring a claim — no cost.
- Ruling: `qa/stories.md` is not touched in this run; the slice is added in task 0 on "go" — the
  run is the plan only — no cost; the stories are in the committed spec meanwhile.

## Deferred minors

None yet (no code review has run). From the spec review, the minors not worked into the plan:

- A form value that holds a line break and `---` can end Astra's own block early today, so that
  on a later run the rest counts as the customer's text. Under the new rules Q6 closes it; under
  the old rules it stays as it is (the switch-off path must not change). Proposed as a task.
- A one-word near match between two names ("Mariano" against MARIANA, 85.7) is confirmed unless
  the other name is a barangay of the same city. This follows from the settled near-match rule.

## Merge danger

Nothing to merge yet. For the build, as planned: a two-way door. The new rules are behind one
switch that is off by default; switching off restores today's decisions for the next row. With
the switch off the changes are the `replay` key in each Astra log and one more read of the
settings per row. Two things to know before switching on: a CEO page that was open before the
switch was turned off sets it on again on its next save (the page sends what it shows); and the
stricter number rules can hold a row that Astra proceeds today at medium confidence (the replay
prints that count). Revert: untick the switch; or revert the squash commit (no migration, no
stored data to undo except the one settings row).

## Conflicts with CLAUDE.md

- The kit's test rule says to keep the tests of a module under about three times its code; the
  spec asks for one named test per case (about a hundred). The plan follows the spec, with the
  variants of one case as rows of a table inside its test. To be confirmed by the reviewer's "go".
- The kit says docs travel with the change; the spec's done-when lists the files that may change
  and `docs/night-run.md` is not among them. This is Q8.

## Tests

No test written (plan run). The base suite: see the checklist.

Spec review of the plan (the separate reviewing agent, spec-review mode, read-only; it checked
the plan's rules against the real list with read-only scripts):

| # | Finding ("needs revision") | Closed in the plan by |
|---|---|---|
| N1 | A match could span a comma or a line break: "Brgy Poblacion" and "2 pcs" on the next line would confirm POBLACION II; "Zone 1, B. Aquino St" would confirm ZONE I-B | 3.2 rules 1 and 3: hard breaks, no hit spans one |
| N2 | The compact rule joined number words: "Poblacion 1 2 boxes" would confirm POBLACION 12 | 3.2 rule 6: the run's special words must equal the needle's |
| N3 | A typo of a sibling label: "Poblacion Wst" would confirm POBLACION EAST (88.9; WEST is 96.3); 560 such pairs in the list | 3.2 rule 9 (b); the city's labels are a required argument |
| N4 | A longer label that ends with the needle: "Dugui San Vicente" would confirm SAN VICENTE (Virac has both); 264 such pairs | 3.2 rule 9 (a) |
| N5 | A city written without "city" and without a province is not a tie for the classic mapper: "San Isidro, Naga" goes to `ZAMBOANGA-SIBUGAY|NAGA`, "Danao" to `BOHOL|DANAO`; 28 such names | 3.3 point 1, second condition (Q4) |
| N6 | The first column of the final-rule table read as "Astra made a line"; a row with no line but valid existing fields (S-29.4) would have skipped the gate | 3.4: "complete" defined as the code tests it; the S-29.4 rows named |
| N7 | The replay would have copied the history query and its cut | 3.1 and 3.6: one shared function |
| N8 | The inference for older logs: a model flag without a reason looks like the program's; evidence must be read from the stored array, not the joined text; two `PANCAKE:` lines can exist | 3.6: own line and "upper bound"; array elements by their start; the element ending `kasama sa input` |
| N9 | S-25.3, S-25.6 and half of S-25.9 test the guard's text assembly, not the matcher | section 5: moved to task 3 |
| N10 | No test for a wrong type in `human_kind` | section 5, task 3; planned story change |

Its minors taken into the plan: a single letter with a dot is an initial (3.2 rule 1, Q9); the
form's own wording confirms only through the barangay matcher (3.2 rule 10); the guard word under
the old rules (3.3); where the controller saves relative to the API-key check (3.5); two prompt
sentences, not three (3.5); the raw answer is not passed into the rule function (3.3); the
replay's "cannot know" sentences name item, COD, shop details and blacklists, and the duplicate
information line respects the whitelist (3.6); the classic text check is pinned through
`assessResolved` (task 0); a text with invalid UTF-8 (3.2 rule 1). What it found sound: every
case of S-25 and S-26 against the rules apart from the findings; no new path to PROCEED with the
switch off; the table of 3.4 against S-27; the holds of S-29 and S-28; the `replay` block's
content; that the replay's reads write nothing; that every case is assigned to a task.

## Open questions

Each has a recommended answer; without an answer the recommended one is built.

1. **One rule function for both rule sets, or for the new rules only?** The decision part of
   Astra's row method (about a hundred lines) either moves into the rule function with a flag
   `old` / `new`, or stays where it is while the function holds the new rules only. Recommended:
   move it, one function with both. Two copies of the final rule would drift apart, and the ten
   captured answers plus the existing tests pin the move. Cost: the diff of
   `AstraEncoder.php` is larger and the switch-off code is moved (not changed). Without an
   answer: one function with both rule sets.
2. **The gate inside a pure function.** The gate reads the database, the rule function must not.
   Recommended: the caller hands the gate in as a function (`decide($in, $gate)`); the checker and
   the replay pass the same one, tests pass a fixed answer. Without an answer: that.
3. **Sibling barangays (not in the spec's number rules).** The 85 rule also confirms a different
   name without any number: "santa marta" against SANTA MARIA is 90.9, "bagong silangan" against
   BAGONG SILANG 92.9, "Poblacion Wst" against POBLACION EAST 88.9; and a whole phrase confirms
   SAN VICENTE in a text that says "Dugui San Vicente", both being barangays of Virac.
   Recommended: reject a hit when the customer's words around it are another, longer label of the
   same city, and reject a near hit when another label of the same city is at least as similar
   (plan 3.2 rule 9). It can only turn a confirmation into "a person decides". Without an answer:
   built.
4. **A city written without "city" and without a province the list knows.** The classic mapper
   then prefers the label spelled exactly as written: "Naga" goes to Naga in Zamboanga Sibugay,
   not Naga City in Camarines Sur; "Danao" to Danao in Bohol, not Danao City in Cebu (28 such
   names in the list). The classic checker has a judge call behind it; Astra would PROCEED.
   Recommended: no line when asking the mapper again with "city" added gives a different city
   (plan 3.3 point 1); the row goes to a person, the note says ambiguous. Without an answer:
   built, with the two rows added to S-24.8.
5. **Does the program write a mapped city and province when it cannot settle the barangay?**
   S-24.10 says only that BARANGAY is not written. Recommended: no, the program writes its labels
   only as a whole line; a partly valid line of the model is still written as today. Without an
   answer: not written.
6. **Long or multi-line form values in the customer-details block.** Today a form barangay of
   10,000 characters is written into the block in full (measured: a block of 10,323 characters on
   a PROCEED row), and a value with a line break and `---` can end the block early. S-24.14 says
   no 10,000-character value is written to a field. Recommended: under the new rules each form
   value goes into the block as one line of at most 250 characters; with the switch off the block
   stays byte for byte as today. Without an answer: built so. The alternative reading ("field"
   means the six fields only, the block untouched) needs no code and would leave S-24.14's
   10,000 characters in the block.
7. **One more key in the `replay` block: `list_crc`,** an integer check number of the list file.
   Without it the replay cannot tell, row by row, that the list changed since the night (S-32.6);
   it could only notice a stored line that no longer exists. Recommended: add it (an integer, so
   the block still holds only booleans, integers and fixed words); S-30.1's list of keys grows by
   one. Without an answer: added.
8. **`docs/night-run.md`.** The kit says docs travel with the change; the done-when file list
   does not name this file. Recommended: a short section there (the switch, the replay command,
   reading its output), the same text as in this result. Without an answer: the section is added
   and the conflict is noted here, because the kit's rule wins over the spec.
9. **An initial is not a suffix letter.** The spec rejects a phrase hit when the text continues
   with a single letter the label does not have. Read literally, "Holy Spirit Q.C." no longer
   confirms HOLY SPIRIT, and "Brgy. V. Luna" reads as barangay 5. Recommended: a single letter
   directly followed by a dot is an initial, neither a number nor a suffix letter; "Zone 1 B" and
   "Zone 1-B" are still rejected for ZONE I, and "Zone 1 B." too wherever ZONE I-B is a label of
   the city (question 3). Without an answer: built so.

## Process suggestions

- A spec review before the plan goes out paid for itself here: it found four ways the planned
  matcher would have confirmed a wrong barangay and one wrong-province mapping, all with real
  labels of the list, before any code existed.
- The fake HTTP answer of the test helpers keeps the first registered answer: a second
  `Http::fake` in the same test is ignored for the same address. Tests that run several answers
  in one test need one fake that reads the current answer. Found while measuring (the first
  measurement of S-33.4 was wrong because of it and was redone).

## Proposed tasks

- Collapse line breaks in Astra's customer-details block under the old rules too — a form value
  with a line break and `---` can end the block early, so a later run reads the rest as the
  customer's text — normal (it changes the switch-off output, so it is not in this spec).
- Give the classic checker the same "city without the word city" tie check — "Naga" or "Danao"
  without a province maps to the smaller town there too; only its judge call stands behind it —
  normal.
- Clean the list's 40 pairs of labels that differ only by spacing or brackets (for example
  `BGY. 1 - EM'S BARRIO (POB.)` twice in Legazpi City) — the matcher returns "no pick" for them,
  so those barangays always go to a person — low.

## Suggested next steps

1. The reviewer answers the nine questions (or says "go" to take the recommended answers).
2. Build in the order of the plan's section 5: the test-only commit first, then the matcher, the
   rule function, the new rules, the switch, the replay command.
3. After the build and before the switch is turned on: run the replay for one night on the
   server and read its numbers with the owner.
