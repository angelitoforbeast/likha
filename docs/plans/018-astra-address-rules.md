# Plan 018: Astra identifies the J&T address like the classic checker

Spec: `docs/specs/018-astra-address-rules.md`. Branch `feat/018-astra-address-rules`, base
`90b900f`. Risk tier: high. No product code and no test is written before the reviewer's "go".
The numbered questions are in the result file under "Open questions"; this plan says what is built
under the recommended answer to each (Q1 to Q9 below point at them).

Short names: AE = `app/Services/AstraEncoder.php`, MC = `app/Services/MacroChecker.php`,
RR = `app/Services/AiCheckerRowRunner.php`. Line numbers are those of `90b900f`.

## 1. What the code shows (on `90b900f`)

- **The row method.** `AE::processRow` (AE:166-372) does, in one method: the model call
  (`resolveForm`), the list check of the model's line (`validateJnt`, AE:686), the guard
  (AE:206-220, text check `mentions`, AE:802), the six fields (AE:222-241), the no-line rule
  (AE:259-272), the final rule with the gate (AE:279-293), the analysis note, the customer-details
  block, the write and the trace. Everything between AE:201 and AE:305 is decision; it reads no
  database except the gate call (AE:285) and the row object it was given.
- **The gate.** `MC::validateRow($row, $final, $maps)` (MC:1080) has two callers: MC:432 and
  AE:285. The duplicate-phone rule is one `elseif` (MC:1109) that calls the private
  `duplicatePhoneRows` (MC:1168). The gate's other reads are four SELECTs (three blacklists, the
  whitelist; MC:1144-1155).
- **The classic mapper works on Astra's form as it is.** Measured in a throwaway test (written,
  run and deleted; nothing of it is committed) with the real list, calling
  `mapResolvedToList(['province','city','province_aliases'=>[],'confidence'=>'medium'])` and then
  `matchBarangayInList` on that city's labels:

  | Form (barangay / city / province) | Result |
  |---|---|
  | Holy Spirit / Quezon City / Metro Manila | METRO-MANILA, QUEZON-CITY, HOLY SPIRIT |
  | Poblacion 9 / Cotabato City / Maguindanao del Norte | COTABATO, COTABATO-CITY, POBLACION IX (note: "province follows the LIST") |
  | Brgy. Sta. Cruz / Cebu City / Cebu | CEBU, CEBU-CITY, SANTA CRUZ (POB.) |
  | Zone I-B / Dasmariñas City / Cavite | CAVITE, DASMARINAS-CITY, ZONE I-B |
  | Poblacion 2 / Cotabato City / (none) | POBLACION II |
  | Poblacion 10 / Cotabato City / (none) | city mapped, barangay null (no neighbour) |
  | Brgy 1 / Nasipit / Agusan del Norte | BARANGAY 1 (POB.) |
  | Poblacion / San Jose / (none) | nothing, note "ambiguous" (many provinces) |
  | Poblacion / Pandan / (none) | nothing, note "ambiguous" (ANTIQUE and CATANDUANES) |
  | `'; DROP TABLE macro_output; --` as city | nothing, note "wala sa list" |
  | a barangay of 10,000 characters / Cotabato City | city mapped, barangay null, under 1 ms |
  | Holy Spirit / QC / Metro Manila; Sta Cruz / Manila / Metro Manila | city not mapped (province only) |

  All the examples the stories name exist in the list (`QUEZON-CITY|HOLY SPIRIT`,
  `COTABATO-CITY|POBLACION`, `…|POBLACION I`, `…|POBLACION II`, `…|POBLACION IX`,
  `CEBU-CITY|SANTA CRUZ (POB.)`, `NASIPIT|BARANGAY 1 (POB.)`, `…|POBLACION 1`,
  `DASMARINAS-CITY|ZONE I-B`, `CALOOCAN|BARANGAY 28`, `BINONDO|BARANGAY 287`,
  `NAIC|IBAYO SILANGAN`). Only one city key is in more than one province by the exact key
  (`pandan`); bare names such as "San Jose" are ambiguous through the mapper's prefix forms. 40
  pairs of labels in one city share the same key (for example two spellings of
  `BGY. 1 - EM'S BARRIO (POB.)` in Legazpi City): the matcher returns null for them, as S-24.10
  expects.
- **Similarity, measured** (`similar_text`, the classic rule's function):
  "holy sprit"/"holy spirit" 95.2; "ibayo silngan"/"ibayo silangan" 96.3; "poblacion 1"/"poblacion 2"
  90.9; "zone 1 a"/"zone 1 b" 87.5; "san isidro"/"san isidro 1" 90.9; and three pairs of
  **different names without any number**: "santa marta"/"santa maria" 90.9,
  "bagong silang"/"bagong silangan" 92.9, "san antonio"/"san antonino" 95.7. The number rule of the
  spec stops the first group; nothing in the spec stops the second. `QUEZON-CITY|BAGONG SILANGAN`
  is a real label. The list also has 548 pairs, inside one city, where one label is the start of
  another (counted over the list file; this includes "(POB.)" variants). This is Q3.
- **Text normalising.** `MC::normBrgyKey` drops the word "barangay" (so "attached to a barangay
  word" cannot be read from its output), leaves `#` and `:` in place ("Brgy #1" gives `#1`,
  "Brgy: 1" gives `: 1`), turns a standalone `i`, `v`, `x` into 1, 5, 10 and handles a
  non-breaking space and capitals already. The new matcher therefore prepares the text itself
  before calling `normBrgyKey` (section 3.2). A 200,000-character text: `normBrgyKey` 3 ms, a
  full window scan of 24,400 words 5 ms.
- **The duplicate-phone query runs on sqlite.** Measured: `Schema::getColumnType('macro_output',
  'ts_date')` is `date` on the test table; two orders with the same phone and `ts_date` give
  `PHONE duplicate sa parehong petsa: #1 … (… · walang status)`. The statements are all SELECT
  (two of them read sqlite's own schema tables). No difference to handle.
- **A wrong type in the model's answer (S-33.4), measured through the run-row route:**

  | In the answer | Today |
  |---|---|
  | an array where a string is expected (`form.brgy`, `jnt.barangay`, `human_reason`, `intent`, `confidence`) | `ErrorException` ("Array to string conversion") inside `processRow`; RR catches it: HTTP 500, `AI check failed. Ref: log #N`, nothing written to the order. Not unhandled for the caller, and no raw text in the response |
  | a number | read as its digits; the row runs normally |
  | null, or `form` / `jnt` not an object | read as empty; the row runs (held, or as the other fields decide) |

  These are pinned in the test-only commit and stay as they are under both rule sets.
- **A 10,000-character form barangay with a valid model line** is PROCEED today and the
  customer-details block written to the order holds all 10,000 characters (measured: 10,323
  characters). The six fields do not (the barangay field gets the list label). This is Q6.
- **Settings.** `Checker1SettingsController::update` saves the CEO-only values inside
  `if (self::isCeo())` blocks after the shared validation (lines 99-117); the CEO section of the
  view is lines 130-182 of `settings.blade.php`, inside the main form. The night form is a
  separate form and is not touched. The settings store is `app_settings` (`key`, `value` text).
- **Commands** are found automatically from `app/Console/Commands` (`NightAstraTick` is there and
  `routes/console.php` does not register it), so a new command needs no change under `routes/`.
- **The night's orders.** A night step (`night_run_steps`, kind `astra`) holds its rows in
  `night_astra_rows` (`macro_output_id`, `state`, `proceed`, `code`, `log_id`). The replay reads
  these, not a date filter on the orders, so S-31.7 holds by construction.
- **Suite on the base in this worktree** (after the one `composer install` from the unchanged
  lock file; `git status` clean): `Tests: 589, Assertions: 6575, Errors: 1, Failures: 1,
  Skipped: 3.` The two red tests are the two the spec names.

**Can the switch-off path stay identical?** Yes. The request is built from the unchanged prompt
text; the new sentences are appended only when the switch is on. The differences with the switch
off are exactly: one more SELECT per row (reading the switch) and the `replay` key in the log.
Ten captured answers (S-23.2, S-23.3) and the existing tests pin the rest. Nothing found says the
job is much bigger than the budget assumes.

## 2. The known traps and how each is handled

| Trap | Handling |
|---|---|
| Astra's text check (short needle, compact substring anywhere, bare label before a number, number-only needle) and the classic similarity that bridges numbers | A new matcher class (3.2). Astra's `mentions` is moved unchanged and used only by the old rules. The classic `chatMentionsFuzzy` is neither called nor edited. |
| The model's own needs_human is overwritten in the trace | The rule function copies the model's flag before any rule runs and returns it in the `replay` block; the stored answer's `needs_human` keeps the program's value as today (S-30.3). |
| The intent rules skip the gate for cancel and inquiry | Under the new rules the gate runs for them when the line is complete; a pass is PROCEED with the checkmark, anything else keeps `CANCEL?` / `INQUIRY?` (3.4). Old rules: untouched branch. |
| The whole log of one Astra row is pinned; the night settings array is pinned | The `replay` key is the only change to that expected log, in the same commit that adds the key. The switch is stored under its own key by the main form; `NightRunSettings` is not touched. |
| Static caches in the classic helpers | Every test loads the real list through `MacroChecker::loadAddressMaps()`; no test builds a small list. |
| The duplicate-phone query on sqlite | Measured: it works (section 1). S-28.2 and S-23.5 run it for the first time in a test, in the test-only commit. |
| Two callers of the gate; the row method mixes calls, rules and writes | One optional argument with a default that keeps today's behaviour; MC:432 is not edited. The decision part of the row method moves to the rule function; the model call and the writes stay in AE. |
| Logs older than 90 days are deleted | The replay counts "log is gone" on its own line and says in its output that only nights whose logs still exist can be replayed. |

## 3. Design

A spec review by the separate reviewing agent ran on the first draft of this plan. Its ten
"needs revision" items are worked into this section (hard breaks, the compact rule, sibling
labels, a city written without "city", the first column of the table in 3.4, one shared history
helper, the inference for older logs, two task moves); its minors are in section 4 or in the
text. What it found and how each item was closed is listed in the result.

### 3.1 Files

| File | Change |
|---|---|
| `app/Services/AstraAddressRules.php` (new) | The rule function `decide()` and the pure helpers it needs, moved out of AE: `validateJnt`, `mentions`, `customerBlocks`, `cleanName`, `normalizePhone`, `composeAddress`, and the cut of a long Pancake history. No database, no HTTP, no clock. |
| `app/Services/AstraBarangayMatcher.php` (new) | The new text check (3.2). Pure. |
| `app/Console/Commands/AstraReplayAddressRules.php` (new) | The read-only command (3.6). |
| `app/Services/AstraEncoder.php` | Reads the switch; adds the prompt sentences and `human_kind` when on; calls `decide()`; writes the `replay` key; the moved helpers are called in their new place; `pancakeHistory` becomes public and static so that the replay reads the history through the same function (one query, one cut, no copy). |
| `app/Services/MacroChecker.php` | `validateRow(..., bool $checkDuplicatePhone = true)` and its use in the one `elseif` of the duplicate rule. Nothing else: no visibility change is needed. |
| `Checker1SettingsController.php`, `settings.blade.php` | The CEO box with its marker field. |
| `tests/Feature/NightRun/AstraAddressRulesTest.php`, `AstraReplayCommandTest.php`, one fixture file with the captured literals, `RunRowCharacterizationTest.php` (the `replay` key only) | Tests. |
| `qa/stories.md`, `TODO.md`, the spec's result, this plan | Docs. `docs/night-run.md` is Q8. |

Classic checker methods that Astra calls, **none of them edited** except the one optional
argument on `validateRow`: `mapResolvedToList`, `matchBarangayInList`, `normBrgyKey`, `normPlace`,
`normProv`, `computeStatusCode`, `validateRow`, `setHost`, and `fetchPancakeChat` (already called
today). `assessResolved`, `chatMentionsAny`, `chatMentionsFuzzy`, `fixBarangay`, `fixCity` are
not called.

### 3.2 The matcher

`AstraBarangayMatcher::confirm(string $text, string $label, array $cityLabels): array` returns
`['result' => 'phrase'|'compact'|'near'|'none', 'score' => int]`. `$cityLabels` (all labels of the
line's city) is required: rules 8 and 9 need it, and a default would switch them off silently.

1. **Prepare the text.** Invalid UTF-8 is scrubbed first. A non-breaking space, `#` and `:`
   become spaces. **Hard breaks**: a comma, a semicolon, a line break, a slash, an opening
   bracket and a dash with a space on either side cut the text into segments; an unspaced hyphen
   ("I-B") does not. The barangay words (`barangay`, `brgy`, `bgy`, `bgry`, with or without a dot)
   are remembered as "a barangay word stands directly before the next word of the same segment"
   and then dropped. A single letter directly followed by a dot ("V. Luna", "Q.C.") is an
   initial: it is not read as a Roman number and is not a suffix letter. Each segment then goes
   through `MC::normBrgyKey` (ñ, sta, sto, pob, Roman to Arabic, capitals, double spaces), giving
   its list of words.
2. **The needle** is `normBrgyKey` of the label without its parenthesis. Its special words are the
   words holding a digit and the words of one letter.
3. **No hit spans a hard break.** Every rule below looks inside one segment. "Brgy Poblacion" and,
   on the next line, "2 pcs" never becomes "poblacion 2"; "Zone 1, B. Aquino St" never becomes
   "zone 1 b".
4. **A number-only needle** (`BARANGAY 1 (POB.)` gives `1`, `BARANGAY 28` gives `28`) is confirmed
   only by the same number standing directly after a barangay word in the same segment. One digit
   is enough. It is never matched by similarity. "28 pcs, house 28" does not confirm it;
   "Barangay 287" does not confirm 28, nor the other way round (whole words are compared).
5. **Whole phrase**: the needle's words equal a run of words of one segment.
6. **Compact**: a run of whole words of one segment that, written together, equals the needle
   written together, the needle being at least 5 characters ("nabag o" for NABAGO), **and** the
   run's special words equal the needle's, word for word. "Poblacion 1 2 boxes" therefore never
   confirms POBLACION 12, and "poblacion 12" never confirms POBLACION 1.
7. **Near**: the needle is at least 5 characters and not number-only; a window of as many words
   as the needle has, inside one segment; `similar_text` of 85 or more; **and** the window's
   special words equal the needle's, in order ("poblacion 2" against POBLACION 1 fails here,
   "zone 1 a" against ZONE I-B too; "poblacion i" equals "poblacion 1" because Roman is already
   Arabic).
8. **What comes after the hit** (rules 4 to 7): if the next word of the same segment is a number
   of one to four digits, a number with a letter ("12a"), or a single letter (not an initial), and
   the label does not have it, the hit is rejected. One rejected hit anywhere in the text means
   "not confirmed": a text that says both "Poblacion" and "Poblacion 9" does not confirm the bare
   POBLACION (S-26.7). A number of five digits or more (a phone number) is not a continuation.
9. **Another barangay of the same city** (Q3, built under the recommended answer). A hit is
   rejected when (a) the customer's words around it, before or after and inside the same segment,
   make up another, longer label of the city ("Dugui San Vicente" does not confirm SAN VICENTE in
   Virac; "Zone 1 B." does not confirm ZONE I where ZONE I-B exists), or (b) for a near hit,
   another label of the city is at least as similar to the window as this label ("Poblacion Wst"
   does not confirm POBLACION EAST: WEST scores 96.3 against 88.9; "bagong silangan" does not
   confirm a label BAGONG SILANG where BAGONG SILANGAN exists).
10. **The form's own barangay wording** (S-25.8) is tried as a second needle only when
    `matchBarangayInList` maps that wording to this very label inside the city. "Poblacion" in the
    form never confirms POBLACION 2, and a form barangay of "Quezon City" confirms nothing.

The guard builds the text from the three sources of the spec (`all_user_input`, the customer's own
blocks from `customerBlocks`, the Pancake history the model was given), each source being its own
segments. Astra's own blocks are removed by `customerBlocks` as today (S-25.6); the history
fetched by the model's tool is not added, as today.

Known and accepted, because it follows from the settled near-match rule: a one-word near hit on a
name ("Mariano" against a label MARIANA, 85.7) is confirmed unless rule 9 finds the other name in
the same city. It is named in the result.

### 3.3 The rule function

`AstraAddressRules::decide(array $in, callable $gate): array` (Q1, Q2). `$in` holds: `rules`
(`old` or `new`), the parsed answer (form, line, intent, issues, needs_human, human_reason,
human_kind, confidence, and nothing else: the raw answer object is not passed in), the row's six
fields as read, the three text sources and the list maps. `$gate` is given by the caller and is
the only way out of the function: AE and the replay both pass
`fn ($final, $checkDup) => $mc->validateRow($row, $final, $maps, $checkDup)`. The function returns
the updates of the six fields, STATUS and the checker code, the analysis note, the verdict, the
gate result, `proceed`, the evidence lines, the pieces of the trace and the `replay` block. AE
keeps: the model call, the FORM evidence line, the block with its timestamp, the write (with the
night run's conditional update) and the trace assembly.

With `rules = old` the function is today's code moved, line for line, with `mentions` (moved
unchanged, still returning true or false) as the text check; the guard word in the log is then
`confirmed`, `none`, `exempt_high_confidence` or `not_run`. With `rules = new` these places
differ, and only these:

1. **Line.** `validateJnt` on the model's line as today. All three labels: source `model`.
   Otherwise, when confidence is not low and the form's city and barangay are both filled and
   neither is longer than 120 characters: `mapResolvedToList` on the form's city and province,
   then `matchBarangayInList` inside the mapped city. A line is made only when all of these hold:
   - the city maps to exactly one list city;
   - **a city written without the word "city" gives the same list city when the mapper is asked
     again with "city" added** (Q4). The mapper prefers a label that is spelled exactly as written
     over a label that differs by the word "city", so "Naga" with no province goes to
     `ZAMBOANGA-SIBUGAY|NAGA` although the customer may mean Naga City in Camarines Sur, and
     "Danao" to `BOHOL|DANAO` instead of Danao City in Cebu; the list has 28 such names. When the
     two answers differ, there is no line and the note says ambiguous. A province in the form that
     the list knows settles it inside the mapper, as today;
   - the barangay maps to exactly one label of that city;
   - the model's own line, where it named a valid city, names the same city.

   Source `program_map`, evidence `MAP: <the mapper's note> · barangay → <label>`. In every other
   case: no line, source `none`, the mapper's note goes into the list note, and today's no-line
   path runs. A city the program mapped is not written to the order unless the whole line was
   made (Q5). A model line with a valid province and city but no valid barangay still needs the
   form's city to map to that same city (R3).
2. **Guard.** The matcher of 3.2 instead of `mentions`; same outcomes as today: not confirmed and
   confidence not high means GUARD evidence, the issue line, needs_human, barangay not written;
   not confirmed and confidence high means accepted with the evidence line. Guard result words in
   the log: `phrase`, `compact`, `near`, `none`, `exempt_high_confidence`, `not_run`.
3. **The model's own flag.** It holds the row, except when `human_kind` is `label_not_found`, the
   line's source is `program_map` and the guard's result is `phrase`, `compact` or `near`.
4. **Intent.** `unclear` holds as today. `cancel` and `inquiry_only` no longer hold: see 3.4.
5. **Gate.** Called with `$checkDuplicatePhone = false`.
6. **The block** (Q6): each form value is written to the customer-details block as one line of at
   most 250 characters.

### 3.4 The final rule under the new rules

"Complete" below means what the code tests today (AE:284): the status code is the checkmark and
all six final fields are filled. That is also true when Astra made no line but the six fields the
order already had are valid in the list; the no-line rule has then already set "a person must
decide" (S-29.4), so such a row is in the "held" rows of the table, never in a PROCEED row.

| Complete | Intent | Held (the flag, the guard, the no-line rule, or `unclear`) | Gate | Code | STATUS |
|---|---|---|---|---|---|
| no | order / unclear | any | not run | the six-way code, as today | unchanged |
| no | cancel / inquiry | any | not run | `CANCEL?` / `INQUIRY?` | unchanged |
| yes | order / unclear | yes (S-29.4 is here) | run | `TO FIX` | unchanged |
| yes | order | no | hard / soft failure | `TO FIX` / `TO FIX - SHOP DETAILS` | unchanged |
| yes | order | no | pass | checkmark | PROCEED |
| yes | cancel / inquiry | yes (the cancel variant of S-29.4 is here), or the gate fails | run | `CANCEL?` / `INQUIRY?`, with a `GATE:` evidence line naming the failure | unchanged |
| yes | cancel / inquiry | no | pass | checkmark | PROCEED; the analysis note and the block's check line end with `· CANCEL? · PROCEED` (or `INQUIRY?`) |

### 3.5 The switch, the prompt, the log

- `AstraEncoder::SETTING_ADDRESS_RULES = 'astra_address_rules'`; `addressRulesOn()` returns true
  only when the stored value is exactly the string `1`, false on any exception;
  `storeAddressRules(bool)` writes `1` or `0`. `processRow` reads it once, after the key check and
  before the model call. Nothing the model or the customer supplies reaches that read.
- The form: inside the CEO section, a hidden field `astra_address_rules_present` and the checkbox
  `astra_address_rules`, with the label and the line of the spec. The controller saves only when
  the user is the CEO role and the hidden field is present. It saves next to the model and effort
  (line 100), that is before the check of a new API key: a post with a bad key still saves the
  switch, as it saves the model and effort today.
- The prompt: when on, two sentences are appended after today's text. The first: when
  needs_human is set only because no list entry was found, add the key `human_kind` with
  `label_not_found` to the JSON, for any other reason with `other`. The second: always fill the
  form's barangay, city and province as the customer wrote them, also when the line is left
  empty. When off, the request is today's, byte for byte. `human_kind` is read only under the new
  rules; any value other than the two words, and any other type, counts as missing.
- The `replay` block is a top-level key of the trace, after `searches`: `rules`,
  `model_needs_human`, `model_human_kind` (`label_not_found`, `other`, `none`; always `none` under
  the old rules, so that an extra key in the answer changes nothing, S-22.6), `model_intent`,
  `label_source`, `guard` (`ran`, `result`, `score`), `hay_chars` (`chat`, `history`, `cxd`),
  `dup_phone_checked` (true under the old rules, false under the new), and `list_crc` (Q7). A row
  without an answer has no trace body and therefore no `replay` key (S-30.5).

### 3.6 The replay command

`php artisan astra:replay-address-rules --night=YYYY-MM-DD` or `--step=<id>`.

- Options: exactly one; the date must be a real date in that form, the step id digits only; the
  step must exist with kind `astra`. Anything else: one fixed line, exit code 1, no row data.
- Reading: `night_astra_rows` of the step in chunks of 200 by id; per chunk one SELECT for the
  logs (`ai_checker_logs.id` = the night row's `log_id`) and one for the orders; per order the
  history through `AstraEncoder::pancakeHistory` (the same query and the same cut as the checker).
  No `update`, no `insert`, no cache, no log call, no file. The whole run is inside one `try`; on
  an error it prints `Replay stopped: <class of the error>` and exits 1.
- Per row with state `done`, a log and an order: the stored answer (form, the model's line,
  confidence, intent, reasons) and the six fields as they were before Astra (`before` in the log)
  go into `decide()` with `rules = new`; the three texts are read from the database as they are
  now; the gate is the same closure as in AE with the host from `app.url`, as the night job.
- The model's own flag: from the `replay` block when the log has one. For an older log it is
  inferred from the `evidence` **array** of the stored detail, element by element (never from the
  joined text column, where a line break in the model's words could imitate a line): the flag is
  taken as the program's when an element starts with the program's own `GUARD: barangay "` or
  `CHECK: existing prov/city/brgy vs list` and the stored reason starts with the program's own
  words (`kumpirmahin ang barangay (` or `hindi matukoy ni Astra ang J&T label`); otherwise it was
  the model's. A model that set the flag and gave no reason looks the same as the program's flag;
  the report therefore prints the rows "taken as the program's flag" on their own line and calls
  the count that rests on them an upper bound. Rows whose flag was the model's are decided twice,
  strict (the flag holds) and lenient (as if `human_kind` were `label_not_found`), and both counts
  are printed (S-32.8).
- "Could not be rebuilt exactly" (S-32.6): the chat's length differs from the log's `chat_chars`;
  the Pancake text's length differs from the logged one (the `replay` block, or for an older log
  the number in the evidence element that starts with `PANCAKE:` and ends with `kasama sa input`);
  the customer's blocks' length differs (only for logs with the `replay` block; for older logs the
  output says it cannot be checked); the list's check number differs (Q7) or the line Astra
  stored is no longer in the list.
- Output, every line a fixed text with counts and order ids, in this order: the night, the step
  and the list's fingerprint; rows by state; rows without a log, without an order, with an older
  log; rows Astra proceeded, and how many of them the new rules would not proceed (expected 0),
  with ids; held rows; of them "pass the address rules" and "pass everything"; the held rows by
  first blocking reason (the model's own flag, unclear intent, no line, the guard, a required
  field is blank, the gate, none), summing to the held count; the line's source (model, program);
  the rows taken as the program's flag; the strict and lenient counts; staff's PROCEED rows (would
  also proceed; same line; different line); staff's CANNOT PROCEED rows (would have passed the
  address rules; everything); rows that could not be rebuilt exactly, by cause; of the "pass
  everything" rows, how many the gate would hold for a same-date duplicate phone today (the gate
  run a second time with the duplicate rule on, so the whitelist counts as in the checker); and
  the fixed sentences on what a replay cannot know (the history as it was, edits since, history
  fetched by the model's tool, the list's version, and that item, COD, shop details and the
  blacklists are read as they are today).

## 4. Planned rulings (recorded in the result when built)

- R1: the first blocking reason gets a sixth part, "a required field is blank" (name, phone or
  address), because such a row is blocked by none of the five parts of S-32.7 and the parts must
  sum to the held count. Listed under "Story changes".
- R2: the form's own wording confirms a barangay only when the classic barangay matcher maps it
  to that very label (3.2 rule 10). Stricter than today, in the safe direction, new rules only.
- R3: a model line with a valid city never replaces the form's city in the mapping; the form must
  map by itself and agree. A form whose city does not map gives no line.
- R4: the flag is waived only for a line the program made (S-29.2). A model that returns a full
  valid line and still asks for a person with `label_not_found` is held.
- R5: "passes the address rules" counts a barangay accepted through the high-confidence exemption
  too, and the report prints how many of them were accepted that way.
- R6: for a log written before this change an edit of the customer-details column cannot be
  detected (no length was stored); the report says so instead of guessing.
- R7: the browser check is skipped: the worktree has no environment file and must not get one, so
  the application cannot be started here; the one new control is covered by the HTTP tests of
  S-22.2 and S-22.3 (the page's markup for the CEO and for another role).
- R8: the hard breaks, the initials rule and the "one to four digits" limit of 3.2 are details
  the spec's number rule leaves open; each can only turn a confirmation into "a person decides",
  except the initials rule, which is Q9.
- R9: the stricter matcher can hold a row that Astra proceeds today at medium confidence (for
  example a barangay followed by an unrelated number in the same segment). The replay shows such
  rows in the line "Astra proceeded, the new rules would not" (S-32.5 expects 0 for its fixture;
  on real data the number is information for the owner). Named under Merge danger.
- Added cases (listed under "Story changes" when built): S-24.8 gains the rows "Naga" and "Danao"
  without a province; S-33.4 gains `human_kind` as an array, a number and null with the switch
  on; S-25.9 gains a text with invalid UTF-8; S-26 gains the cases of the spec review (a number
  on the next line, "Zone 1, B. Aquino St", "Poblacion 1 2 boxes", "Poblacion Wst", "Dugui San
  Vicente", "Brgy. V. Luna", "Holy Spirit Q.C.").

## 5. Tasks

Every task is reviewed by `skeptic-reviewer` at adversarial depth (developer and reviewer on the
high-tier models). None is parallel-safe: the spec puts the tests of S-22 to S-30 and S-33 into
one file. The slice is added to `qa/stories.md` (at the end) in task 0.

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 0 | **Test-only commit on the unchanged product code.** The ten stored answers with their captured literals (fields, STATUS, code, note, block, evidence, gate, request hash, number of calls, cost); the classic fixture with the setting row present; the gate and the classic mapper, barangay matcher and text check (the last through `assessResolved`, it being private) on fixed inputs; cancel, duplicate phone, the five night rows, the wrong types of S-33.4, as they are today. Green on the base; the result says what turns each red. | backend | high | S-22.6, S-23.2 to S-23.6 (today's half), S-27.6, S-28.2, S-28.3, S-29.7 (existing tests named), S-29.8 (today's half), S-33.4 |
| 1 | **The matcher** (the pure function only). | backend | high | S-25.2, S-25.5, S-25.8, S-25.9 (the long text and invalid UTF-8), S-26.1 to S-26.8 with the added cases, S-33.5 |
| 2 | **The rule function with the old rules, the gate argument, the switch reader, the `replay` block.** The move of the decision code and its helpers out of AE with no change of result; `validateRow`'s optional argument; `addressRulesOn()`; the `replay` key in the log. | backend | high | S-22.1, S-22.4, S-22.7, S-23.1, S-23.5, S-30.1 to S-30.5 |
| 3 | **The new rules, part 1: the line and the guard.** The mapping fallback, the matcher in the guard with its three text sources, the prompt sentences and `human_kind`, the block's one-line values. | backend | high | S-24.1 to S-24.14, S-25.1, S-25.3, S-25.4, S-25.6, S-25.7, S-25.9 (a Pancake query that throws), S-26.9, S-29.3, S-29.4, S-29.5, S-23.3, S-23.6, S-33.1 (row), S-33.2, S-33.3, S-33.4 (`human_kind`) |
| 4 | **The new rules, part 2: the flag, the intent, the duplicate phone.** | backend | high | S-27.1 to S-27.7, S-28.1, S-28.4 to S-28.6, S-29.1, S-29.2, S-29.6, S-29.8, S-22.8, S-23.4 |
| 5a | **The settings switch: saving.** | backend | high | S-22.2, S-22.3, S-22.5 (the stored value and the response) |
| 5b | **The settings switch: the box on the page.** | frontend | medium | S-22.2, S-22.3 (the page shows the box ticked or unticked for the CEO, none for another role) |
| 6 | **The replay command.** | backend | high | S-31.1 to S-31.9, S-32.1 to S-32.9, S-33.1 (replay) |
| 7 | **Result, stories' last-run column, accepted minors.** The main session runs lint on every changed PHP file and the full suite. | n/a | low | the done-when list |

Every case of S-22 to S-33 is in one task above (S-22.2, S-22.3, S-25.9, S-33.1 and S-33.4 are
split between two tasks by the halves named). One test per case, named with its case id; variants
of one case are rows of a table inside that test. About a hundred tests is more than three times
the product code; the spec asks for one named test per case, so the suite-size rule of the kit is
noted as a conflict in the result rather than followed.

Commits: `test:` for task 0, one `feat:` per task 1 to 6 (test and code together, green), `docs:`
for task 7. No push, no PR.

## 6. What is not done

Nothing under "Out of scope" of the spec. In particular: no pick-from-list model call, no check of
the city against the text, no change to the classic checker's results, no change to
`NightRunSettings`, the night job or the logs page, the switch is not turned on, and the replay
is not run on real data.
