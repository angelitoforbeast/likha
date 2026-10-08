# Astra dry run

`astra:dry-run` runs the whole new Astra on the rows of one past night and reports how many of the rows Astra
did not proceed that night WOULD proceed now. No order is changed.

## What it does

For each selected row of the night's Astra step it does what the night job would do with the new address rules
ON, whatever the switch says: the real model call with the new instructions, the same tools (earlier
conversation, J&T list search, web search), the new rule decision and the final check. The row is put back, in
memory only, to the six fields it had before Astra touched it that night (from that night's log), with a blank
STATUS, and is then judged. The outcome is compared with the row as it is now, after staff.

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
- Ruling: the encoder was split into a deciding part (`decideOnly`) that `processRow` calls, plus `dryRunRow`
  for this command — the smallest change that lets the same code decide without writing — the night job's
  statements and their order are unchanged and its existing tests pass untouched.
