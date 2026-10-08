# Plan 016: Night run rows on Checker 1

Spec: `docs/specs/016-night-rows-checker1.md`. Branch `feat/016-night-rows-checker1`, base
`525ef44`. Risk tier: high. No product code and no test is written before the reviewer's "go".
The numbered questions are in the result file under "Open questions"; this plan says what is built
under the recommended answer to each.

## 1. What the code shows (on `525ef44`)

- `MacroOutputController::index` (lines 1068 to 1291) builds one `$baseQuery` (date, then PAGE,
  then the Filter value). The six chip counts, the records, the pagination and the Page list are
  all clones of it. One more `where` on `$baseQuery` therefore narrows all four together, as the
  spec decided. The date default is line 1073; the date is parsed by Carbon on line 1076.
- The status chips (`index.blade.php:396-397`) are built from `request()->all()`, and both
  pagination blocks use `withQueryString()`. They **already carry any extra parameter**, so the
  chips and the pagination keep `night_step` with no change. Only the GET form (`filtersForm`,
  line 320) drops it.
- `resetCheckerAndSubmit(form)` (line 666) is called by the date picker (line 325) **and by the
  Page dropdown** (line 805). The removal of `night_step` on a date change therefore goes on the
  date input itself, not into that function, or choosing a Page would lose the filter.
- How the toolbar buttons build their requests today (none is changed):

  | Button | Sends | Where the date comes from |
  |---|---|---|
  | Validate 1 (line 688) | `date`, `PAGE`, `status_filter` | the date input |
  | Download (line 849) | `date`, `PAGE`, `download_all` | the date input |
  | VALIDATED badges (line 1421) | `date`, `PAGE` | the date input |
  | AI Checker / Astra Check count and start (lines 1728-1738, 1872) | `date`, `PAGE`, `scope` | **the address bar** |
  | Validate (lines 1249, 1548) and ITEM CHECKER (line 1334) | the ids of the rows **in the table** | none |

  Two consequences are open questions 2 and 3. A third: after a successful Validate 1 the page
  goes to an address the server builds (`MacroOutputController.php:660-672` and `:864-876`) with
  `date`, `PAGE`, `status_filter` and the To Fix filter only, so the night filter is left without
  a word. Open question 8.
- The app runs Laravel's default global middleware (`bootstrap/app.php` removes none), so every
  input is trimmed and an empty string becomes null before a controller sees it. Measured in a
  throwaway test: the request `night_step=%201` reaches the controller as `"1"`. A literal `+` in
  a URL is a space, so `night_step=+1` arrives as `"1"` too (a real plus sign, sent as `%2B1`,
  arrives as `"+1"` and is rejected). A value of spaces only arrives as null, like an empty one.
  This is open question 1.
- `NightRunController::astraStep()` is private; its rule is "digits, the tables exist, the step
  exists with kind `astra`". `NightRunSummary` counts `for_person` as `state = 'done'` and
  `proceed` false, grouped in PHP. Neither file may be touched, so the rule "the night's rows" is
  written once more, in the new class (section 2).
- The logs page (`macro_checker.logs`) is open to whoever `AiCheckerAccess::allows()` lets in (CEO,
  Marketing, Marketing OIC and the extra allow list); `_night_run.blade.php` prints the collapsed
  summary as **one** `{{ }}` expression (line 100).
- `NightRunPageTest::test_the_ceo_sees_the_night_with_every_action` asserts the collapsed line as
  one exact string (`'9 rows · 1 PROCEED · 1 for a person · 1 failed · …'`). An anchor around
  "1 for a person" breaks that one assertion. Open question 5.
- An invalid `date` errors today (throwaway test, signed in): `date=abc` and `date=2026-13-45`
  give 500 (`Carbon\Exceptions\InvalidFormatException`), `date[]=1` gives 500 (`TypeError`). Not
  this spec's to change; it is under Proposed tasks in the result.

**Can the full page be drawn in a test?** Yes, with little. A throwaway test (written, run and
deleted; nothing of it is committed) drew `/encoder/checker_1` with status 200 after adding only:
the `tasks` table (as `NightRunPageTest` already does) and ten nullable columns on the test
`macro_output` table: `HISTORICAL LOGS`, `edited_full_name`, `edited_phone_number`,
`edited_address`, `edited_province`, `edited_city`, `edited_barangay`, `edited_item_name`,
`edited_cod`, `status_logs`. Each row is a `<tr data-id="…">`, so the tests read the listed ids
from the markup.

**Suite on the base in this worktree** (after `composer install --no-interaction` from the
unchanged lock file; `git status` clean, no diff on `composer.json` or `composer.lock`):

- `php.bat vendor/phpunit/phpunit/phpunit`: `Tests: 544, Assertions: 5598, Errors: 1, Failures: 1,
  Skipped: 3.` The two red tests are the ones the spec names (`ExampleTest`, and
  `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`).
- `php.bat artisan test`: `Tests: 2 failed, 485 warnings, 57 passed (5598 assertions)`. Same two
  red tests. The 485 "warnings" are every test that boots the application; the printer shows a
  PHP warning from `file_get_contents(...)` on a path it cuts off, most likely the environment file
  this worktree does not have and may not create (not verified). Plain PHPUnit shows none. The
  result will carry both commands' summaries, before and after.

## 2. The change

### 2.1 One new class: `app/Support/NightStepFilter.php`

Resolves the parameter and holds the rule "the night's rows" in one place.

- `NightStepFilter::fromRequest(Request $request): ?self`
  - value absent or empty: returns `null` **before any query** (the page as today).
  - not a string, or not `/\A[0-9]{1,9}\z/`: a "not valid" filter, **without any query** (so a
    10,000-digit value, an array, `1 OR 1=1` never reach the database).
  - otherwise `(int)` and one lookup: both tables exist (`Schema::hasTable`) and
    `NightRunStep::where('id', $id)->where('kind', NightAstraRun::KIND)->first()`. No step: "not
    valid". A step of another kind is the same "not valid"; nothing of it is kept.
  - valid: keeps only the step id (int), the night date and the orders date
    (`NightAstraRun::ordersDate()`).
- `narrow($query)`: valid adds
  `whereIn('macro_output.id', sub-select macro_output_id from night_astra_rows where step_id = ? and state = 'done' and proceed = false)`
  through the query builder (bindings only, no raw SQL, the same on mysql and pgsql); not valid
  adds `whereRaw('1 = 0')`. Either way it is an `AND` on the existing query, so it can only narrow.
- `nightRowCount(): int`: the step's current number of night rows (the M of the line).

Under the recommended answer to question 1 the value is read with `$request->query('night_step')`
like every other input of the app. If the answer is "spaces must be rejected", the class reads the
value from the raw query string instead (a short scan of `QUERY_STRING`, no `parse_str`, about
twelve more lines and their tests); nothing else in the plan changes.

### 2.2 `MacroOutputController::index` (the only method touched, plus one import line)

1. `$night = NightStepFilter::fromRequest($request);` before the date line.
2. The date default becomes: the request's `date` if filled, else the step's orders date when the
   filter is valid, else yesterday as today. Under the recommended answer to question 2, a valid
   filter with no `date` is answered with a redirect to the same address with `date` added and
   every other parameter kept (`PAGE`, the Filter value, `status_filter`, `page`), so that the
   address bar and the picker agree; the test of S-07.6 follows the redirect.
3. Right after the date condition and **before** the PAGE condition: `$night?->narrow($baseQuery)`,
   then, for a valid filter, `N = (clone $baseQuery)->count()` (the night's rows on this date,
   before Page, Filter and chip) and `M = $night->nightRowCount()`.
4. One more view variable, `$nightFilter`: `null` (no filter), or `['valid' => false]`, or
   `['valid' => true, 'step_id' => int, 'night_date' => 'Y-m-d', 'orders_date' => 'Y-m-d',
   'shown' => N, 'total' => M, 'date_differs' => bool]`. Integers, dates and booleans only: the
   view has nothing of a row or of `night_astra_rows` to print. `date_differs` compares the date
   **as Carbon parsed it** (`Y-m-d`) with the orders date, never the raw text: `date=2026-10-4` is
   the same day and must not raise the notice. The redirect and "Show all rows" use that parsed
   date too.

With no `night_step` the method runs the same statements as today plus one `null` check: no query
on the night tables (pinned by S-12.2).

### 2.3 `resources/views/macro_output/index.blade.php`

- Inside `filtersForm`, only when `$nightFilter` is not null:
  `<input type="hidden" name="night_step" id="nightStepHidden" value="…">`. The value is the
  integer step id for a valid filter and the fixed `0` for a not valid one (question 4): the new
  markup never prints the address-bar text. (The chips and the pagination links repeat the whole
  query string as they do today, escaped; S-10.5 and S-10.6 assert the page still draws with
  them.)
- The date input's `onchange` first removes `#nightStepHidden`, then calls the existing function.
- One line inside `#fixed-header`, under the form, in the page's `text-sm` style, `flex-wrap` so
  it wraps on a phone (the existing "Sticky offset" script measures the header on load and on
  resize, so the table moves down with it):
  - valid: `Night run filter · night of Mon, Oct 5 (orders of Oct 4) · 2 of 3 shown · Buttons above still use the whole date · Show all rows`
  - date differs: `… · 0 of 3 shown · this date is not the night's orders date · Buttons above …`
  - not valid: `Night run filter not valid. No rows shown. · Show all rows`
  - "Show all rows" is a link to the same route with the current query minus `night_step` and
    minus the pagination's `page`, with `date` set to the date shown.
- Nothing else: no column, no change to a script, a button, a chip or a row.

### 2.4 `resources/views/encoder/_night_run.blade.php`

- Collapsed line: the one expression is split in three so that "N for a person" can be an anchor
  (`whitespace-nowrap`, so the words stay together) when N > 0 and plain text when N is 0. The
  text of the line, tags removed, is unchanged.
- Expanded block: "N left for a person" is the same anchor; the by-code breakdown stays outside it.
- `href` is `route('macro_output.index', ['date' => orders date, 'night_step' => step id])`, same
  tab, shown to everyone who sees the block. No Astra step: the "—" of today, no link.

## 3. Tests

`tests/Feature/NightRun/NightRunTestCase.php` gets the ten columns. A new
`tests/Feature/NightRun/Checker1NightFilterTest.php` (extends `NightAstraTestCase`, creates the
`tasks` table) holds every case on `/encoder/checker_1`, one method per case named
`test_S_06_1_…`; inputs that vary go in a loop inside the case's method. The S-09 cases go into the
existing `NightRunPageTest.php`, next to its fixture. No unit test: every case can be asked at the
HTTP route. Listed ids are read from `data-id` in the response. Expected ids and counts are
literals from the fixture, never from the query under test.

The fixture of the independent test: one Astra step with 2 rows done and not proceed, 1 done and
proceed, 1 failed, 1 skipped, plus orders of the same date that are in no night row.

How the harder cases are asked:

| Case | How |
|---|---|
| S-06.3 | The filtered page has the line (red before the code) and its table head, its toolbar and one row's `<tr>` are byte for byte those of the unfiltered page |
| S-06.8 | 120 night rows inserted in bulk: page 1 has 100, the page-2 link holds `night_step`, pages 1 and 2 together hold each id once |
| S-07.2, S-07.3 | The hidden input sits inside `filtersForm`; the chip and pagination links hold `night_step`; a request with `PAGE` and `night_step` lists only that page's night rows with no pagination |
| S-07.5 | The date input's `onchange` removes the hidden input before it submits (asserted on the drawn markup: there is no JavaScript test runner in the project), and a request with a new date and no `night_step` is the plain page. Question 7 asks for a hand check as well |
| S-07.8 | Each `<script>` block of the page that builds one of those requests is byte for byte the same with and without `night_step` for the same `date` and `PAGE` (the CSRF token made equal first), and none holds the text `night_step`. This pins the script text; it does not run the scripts, so it does not prove the requests at run time |
| S-10.7 | `DB::listen` collects every statement of the request: none holds the value, `macro_output` has the same rows before and after |
| S-10.8 | A table of about eight mixes of `night_step`, `date`, `PAGE`, Filter and `status_filter`, string values only (an array as `PAGE` errors today, see Proposed tasks): the ids shown are inside the night's ids and inside the ids of the same request without `night_step` |
| S-10.9 | Night rows seeded with a marker code, a marker reason, a cost and a log id: none of the markers is in the page |
| S-12.2 | `DB::listen`: no statement names `night_run_steps` or `night_astra_rows` |
| S-12.4 | `macro_output.update_field` on a listed row changes that row as today, and the row's markup on the filtered page equals the unfiltered one |

**Green before the code (characterisation), reported with their green run and the change that
would turn them red:** the ones the spec names (S-07.8, S-09.6, S-11.3, S-12.1 to S-12.4) and two
more that are true today already: S-10.4 (empty or absent is the page as today) and S-11.2 (a
guest is sent to sign-in). S-07.8 and S-12.4 compare the page with and without `night_step`, so
before the code they pass for the plain reason that the parameter is ignored; they turn red only
if the change makes a script, a row or a save differ. S-09.6 and S-11.3 are existing tests
(`NightRunPageTest::test_the_ceo_sees_the_night_with_every_action`,
`NightRunRoutesTest`, which already pins 403 on `night_run.rows` for a non-CEO); they are named in
the case table, not written twice.

## 4. Tasks

Run one after another in this worktree (T4 shares no product file with T2 and T3, but the order
keeps the commits readable). One `test:` or `feat:` commit per task, test and code together, green.

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| T1 | Stories into `qa/stories.md`; the ten fixture columns; the characterisation tests, green on the unchanged code (`test:` commit, before any product code) | backend | low | S-07.8, S-10.4, S-11.2, S-12.1, S-12.2, S-12.3, S-12.4; S-09.6 and S-11.3 named |
| T2 | `NightStepFilter` and the `index` method: resolve, narrow, date default, N and M | backend | high | S-06.1, S-06.2, S-06.4, S-06.5, S-06.8, S-07.1, S-07.4, S-07.6, S-10.8, S-10.10, S-11.1; the "status 200, no rows" half of S-10.1 to S-10.3 and S-10.5 to S-10.7; the rows half of S-06.6, S-06.7, S-07.7 |
| T3 | The Checker 1 view: hidden input, the date picker, the line, "Show all rows" | frontend | high | S-06.3, S-07.2, S-07.3, S-07.5, S-08.1 to S-08.5, S-08.7, S-10.9; the line half of S-10.1 to S-10.3, S-10.5 to S-10.7, S-06.6, S-06.7, S-07.7 |
| T4 | The link in the Night run block | frontend | medium | S-09.1 to S-09.5 (S-09.6 stays green) |
| T5 | `qa/stories.md` Test and Last run columns, the two owner checks, minors, `TODO.md` if needed, the result | docs | low | S-08.6, S-09.7 (owner checks) |

A case whose Then has a rows half and a line half (for example S-10.1) has **one** test: T2 writes
it with the rows assertions (red, then green), T3 adds the line assertions to the same method (red
again, then green). Each task reports its own red line.

Review: `skeptic-reviewer` at adversarial depth on the diff of T2 and of T3, at standard depth on
T4; T1 and T5 get lint and the tests from the main session. At most two fix loops per task. Minors
on untrusted paths are fixed in one last wave; the others go to `TODO.md` with the reason. The
full suite runs once at the end, with both commands of section 1.

**Spec review (high tier), done before this plan was sent:** `skeptic-reviewer` in spec-review mode
read the spec, this plan and the code. Verdict `ready`, no blocker and no major: it could not make
the planned filter widen, error or leak. Its five changes are in this plan (the parsed date, the
real plus sign, the redirect that keeps the other parameters, the Validate 1 question, the honest
wording of S-07.8 and S-12.4 and of what is echoed).

No browser check: this worktree has no environment file and may not get one, so the app cannot be
started here. The two owner checks cover the phone width and the tap.

## 5. Risks

- **The sub-select on MySQL.** The tests run on sqlite. On production MySQL the condition is
  `id IN (select … where step_id = ? and state = 'done' and proceed = ?)`, written with the query
  builder only and `false` as a binding (a raw `proceed = 0` would fail on pgsql, and sqlite would
  not show it); `night_astra_rows` has
  the index `(step_id, state)` and the unique `(step_id, macro_output_id)`, and the outer query is
  already limited to one date. It cannot be timed here; the result will say so under Merge danger.
- **The rule "the night's rows" now lives in two places** (`NightRunSummary` in PHP, the new class
  in SQL). S-06.4 pins that they agree on a fixture. Sharing it means touching `NightRunSummary`,
  which is outside this spec; it is a proposed task.
- **The date format `D, M j` / `M j`** is written a second time in the Checker 1 view (the first
  is a closure inside `_night_run.blade.php`). Two uses, so no shared helper yet.
- **Blade whitespace** in the collapsed line: the split must not add a space or a line break
  inside the sentence. The existing in-order assertion and the reworked one-line assertion catch it.
