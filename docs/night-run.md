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

**The switch.** In Checker 1 settings, in the CEO's Astra section, "Astra address rules" is a choice of three:
"New address rules for Astra (match like the classic checker)" (stored as `1`), "New address rules + web search
first" (`2`, see the next section) and "Off" (`0`, the default). It takes effect on the next row, for both the
night run and the browser's Astra Check / Astra Fix. Choosing "Off" restores the old rules for the next row. Rows
already done are not touched either way.

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

## Web search first (value `2` of the switch)

The rows Astra gets are the hard ones, so in this mode Astra works like the classic checker: it first finds the
real-world barangay on the web, then looks for the courier's line, and what it could fix is saved even when the
row does not reach PROCEED. Values `1` and `0` behave exactly as before; nothing below applies to them.

**What the model is told and sent (mode `2` only).**

- Extra instructions after those of the new rules (`AstraEncoder::WEB_FIRST_PROMPT`): the first step is a web
  search; establish the real-world barangay first (a barangay the customer wrote is used, and confirmed with one
  search when its name is ambiguous; for a street, sitio, purok, subdivision, district, area, landmark or business,
  or a place that files barangays by number when no number was given, search which barangay it belongs to, with the
  city and the province or "Philippines" in every query, preferring an official list, a map listing or two sources
  that agree); then find the courier's line with the list search (the City of Manila is filed by district); never
  invent a barangay; a barangay found on the web is not by itself a reason to ask for a person.
- The first request carries `"tool_choice": {"type": "web_search"}` (the tools list is unchanged: web search, the
  J&T list search, the chat history). Every later request of the row carries `"tool_choice": "auto"`, as today.
- `max_tool_calls` is 5 instead of 4 (`services.openai.astra_encoder_max_web_first`, environment variable
  `ASTRA_ENCODER_MAX_WEB_FIRST`, optional; 0 = no cap).
- If the API answers the first request with HTTP 400, it is sent once more with `"tool_choice": "auto"` (nothing
  else changes) and the row is counted as "web search not forced". A first answer that contains no web search is
  counted the same way; the row still runs. The row's log then has the line `WEB FIRST: hindi napilit ang web
  search`, and `replay.web_forced` is false.
- Two new answer fields, read only in this mode and only as these exact words (anything else is `none`):
  `brgy_source`: `customer` | `web` | `none`; `web_basis`: `official` | `several` | `single` | `none`.

**What the program does with the answer (mode `2` only).**

- The line comes from the model or, when the model gave none, from the program mapping Astra's form, as in mode
  `1`. When the model gave a line and the program can also map the form by itself, and the two differ in city or
  barangay, the row is held: "two different lines".
- When the customer's own text confirms the barangay, everything is as in mode `1`. When it does not and the model
  said `brgy_source` = `web`, the barangay is "web-found": the model's confidence neither confirms it nor drops
  it. The province, city and barangay are written to the row, STATUS stays blank, and the row's one-line summary
  starts with `WEB: barangay from web search (<web_basis>), confirm`. When that is the only thing in the way, the
  code is `Barangay`; otherwise the code is the other obstacle's, as in mode `1`.
- **The second setting**, "Barangay found on the web may PROCEED" (`astra_web_barangay_proceed`, CEO, same form):
  `0` (default) never; `official`; `several` (official too); `single` (any). It names the weakest basis at which a
  web-found barangay may PROCEED when everything else on the row passes. It is read only in mode `2`.

**Before switching to `2`.** Run the dry run in web-first mode on a recent night (next section) and read the "Web
search first" block of its report.

Rulings (mode `2`):

- Ruling: on the settings page the three choices stand in the order New rules, New rules + web search first, Off —
  the page's existing checks read the first control as the mode-`1` box — if the owner wants Off first, the
  markup and those checks change together.
- Ruling: a value other than `0`, `1`, `2` posted for the switch changes nothing; no value at all still means Off —
  a broken post should not switch production rules off — before this, any such value turned the rules off.
- Ruling: the second setting is saved only when it is sent as one of its four words — an older open page does not
  send it — a wrong word is ignored without a message.
- Ruling: the forced first round is sent as `{"type":"web_search"}`, the same name the tool has in the tools list,
  and this was not tried against the live API from the development machine — real calls are not allowed there —
  if the API wants another shape, every row falls back to `auto` and shows up as "web search not forced" in the
  dry run, which is the number to look at first.
- Ruling: any HTTP 400 on the forced request triggers the one retry with `auto`, not only a 400 that names
  `tool_choice` — the error text is not a stable thing to match — a 400 for another cause costs one extra failed
  call.
- Ruling: the fallback changes only `tool_choice`; the web-first instructions and the cap of 5 stay — the
  instructions still tell the model to search first, and the two new fields must still be answered — if the owner
  wants the exact mode-`1` request there, the row would then have no web-found barangays at all.
- Ruling: "forced" means the API accepted the parameter and the first answer contains a web search — both ways of
  not searching matter the same to the owner — rows whose first answer was only cut short count as not forced.
- Ruling: the model is told to write a web-found barangay into the form's barangay too (city and province stay
  as the customer wrote them) — the program can then map the form by itself, which is what makes "two different
  lines" possible — the Astra block in the customer details shows a barangay the customer did not write; the
  summary line says so.
- Ruling: a web-found barangay is written whatever the model's confidence, also medium and low — a proposal a
  person confirms is the point of this mode — a weak proposal can sit on a row; its summary line starts with WEB.
- Ruling: a web-found barangay beside another obstacle (the model asked for a person, a blank field, the final
  check, a cancel or inquiry) is still written, with the other obstacle's code and the WEB summary line — the
  classic checker's habit of saving what it fixed — in mode `1` some of these rows would have had no barangay
  written.
- Ruling: with the second setting, a web-found barangay of `web_basis` `none` or with low confidence never
  proceeds — no source, or the model's own doubt, is not enough for a parcel nobody looks at — a few rows wait
  that a looser reading would have sent.
- Ruling: a barangay that is not in the customer's text and that the model marked `customer` or `none` follows
  the mode-`1` rule (accepted at high confidence, dropped otherwise) — the new treatment is meant only for
  web-found barangays — a model that marks a web barangay as `customer` with high confidence proceeds, as in
  mode `1` today.
- Ruling: "two different lines" compares the city and barangay labels, and is checked only when the program can
  map the form by itself (one city, one barangay, not low confidence); the model's line is written like any row
  the model flagged (code `TO FIX`, STATUS blank) — one of the two is probably right and staff see both names in
  the reason — if the program's line was the right one, staff must change the barangay by hand.
- Ruling: a web-found barangay that proceeds under the second setting gets ` · barangay from web search
  (<web_basis>)` at the end of its summary line — so it can be found afterwards — none.
- Ruling: the log's `replay` block gains keys in mode `2` only (`web_first`, `web_forced`, `brgy_source`,
  `web_basis`, `confidence`, `web_found`, `web_only_obstacle`, `web_proceed`, `two_lines`), fixed words and
  booleans; `rules` stays `new` — mode `1` logs are unchanged — `astra:replay-address-rules` re-decides a mode-`2`
  night with the rules of mode `1`.
- Ruling: the example environment file was not touched — environment files are not opened in this work — the
  owner may add `ASTRA_ENCODER_MAX_WEB_FIRST=5`; without it the cap is 5.

## The dry run of the whole new Astra

The replay above re-decides stored answers and calls no model. `astra:dry-run` is its sibling that does call the
model: for the rows of one past night it runs the new instructions, the tools, the new rules and the final check
with the rules ON whatever the switch says, on each row as it stood before that night, and reports how many
would proceed. It writes nothing to the orders, the night tables or the logs; it costs model calls.

    php artisan astra:dry-run --step=<id> [--rows=held|proceeded|all] [--limit=200] [--ids=1,2,3]
    php artisan astra:dry-run --night=YYYY-MM-DD
    php artisan astra:dry-run --step=<id> --mode=web-first
    php artisan astra:dry-run --step=<id> --mode=web-first --compare=storage/app/astra-dry-run/<earlier report>.json

`--mode=rules` (the default) forces mode `1`; `--mode=web-first` forces mode `2`, whatever the switch says.

It refuses to start between 02:30 and 04:30 Manila time or while a night Astra step is running, and stops after
3 API errors in a row. The report is printed and saved under `storage/app/astra-dry-run/`. Details, caveats and
how to run it on the server: `docs/astra-dry-run.md`.
