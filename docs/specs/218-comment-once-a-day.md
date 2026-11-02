# Spec 218: one comment answer per customer per page per day

**Project:** Likha (likhaaitech.com) and Incepxion (incepxion.pro) — one codebase.
**Stack:** Laravel, MySQL, queues on Redis/Horizon, PHPUnit.
**Shape:** change · **Weight:** bounded · **Risk tier:** medium (messages to customers)

> Committed with the work as `docs/specs/218-comment-once-a-day.md`. Names no people and no decision ids:
> "the owner" decided, "the reviewer" checks and merges.

---

## Why

`App\Services\Chatbot\CommentReplies` answers every top-level comment on a Forbeast page once: a Comment PM (private message) and one of Comment Reply 1–3 (public). A customer who comments five times gets five private messages and five public replies. The owner wants one answer per customer per page per day: at most one Comment PM and one public reply per commenter (`from_id`) per page per Manila calendar day. Later comments that day are recorded and skipped with a reason.

`chatbot_comment_events` already stores `page_id`, `from_id`, `status`, `pm_status`, `reply_status` and `received_at`, with an index on (page_id, received_at).

## Stories

Add to `qa/stories.md` under `## Slice 218 – Comment answer once a day`. Keep these case IDs.

### S-218.1 One answer per customer per page per day (P1)
| Case | Type | Given / When / Then | Test | Last run |
|------|------|---------------------|------|----------|
| S-218.1.1 | happy | Given customer C's first comment today on page P, then the Comment PM and the public reply are sent as today | | Not run |
| S-218.1.2 | happy | Given C was already answered on P today (a PM or a public reply sent, or an outcome that may have been posted: unknown/504), when C comments again on P (same or another post), then nothing is sent and the row is skipped with reason `daily_limit` ("already answered today") | | Not run |
| S-218.1.3 | happy | Given C was answered on P yesterday at 23:50 Manila, when C comments at 00:10 Manila, then C is answered (the day is the Manila calendar day) | | Not run |
| S-218.1.4 | happy | Given C answered on page P today, when C comments on page Q, then C is answered on Q (the limit is per page) | | Not run |
| S-218.1.5 | negative | Given C's earlier comment today was skipped (bot off, not set, a reply to a reply, page author) or failed before anything was sent, then a later comment today is still answered | | Not run |
| S-218.1.6 | edge | Given two comments from C on P arrive at the same moment, then exactly one is answered and the other is skipped `daily_limit` (a lock or an atomic check per page and commenter) | | Not run |
| S-218.1.7 | edge | Given a comment with no `from_id`, then it is answered as today (no limit can apply) and the result says so | | Not run |
| S-218.1.8 | edge | Given a comment waiting in the queue because the page's minute was full (429 / 20 per minute), then the limit is checked when it is sent, not only when it arrived | | Not run |
| S-218.1.9 | happy | Given the place the owner sees comment outcomes today (the reasons list / health or inbox views that read `REASONS`), then `daily_limit` shows as "already answered today" | | Not run |

**Tests:** one failing test per case first, named with its case ID; Forbeast is faked with `Http::fake`.

## Constraints

| Topic | Decision |
|---|---|
| Rule | At most one Comment PM and one public reply per (page, from_id) per Manila calendar day. Both parts follow the same rule: a comment is either answered (both parts as configured) or skipped. |
| What counts as answered | A row of that page and commenter today whose PM or public reply was sent, already_replied, or unknown (may have been posted). Skipped and failed-before-sending rows do not count. |
| Storage | No migration and no new table if the existing index serves the check; if the plan shows a needed index, say so in a ruling and add it in an additive, reversible migration. |
| Queued jobs | Do not change the properties of `App\Jobs\HandleComment` or any other queued job class (a deploy with new typed properties failed thousands of queued jobs). |
| Base | `vps/main` at f5d6d450 (live Likha after spec 215; Incepxion runs the same tree). Branch `feat/218-comment-once-a-day`. |

**Threat model.** Untrusted: comment events from the gateway (`from_id`, ids). Trusted: database, config. Medium: double sends under concurrency.

**Rules**
- Don't start other Claude Code sessions; use subagents inside this session. Talk only to the reviewer. Plan first, then build.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines. Conventional subjects ending "(spec 218)".
- Code comments state the reason itself, in the language and style of the surrounding code; no people or decision ids.
- No commands that start with an environment variable. Downloads: the project's own installs only. Never open `.env`.
- Another worker is building spec 217 (the /chatbot/pages screen, ad groups, /chatbot/test) on its own branch; stay out of those files.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report.
- Size: small job.

## Done when

- [ ] Cases S-218.1.1 – S-218.1.9 pass, each with a test named after it.
- [ ] The result has a red line for every feat/fix slice, watched before the code.
- [ ] The full suite adds no new failures (known baseline: BigBroadcastTest S_167_1, ExampleTest, ItemTableFitScriptTest, AstraAddressRulesTest S_26_18; S_26_23 is timing-sensitive).
- [ ] `qa/stories.md` has these stories with "Last run" filled.
- [ ] `docs/specs/218-comment-once-a-day.result.md` is filled in, with Deploy notes.

## Out of scope

Botcake pages' own comment feature; changing the reply texts or the 20-per-minute limit; Forbeast; any deploy.

## Report back

Fill in the result and commit it with the work: every ruling (`Ruling: <decision> — <why> — <cost if wrong>`) and deferred minors, then message the reviewer that it's ready, with **Merge danger** (door, blast radius, revert).
