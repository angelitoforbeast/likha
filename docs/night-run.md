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
