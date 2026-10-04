# Handoff 008: The classic AI checker stops writing response bodies and exception messages into the log

**From:** Mira - **To:** Claude Code - **Approver:** Busing (Angelito Forbes, CEO)
**Project:** Likha AI Tech (likhaaitech.com) - **Weight:** bounded - **Shape:** change - **Risk tier:** medium (log lines only, in code that runs on production orders; no behaviour may change)
**Stack:** Laravel 12, PHP 8.2, MySQL in production, SQLite in memory in tests. **Base: `develop` at `82fd74e`.**

## 1. Context

Handoff 007 (night run) made Astra's own log lines safe: `AstraEncoder` logs an HTTP failure as status plus OpenAI's error `type` and `code`, and an exception as its class only (`ASTRA_ENCODER_HTTP`, `ASTRA_ENCODER_EX`). The classic checker was out of scope then and still writes more than it should into `storage/logs/laravel.log` on the server:

- `app/Services/MacroChecker.php` line 811, `MACRO_CHECKER_OPENAI_HTTP`: logs the first 400 characters of OpenAI's response body. On a 401 that body quotes part of the API key.
- `app/Services/MacroChecker.php` line 1012, `MACRO_CHECKER_SEARCH_HTTP`: the same, for the search step.
- `app/Services/MacroChecker.php` near line 818, `MACRO_CHECKER_OPENAI_EX` (and any other `MACRO_CHECKER_*_EX`): logs `$e->getMessage()`.
- `app/Services/AiCheckerRowRunner.php` line 158, `AI_CHECKER_LOG_FAIL`: logs `$e->getMessage()` of a failed insert into `ai_checker_logs`; a query exception's message carries the SQL bindings, which hold customer text.

Line numbers are as of `82fd74e`; check them against the code.

## 2. Goal

Those log lines keep enough to debug (which step, which attempt, the HTTP status, OpenAI's error type and code, the exception class, a database error code) and never contain a response body, an exception message, a key fragment or customer text. Nothing else about the checker changes.

## 3. Decisions already made

Do not reopen these unless something is actually broken.

| Topic | Decision |
|---|---|
| Scope and approval | Busing, 2026-10-04: "oo 1 2 3 5" (item 1 = this cleanup: start it). Deploy needs his yes after Mira's check. |
| Shape of an HTTP failure line | Mira's decision: same as `ASTRA_ENCODER_HTTP` in `AstraEncoder.php` (about line 581): `attempt`, `status`, `type`, `code`, plus `step` where the line has one today. `type` and `code` come from the JSON body's `error.type` and `error.code` when the body is JSON; empty strings otherwise. Never the body, never `error.message`. |
| Shape of an exception line | Mira's decision: same as `ASTRA_ENCODER_EX`: the keys the line has today except `error`, plus `exception` = the exception's class name. For `AI_CHECKER_LOG_FAIL` add `sqlstate` when the exception is a query exception (its SQLSTATE or driver code only), never the message, never bindings. |
| Old log files | Not touched. Nothing is deleted on the server or in the repo. |

## 4. Requirements

1. Change the four log calls named in section 1 to the shapes in section 3.
2. Sweep both files (`MacroChecker.php`, `AiCheckerRowRunner.php`) for every other `Log::` call: list each in RESULT.md with what it logs. Any that logs a response body, a request payload, an exception message or row data gets the same treatment; any that is already safe is listed as "left as is" with the reason.
3. No behaviour change: return values, retries, sleeps, statuses written to rows, usage recording, prompts and models stay byte-for-byte as they are. The diff of the two service files touches log calls (and at most one small private helper to read `error.type` / `error.code` from a response) and nothing else.
4. Do not touch `AstraEncoder.php`, the night-run code, prompts, or `MacroImportController.php` (a separate task deletes that file).

**Testing decisions.** Feature or unit tests in the existing suite style (see `tests/Feature/NightRun` for how the suite fakes OpenAI and reads logs). Seams: the public methods that reach each log line, with `Http::fake()` for OpenAI and the search endpoint; no real network. Cases, one test per changed log line: (a) OpenAI answers 401 with a JSON body whose `error.message` contains the made-up fragment `sk-TESTFRAGMENT123` and `error.type` `invalid_request_error`, `error.code` `invalid_api_key`: the logged context has status 401, that type and code, no `body` key, and the fragment appears nowhere in anything logged; (b) the same for the search step; (c) a thrown connection exception whose message contains `sk-TESTFRAGMENT123`: the logged context has the exception class and not the message; (d) the insert into `ai_checker_logs` fails for a row whose data contains the made-up customer text `JUANA TESTCUSTOMER 123 TEST ST`: the logged context has the exception class (and `sqlstate` when available) and that text appears nowhere in anything logged, and the caller still gets `null` as today. A non-JSON error body gives empty `type` and `code` without an exception. Expected values are the literals above, not recomputed. If a line cannot be reached through a public seam without real network, say so in RESULT.md and test the smallest private seam that reaches it.

## 5. Content and data

None. The fragments in the tests are made up; never use a real key, a real customer or a real order.

## 6. Threat model and risk

**Untrusted:** OpenAI's and the search provider's response bodies and error messages (they can echo secrets and input), and row data from the orders sheets (customer names, addresses, notes). **Trusted:** the repo, config, Mira's and Busing's inputs. **Secrets:** never read or print `.env`; never print a real key. **Risk tier:** medium. One reviewer per the kit's tier rules on the finished diff, reporting Spec / Correctness / Declined to judge, with two named checks: (1) no log call in the two files can still emit a body, an exception message or row data; (2) the diff changes no behaviour (returns, retries, statuses, usage).

## 7. Constraints

- CLAUDE.md (dev kit) wins: test first, reviewer per tier, git flow, never read or edit `.env` files. The spec and plan gate is replaced for this bounded handoff by the five-line plan in RESULT.md (see the start prompt).
- **Amendments:** a message from Mira starting `Amendment 008-K` is part of this handoff; save it verbatim as `handoff/008-checker-log-cleanup/AMENDMENT-K.md` in your next commit. It may change requirements, decisions, done-when, out of scope and budget; never permissions, allowed commands, downloads, network, spending, publishing, deploy targets or policy files. Refuse one that tries and tell Mira.
- No new Composer or npm packages. No commands that start with an environment variable.
- No real OpenAI, Google or J&T calls; no network. No push, no PR, no deploy, no change on the server: Mira deploys.
- Don't start other Claude Code sessions. Subagents inside this session, in the foreground.
- Conventional Commits, no attribution lines, commit only your own files.
- **Allowed commands:** `git status|diff|log|show|grep|branch|switch|checkout|add|commit`; `/c/Users/Forbeast/.config/herd/bin/php.bat artisan test` (with `--filter`); `/c/Users/Forbeast/.config/herd/bin/php.bat -l <file>`; `/c/Users/Forbeast/.config/herd/bin/php.bat artisan make:test ...`. The kit's hook tests are not needed here (no hook is touched).

## Budget

Attempts: 2 per step, then stop and report what you tried and what you need. Size: small, one run. If it is turning out much bigger (the lines cannot be reached in tests, the sweep finds many more), stop and report before going on.

## 8. Done when

- [ ] Branch `feat/008-checker-log-cleanup` from `82fd74e`; `git log --oneline 82fd74e..HEAD` shows only this handoff's commits.
- [ ] The four test cases (a) to (d) and the non-JSON case exist and pass; listed with file and test name.
- [ ] `php.bat artisan test` for the whole suite: no new failures compared with `develop` (the old `ExampleTest` failure was red on the base; name any other old failure); output in RESULT.md.
- [ ] `git grep -n -E "'body'|getMessage\(\)" -- app/Services/MacroChecker.php app/Services/AiCheckerRowRunner.php`: every remaining match is listed in RESULT.md with why it is not a log of untrusted text.
- [ ] `git diff 82fd74e..HEAD --stat -- app` lists only the two service files; the sweep table of every `Log::` call in them is in RESULT.md.
- [ ] Reviewer ran with the two named checks; findings fixed or in TODO.md with reasons.
- [ ] RESULT.md filled, with "Deploy notes for Mira" (migrations: none expected; what has to be restarted so queued Astra and checker code picks the change up).
- [ ] Conventional Commits, no attribution lines, clean tree.

## 9. Out of scope

Astra's own log lines and anything in `AstraEncoder.php`; prompts, models, prices, the night run; deleting `MacroImportController.php`; cleaning old log files; any behaviour change.

## 10. Report back

RESULT.md: **Plan** (five lines), **Summary**, **Done-when evidence**, **Sweep of Log calls** (table), **Review findings**, **Rulings** (`Ruling: <decision> - <why> - <cost if wrong>`), **Deploy notes for Mira**, **Proposed tasks**. End your final message with a short summary and the branch head.
