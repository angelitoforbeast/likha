# Night run

Every night, by itself: the macro import and the Likha import at two times (default 01:00 and 02:00), then
Astra on yesterday's orders that still have no STATUS (default 03:00), all Asia/Manila. Spec:
`docs/specs/007-night-run.md`.

## Turning it on, and what the page shows

Everything ships switched off. The CEO opens `/encoder/checker_1/settings`, block "Night run", ticks the
switches wanted (night macro import, night Likha import, night Astra), checks the times, the stop time and the
safety maximum, and saves. From then on the scheduler starts each step at its time. The result is at the top of
`/encoder/checker_1/ai-checker/logs`, "Night run": one line per night for the last 14 nights, with the imports
(Done, Done with failed sheets, Failed, Skipped, Running) and Astra (Waiting, Waiting for the worker, Running,
Finished, Stopped, Did not run, with rows found, PROCEED, left for a person, failed, skipped, not run). A red
banner shows when a switched-on step failed, was stopped, did not run or left no record last night. › opens a
night; the CEO also sees the estimated cost, "Show rows" (each row links to its log entry), "Retry failed" and
"Run now". No J&T order is created by any of this.

## Deploy

1. **Migrations** (`php artisan migrate --force`): `2026_10_04_100000_create_night_run_steps_table`,
   `2026_10_04_100100_create_night_astra_rows_table`. Both are new tables; nothing existing is altered.
2. **Config cache:** run `php artisan config:cache` (or `config:clear`) so the new gpt-6-luna price in
   `config/services.php` is read.
3. **Supervisor program for queue `astra`** (2 processes). Adjust the path, user and log path to the server:

   ```ini
   [program:likha-astra]
   process_name=%(program_name)s_%(process_num)02d
   command=php /var/www/likha/artisan queue:work database --queue=astra --sleep=3 --tries=3 --timeout=540 --max-time=3600
   directory=/var/www/likha
   numprocs=2
   autostart=true
   autorestart=true
   stopasgroup=true
   killasgroup=true
   stopwaitsecs=600
   user=www-data
   redirect_stderr=true
   stdout_logfile=/var/www/likha/storage/logs/astra-worker.log
   ```

   Then `supervisorctl reread`, `supervisorctl update`. `stopwaitsecs` is above the job timeout (540 s) so a
   restart lets the current row finish. The existing `default` worker is not changed and must not take queue
   `astra`.
4. Nothing else. The cron line `* * * * * php artisan schedule:run` is already there.

Things to check on the server, because the code can't read them:

- **Cache store.** The one-import-at-a-time guard is a cache lock. It needs a store shared by web requests,
  the API and the scheduler: `database`, `file` or `redis`. It does not work with `array`. Two servers with
  separate stores don't guard each other (likha and incepxion are separate sites, so this is fine).
- **`pcntl`** in the PHP used by the workers, for the 540 s job timeout. Without it a hung row is closed by the
  10-minute sweep instead.
- **`DB_QUEUE_RETRY_AFTER`** stays above 540 (the default in `config/queue.php` is 1900).
- **`APP_URL`** names the site: the black and white lists used at night are chosen by whether it contains
  "incepxion".

## The first night

1. Turn on only what is wanted; leave Astra off for a night if you want to watch the imports first.
2. Next morning open the logs page. Expect one line for the night: imports "Done" with counts, Astra
   "Finished" with rows found = PROCEED + for a person + failed + skipped + not run.
3. "Waiting for the worker" means the `astra` supervisor program isn't consuming the queue.
4. "Did not run: no finished macro import since midnight" means no macro import that started after 00:00 ended
   with at least one sheet done; "an import was still running (macro)" or "(Likha)" names the one that blocked.
5. "Stopped: …" names the reason (key rejected, credit or spend limit, 10 rows failed in a row, no API key).
   After fixing the cause, "Retry failed" runs that night's failed and not-run rows that are still blank.
6. `storage/logs/laravel.log`: `NIGHT_ASTRA_ROW` (a row job hit an error; the class only) and
   `ASTRA_ENCODER_HTTP` (HTTP status and OpenAI's error type and code, never the body).

## Astra's new address rules (the switch and the replay)

**The switch.** In Checker 1 settings, in the CEO's Astra section, tick "New address rules for Astra (match like
the classic checker)". It is off by default. It takes effect on the next row, for both the night run and the
browser's Astra Check / Astra Fix. Unticking it restores the old rules for the next row. Rows already done are
not touched either way.

**The replay.** Before (or after) switching on, you can see what the new rules would have done to a past night:

    php artisan astra:replay-address-rules --night=YYYY-MM-DD
    php artisan astra:replay-address-rules --step=<id>

`--night` is the date of the morning the run happened (Manila time); `--step` is the id of that night's Astra
step. Give exactly one of them. The command only reads: it changes no order, calls no model, costs nothing, and
prints counts and order ids only, never customer text. It gives the same report whether the switch is on or off.

**How to read the output.**

- The first lines name the night and the step, the list fingerprint (a short code for the J&T address list in
  use today), and how many rows the night had by state (finished, failed, skipped, not run, waiting, other).
- "Finished rows without a log / without an order / whose log could not be read": finished rows the replay
  could not use, because the stored answer or the order is gone or unreadable. They are listed by id and
  counted as "could not be replayed".
- "Finished rows with an older log": logs written before the replay data was stored. The model's own request
  for a person is not recorded in them, so it is inferred.
- "Astra proceeded that night" and "Held for a person that night": what happened then. For the rows that
  proceeded, the report lists those the new rules would not proceed. For the held rows it shows how many would
  pass the address rules, and how many would pass everything (address rules plus the final check of item, COD,
  shop details and blacklists), with the order ids.
- "First thing that would still hold each held row": every held row is counted once, under the first reason
  that stops it (the model asked for a person, unclear intent, no line from the list, barangay not in the
  customer's text, a blank required field, the final check, or nothing, meaning it would proceed).
- "Where the line of the held rows came from": the model, the program mapping Astra's form to the J&T list,
  or no line at all.
- The older-log lines (hold taken as the program's, and the rows where the model asked for a person without a
  stated reason) say where the counts for older logs can be too high or too low. The "strict" line is always
  "none of these rows would proceed"; the "lenient" line is the count if such a request were ignored when the
  program found the line and the customer's text confirms it.
- "Held rows that staff have since set to PROCEED / CANNOT PROCEED": how the new rules compare with what staff
  decided afterwards, and whether the province, city and barangay are the same as staff's.
- "Rows that could not be rebuilt exactly as they were that night": the chat, the earlier conversation or the
  customer details have a different length now, or the list changed. Read those rows with care.
- "Of the rows that would pass everything, the same phone is on another order of the same date today": rows
  that the old rules held for a duplicate phone and the new rules let through; the Validate step still catches
  them.

**What a replay cannot know.** It reads the earlier conversation, the item, COD, shop details and blacklists as
they are today, not as they were that night. It cannot see edits made to the chat or the customer details since,
earlier conversation the model fetched with its own tool, or (for older logs) the version of the address list
that night. Logs older than 90 days are deleted, so only recent nights can be replayed.

## The dry run of the whole new Astra

The replay above re-decides stored answers and calls no model. `astra:dry-run` is its sibling that does call the
model: for the rows of one past night it runs the new instructions, the tools, the new rules and the final check
with the rules ON whatever the switch says, on each row as it stood before that night, and reports how many
would proceed. It writes nothing to the orders, the night tables or the logs; it costs model calls.

    php artisan astra:dry-run --step=<id> [--rows=held|proceeded|all] [--limit=200] [--ids=1,2,3]
    php artisan astra:dry-run --night=YYYY-MM-DD

It refuses to start between 02:30 and 04:30 Manila time or while a night Astra step is running, and stops after
3 API errors in a row. The report is printed and saved under `storage/app/astra-dry-run/`. Details, caveats and
how to run it on the server: `docs/astra-dry-run.md`.
