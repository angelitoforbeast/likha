# Plan 201: the Speed page opens fast

Spec: `docs/specs/201-speed-prewarm.md`. Risk tier: medium for every task (the spec's tier). The new JSON endpoint is a
second route on the same controller behind the same permission, throttle and filter normalisation as `/chatbot/speed`
(`ResponseSpeed::filters()`), so it is no new kind of input.

## Design (decided here; the spec leaves it open)

### Two caches instead of one
`ResponseSpeed` keeps one cache entry per (day, page, bot) for everything except Sequences
(`response-speed:v3:<md5 of filters>`; the version moves from v2 because the entry no longer holds `sequences`) and a
second entry per (day, page, bot) for Sequences only (`response-speed-seq:v1:<md5 of filters>`). TTLs are unchanged:
120 s for today, 1800 s for a past day, 120 s for a Sequences answer that is `too_large`.

- `output($q)`: the page's data, as before minus `sequences` and minus `charts.seq`. Reads the chosen day and the
  previous day from the first cache only, so it never touches `chatbot_events`.
- `sequencesOutput($q, $chart)`: the endpoint's data. Without `$chart`: `{filters, sequences}` for the chosen day only
  (one day of sequence work at most). With `$chart`: `{date, prev_date, seq}` built from the chosen day's entry (already
  cached by the first call) and the previous day's (one day of sequence work at most). The numbers are the same
  functions as before (`sequences()`, `charts()`); only where they are called from changes.

### The endpoint and the page
- `GET /chatbot/speed/sequences?date&page_id&bot[&part=chart]` (`chatbot.speed.sequences`), same NavAccess check as the
  page (403), `throttle:120,1,chatbot-speed-sequences` (two calls per page load).
  - default part: `{filters, sequences, html}`; `html` is the section body rendered from a new partial
    `chatbot/partials/speed-sequences.blade.php` (the markup that sat in `speed.blade.php`, moved unchanged).
  - `part=chart`: `{date, prev_date, seq}` (the old `charts.seq`).
- The page renders the Sequences heading and a loading line (`role=status`) in place of the body. Its script fetches the
  section, inserts the HTML, wires the remembered open/closed tables, then fetches the chart and draws it with the same
  chart code as the other three (the drawing loop becomes a function). Any failed call shows an error line
  (`role=alert`) with a Retry button that runs both calls again. Nothing else on the page changes.

### Bound for a past day (S-201.3)
Before: one request did two days of everything, Sequences included (34.5 s cold on a past day in production). After:
the page request does two days without Sequences; each Sequences call does at most one day of Sequences work (the
chosen day, then the previous day for the chart reference), each under its own request and its own php-fpm limit. The
memory budget (3/4 of memory_limit) and the `too_large` fallback stay as they are, now in the endpoint.

### Pre-warm (S-201.1, S-201.5)
- `ResponseSpeed::warmDefault()`: for the default filters (no page, no bot) builds and writes both entries, bypassing
  the read, for yesterday when it is due (no warm stamp, last warmed while it was still today, or 20 minutes old) and
  then for today (every run). Writes use the same keys the page reads, with TTL = the page's TTL + 180 s, so an entry
  never expires between two runs that are 2 minutes apart; a `too_large` Sequences answer keeps the page's 120 s.
  Each entry is built in its own try/catch: a failure is reported, nothing is written for it, and the page builds it
  on demand as before. A small stamp key (`response-speed:warmed:<date>`) records when yesterday was last warmed.
- Command `chatbot:speed-warm` (`app/Console/Commands/ChatbotSpeedWarm.php`): takes a cache lock
  (`response-speed:warm`, 10 min); when held, logs one "skipped" line and exits. Otherwise warms and logs one line
  with the days warmed, any failed part and the seconds taken.
- `routes/console.php`: `Schedule::command('chatbot:speed-warm')->everyTwoMinutes()->withoutOverlapping(10)
  ->onOneServer()->runInBackground()`, following the other chatbot entries (`onOneServer` as `rts:daily-estimate`).

## Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | Old-vs-new snapshot: on the base code, record `output()` for a seeded day in six filter sets (all, page, bot, past day, past day + page, two days back) as a fixture; test compares the new page data + endpoint data to it | backend | medium | S-201.4 |
| 2 | Split the cache: `output()` without Sequences, `sequencesOutput()` with its own cache; existing tests that read Sequences from the page move to the endpoint | backend | medium | S-201.2, S-201.3, S-201.4 |
| 3 | Endpoint route + controller action + partial; page placeholder, loading/error/retry script, chart drawing as a function | backend + frontend (same files; one task because the partial is shared) | medium | S-201.2, S-201.3 |
| 4 | `warmDefault()` + `chatbot:speed-warm` + schedule entry | backend | medium | S-201.1, S-201.5 |
| 5 | Measurements on the spec 198 volume (sqlite, local), before vs after; result, stories, deploy notes | docs | low | S-201.6 |

Tasks share `ResponseSpeed.php` and the tests, so they run in order in this one worktree (none is parallel-safe).
Review: one `skeptic-reviewer`-style pass (standard depth) on the whole diff after task 4; majors get a fix loop.

## Cases (added to `qa/stories.md` as S-201)
- S-201.1 warm writes today's and yesterday's default entries (both caches) under the page's keys; afterwards the page
  and the endpoint run no query on the big tables; entries outlive the 2-minute interval; yesterday is rebuilt only
  when due; the schedule entry is every 2 minutes, without overlapping, on one server, in the background.
- S-201.2 the page has no Sequences numbers and reads no `chatbot_events`; it has the loading line, the endpoint URL with
  the same filters, the error line with Retry; the endpoint returns the section HTML and the chart; same 403; bad
  query values are normalised as on the page.
- S-201.3 a past day: the page request reads no `chatbot_events`; the endpoint's section call reads sequence events of
  the chosen day only, the chart call of the previous day only; over the memory budget or failing, the endpoint
  answers `too_large` (200) and retries after 2 minutes.
- S-201.4 same numbers: page data + endpoint data equal the base code's `output()` for six filter sets; the spec 198
  volume fingerprint is unchanged.
- S-201.5 the lock held: one "skipped" log line, nothing written; an exception in one part: reported, one log line
  naming it, that entry not written, the page builds it on demand; one log line per run with its seconds.
- S-201.6 measurements in the result.

## Amendment 1 (added during the build)
- A1, one message per (page_id, psid, received_at): `build()` and `pageSeconds()` skip a chat already seen with the same
  key (after the attachment and standby rules), tracked per hourly window (a duplicate has the same time, so the same
  window).
- A3, Not sent: the hourly `chatbot_replies` read also takes `status = failed` rows (same query, `status` IN list grows by
  one, `status` and `error` selected), kept per customer with their read position. Per message: the failed sends from
  the message to its first delivered reply (by read order, so a same-second retry counts) make it "Sent late"; with no
  reply in 86,400 s, the failed sends in that window make it "Never sent". Rows get
  `not_sent {never, late, attempts, late_avg}`; the reply chart gets `not_sent` (a red bar on its own right axis); the
  hours table gets three columns. The list (newest first; newest 200 per hour kept, with per-hour counts) is written by
  the same build under its own key and served by `GET /chatbot/speed/not-sent?date&page_id&bot&hour`, loaded when the
  "Not sent list" box is opened. Only the Meta code and subcode digits are read from the stored error text.
- Review follow-ups: the warm run never writes a `too_large` Sequences answer; a web Sequences call has a 20 s time
  budget (answers `too_large` before php-fpm's 30 s); the command caps an unlimited CLI memory_limit at 512M so the
  3/4 budget holds.
