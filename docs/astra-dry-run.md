# Astra dry run

`astra:dry-run` runs the whole new Astra on the rows of one past night and reports how many of the rows Astra
did not proceed that night WOULD proceed now. No order is changed.

## What it does

For each selected row of the night's Astra step it does what the night job would do with the new address rules
ON, whatever the switch says: the real model call with the new instructions, the same tools (earlier
conversation, J&T list search, web search), the new rule decision and the final check. The row is put back, in
memory only, to the six fields it had before Astra touched it that night (from that night's log), with a blank
STATUS, and is then judged. The outcome is compared with the row as it is now, after staff.

`--mode=rules` (the default) is the run described above: it forces mode `1` of the switch. `--mode=web-first`
forces mode `2` instead (the new address rules + web search first, see `docs/night-run.md`): the first model
round must search the web, the cap is 5 searches, and a barangay found only on the web is not a PROCEED. In that
mode the setting "Barangay found on the web may PROCEED" is not read: no web-found barangay proceeds, and the
report counts what each value of the setting would let through.

## What it does not do

- It writes nothing to the database: no order, no night table, no Astra log, no setting, no queue job. It only
  reads. The J&T list is cached in memory for the run, so not even the cache table is written.
- It prints and stores ids, counts, flags and fixed reason words only: never a name, phone, address, chat text,
  order number or the model's own words. It never prints the API key; it reads the key the way the night job does.
- It does not start between 02:30 and 04:30 Manila time, while a night Astra step is running, or without an API
  key. It prints why.
- It **does** call the model, so it costs money. Rows run one after another. It stops after 3 API errors in a row.

## Running it on the server

Find the step id on the logs page (or use `--night`, the date of the morning the run happened, Manila time). From
the project folder, as the web user:

    sudo -u www-data php artisan astra:dry-run --step=<id>
    sudo -u www-data php artisan astra:dry-run --night=YYYY-MM-DD

Options:

    --rows=held|proceeded|all   held (default): rows Astra finished without PROCEED that night
    --limit=N                   most rows to run (default 200), applied before the first call
    --ids=1,2,3                 only these macro_output ids
    --mode=rules|web-first      rules (default): mode 1; web-first: mode 2 (web search first)
    --compare=<path>            an earlier report JSON of this command; adds a table of outcome then against now

Web search first on a night, and the same compared with an earlier run of that night:

    sudo -u www-data php artisan astra:dry-run --step=<id> --mode=web-first
    sudo -u www-data php artisan astra:dry-run --step=<id> --mode=web-first --compare=storage/app/astra-dry-run/step-<id>-<YYYYMMDD-HHMMSS>.json

A small first run to see it work: `sudo -u www-data php artisan astra:dry-run --step=<id> --limit=5`. For a long
run use `screen` or `tmux`, or `nohup … > dry-run.txt &`, so a dropped SSH session does not stop it.

One line per row is printed as it finishes (`row 123: would proceed`, `row 124: held - <reason>`,
`row 125: API error (http_429)`), then the report.

## How long, how many calls

The command prints, before the first call, how long the selected rows took that night (their stored
durations); expect about that, since here the rows run one at a time instead of two. No measured figure is
stored in this repo, so as a rule of thumb only: a row is usually 2 to 4 model calls (the first call, one or
two after a list search, the final answer; at most 8) and well under a minute to two minutes at high effort,
so about 150 rows is roughly 300 to 600 model calls and 1.5 to 4 hours. Each call is cut at 120 seconds, as
at night. The cost per row is that of a night row; the report gives the calls and tokens in and out.

## The report file

The same counts are written as JSON to `storage/app/astra-dry-run/step-<id>-<YYYYMMDD-HHMMSS>.json` (Manila
time). The file is rewritten after every row, so a run stopped from outside still leaves the rows it finished;
the `summary` block is added when the run ends by itself. `storage/app` is ignored by git.

## Reading the report

- **Rows tried / WOULD PROCEED / would stay held / API error**, then the held rows by the first reason that stops
  each (the replay's wording: the model asked for a person, unclear intent, no line from the list, barangay not
  in the customer's text, a blank required field, the final check).
- **Rows Astra held that night:** how many would proceed now, how many stay held, and of those how many staff
  have since set to PROCEED (rows the new process still misses).
- **Rows Astra proceeded that night** (`--rows=proceeded` or `all`): how many the new process would hold
  instead, by reason, with ids.
- **The rows that would proceed, against the row as it is now:** staff's status now (PROCEED, CANNOT PROCEED
  with ids, other), and whether province, city and barangay equal the row's now (all equal; which part differs,
  with ids; cannot compare when the row now has one of them blank).
- **Model, effort, model calls, tokens, web searches, API errors by class, elapsed time.**

In web-first mode the held rows have two more reasons, "two different lines" (the model's line and the program's
own mapping of the form differ in city or barangay) and "barangay from the web, waiting for a person", and the
report adds a block **Web search first**, with ids:

- rows that would PROCEED outright;
- rows that would be written with a barangay from the web and wait for a person, split by `web_basis` (official,
  several, single, none) and by the model's confidence (high, medium, low); for each split: how many have
  province, city and barangay equal to the row now, how many differ and in which part, how many cannot be
  compared, and how many staff set to CANNOT PROCEED;
- "If barangays from the web were allowed to proceed" at official / several / single: the total that would
  proceed (the outright rows plus the waiting rows that value lets through) and how many of those have a line
  equal to the row now;
- rows where web search could not be forced (the API refused the parameter, or the first answer had no search);
- web searches per row, average and maximum, over the rows that got a verdict.

With `--compare` the report ends with **Against the earlier report**: for the rows in both, held then and would
proceed now, held then and barangay from the web now, would proceed then and held now, any other change that
occurred, the count with the same outcome, and the rows without a verdict in one of the two. The JSON has the
same under `summary.web_first` and `summary.compare`, and `"mode": "web-first"` at the top.

## Caveats

- The chat and the earlier conversation are each stored as one text with no time per message. Messages written
  after the night step started cannot be left out: the model reads both as they are today. The report counts the
  rows whose chat, earlier conversation or customer details have a different length now than that night.
- Item, COD, page, shop details, customer details, blacklists and the J&T list are read as they are today.
  Under the new rules a same-phone order of the same date is not a hold, so it is not checked (as in the replay).
- Only the six fields are stored as "before" values. A row with no readable log of that night is judged as it
  is today and counted under its own caveat line.
- Web searches and the model's own fetch of the earlier conversation happen today.
- The model does not answer the same way twice: a second run can move a few rows either way.
- Logs older than 90 days are deleted; older nights have no "before" values.

## Rulings

- Ruling: the "before" row is the six fields from the night log's `before`, with STATUS blank — that is all the
  night stored, and the night only picks rows with a blank STATUS — if wrong, the model sees an encoder's value
  that differs from that night's in the "current row" line and a few rows could be judged differently.
- Ruling: customer details (CXD) are today's with Astra's own blocks removed, not a stored copy — the night
  only appended its block and the encoder already strips those blocks before use — if staff rewrote the
  customer's part, the model and the barangay check read the new text; those rows are in the "different length"
  count.
- Ruling: no time filter on the chat — neither text has a time per message, so there is nothing to cut on — if
  the customer or staff added text the next day, a row can look easier than it was that night; the length count
  shows how many rows this can touch.
- Ruling: rows of the night are those in state `done`; `held` means done without PROCEED — failed, skipped and
  not-run rows were never judged by Astra, so there is nothing to compare — if the owner wants those too, they
  need another option; today they are left out silently except through `--ids` (which also only matches done
  rows).
- Ruling: the first holding reason is worked out exactly as the replay does, in the same words — so the two
  reports can be read side by side — if the grouping is wrong it is wrong in both the same way.
- Ruling: the province, city and barangay comparison is made for every row that would proceed, whatever staff's
  status, ignoring case, accents, hyphens and spaces (as the replay) — staff's line is the best reference there
  is — if staff left Astra's night values untouched, "equal" partly compares Astra with itself; the extra line
  "of those, staff status now PROCEED" is the stricter number.
- Ruling: "cannot compare" means the row now has a blank province, city or barangay — a blank is not a
  disagreement — if wrong, a few rows move between "differs" and "cannot compare".
- Ruling: a row with no usable answer from the model (no JSON, 8 tool rounds used up) is counted as an API error
  of class `no_usable_answer` and counts toward the 3-in-a-row stop — it cost a call and gave no verdict — if
  wrong, a run could stop early on three odd answers in a row; rerun with `--ids`.
- Ruling: only a step in state `running` blocks the start (plus the 02:30–04:30 window); a `waiting` step does
  not — a waiting step makes no model calls — if a night starts outside the window while the dry run runs, both
  use the same key at once; the night job's own retry handles rate limits.
- Ruling: the cache is switched to memory for the process — on a server whose cache store is the database, the
  list search would otherwise insert into the cache table — the cost is reading the list file once per run.
- Ruling: HTTP errors are still written to `laravel.log` by the encoder (status and error code only) — that is
  the night job's own code path and a file, not a table — if a fully silent run is wanted, that needs a change
  in the encoder.
- Ruling: each model call is cut at 120 seconds, like the night job, not the browser's 300 — the dry run should
  behave like the night — if wrong, a few slow rows show as `connection_or_timeout` instead of an answer.
- Ruling: the JSON file is rewritten after every row — a stopped run then still leaves a file — the cost is one
  small file write per row.
- Ruling: in web-first mode the setting for web-found barangays is taken as `0` — one run then answers all three
  values through the "if allowed" lines — the run does not show the night exactly as it would go with the setting
  the CEO saved; add the matching "if allowed" line.
- Ruling: a row whose only obstacle is a web-found barangay is counted as held, under its own reason, not as a
  third outcome — "would stay held" then still means "no PROCEED" — the reader adds the two lines to get the rows
  that are written with a proposal.
- Ruling: the first holding reason in web-first mode is looked for in this order: the model asked for a person,
  unclear intent, no line, two different lines, barangay not in the text, a blank field, the final check, and
  last the barangay from the web — the same order as the rule function, so "barangay from the web" means nothing
  else was in the way — a row with two problems shows only the first.
- Ruling: the two new reasons, the Web search first block and the `mode` key appear only in web-first mode — the
  default mode's report and file stay as they were — the line that lists the options when one is wrong is longer
  in both modes.
- Ruling: "equal to the row now" in the Web search first block compares province, city and barangay whatever
  staff's status, as the older lines do — one way of comparing in the whole report — a row staff never touched
  compares Astra's night values with Astra's proposal.
- Ruling: web searches per row are averaged over rows that got a verdict (would proceed or held) — an API error
  has no searches to count — the total in the "Model calls" line still covers every call.
- Ruling: `--compare` reduces each row to would proceed, barangay from the web, or held; it takes only ids and
  those fixed words from the earlier file, which must be a report of this command of at most 20 MB, and it is read
  before the first model call — a wrong path should not be found after the cost — a report of another night
  simply has no rows in common.
- Ruling: `--compare` works across modes (an earlier default-mode report against a web-first run) — that is the
  comparison asked for — the two runs also differ by the model's own variation, so a few changes are noise.
- Ruling: the encoder was split into a deciding part (`decideOnly`) that `processRow` calls, plus `dryRunRow`
  for this command — the smallest change that lets the same code decide without writing — the night job's
  statements and their order are unchanged and its existing tests pass untouched.
