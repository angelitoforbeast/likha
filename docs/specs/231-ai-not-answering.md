# Spec 231: "AI not answering" in the problems banner

**Project:** Likha (Laravel; the same code runs incepxion.pro). The chatbot problems banner (app/Services/Chatbot/ProblemsPanel.php, shown on every chatbot screen, with Acknowledge/Done in ProblemAcks).
**Shape:** change · **Weight:** bounded · **Risk tier:** medium (read-only checks on a shared banner, which must stay fast and never break the page)

## Why
When a page's AI step gets no answer, the banner shows nothing today. It only says "customers not answered in 5 min" once 3 customers on a page got no reply at all. The causes of no answer: OpenAI gave an empty answer, did not answer, or the AI request is stuck in the queue. The AI step then leaves by its "failed" port. If that port is wired to a fallback, the customer still gets a reply; if not, the customer gets nothing from that step on.

On incepxion.pro on one day: 21 of 52,982 AI requests had no answer (18 "OpenAI gave an empty answer.", 3 "OpenAI did not answer."), at most 2 per page. Read the AI queue (ChatbotAiQueue::TABLE: status, error, answer, created_at, answered_at, page_id, flow_id, node_id, chat_id) and the flow graph ports (FlowGraph::ports, where 'ai' => ['success','failed']).

## Decisions already made (settled with the owner; don't reopen them unless something is actually broken)
| # | Topic | Decision |
|---|---|---|
| 1 | Where | This banner only, on Likha and incepxion.pro. Nothing is sent to or shown on Forbeast. |
| 2 | Red, per page | 3 or more AI requests of the page with no answer (error set, or an empty answer) within the last 15 minutes. Text such as "AI not answering: 4 of 9 AI replies failed in 15 min (OpenAI gave an empty answer)", with links to the affected chats in the Inbox and to the flow step. |
| 3 | Red, per page | Any AI request of the page waiting longer than 2 minutes for an answer (not answered, not failed). Text such as "AI stuck: 2 replies waiting over 2 min". |
| 4 | Red, one line for the site | When AI requests are failing on most pages within 10 minutes (at least 5 pages with a failure, and more than half of the pages that asked AI in that window), show ONE site-wide line ("AI is failing on 37 of 52 pages: OpenAI or the key may be down") instead of a line per page. The per-page lines from decisions 2 and 5 are then not shown. |
| 5 | Orange, per page | 1 or 2 failures within 15 minutes where the AI step's "failed" port is not wired in the flow version that ran, so that customer got nothing. Text names how many customers got no reply, with an Inbox link. |
| 6 | Not shown | A failure whose "failed" port is wired (the fallback went out), and AI steps skipped because the page has no AI load (error ChatbotAiLoad::ERROR). Load is shown on Forbeast, not here. |
| 7 | Same machinery | Lines use the existing line()/level/kind/Acknowledge/Done flow, the CACHE_SECONDS cache and safely(), so a failure in this check never breaks the banner. The thresholds go in config('services.chatbot.problems') with env defaults (ai_fail_count 3, ai_fail_minutes 15, ai_stuck_seconds 120, ai_site_minutes 10, ai_site_min_pages 5). |
| 8 | Cost | The check is a few grouped queries over the last 15 minutes of the AI queue, never a query per page, and it must use an index on created_at (add one in a migration if the table lacks it). |

## Stories
Add these to qa/stories.md under `## Slice 231 – AI not answering`, in that file's format, with one failing test per case first, named with its case ID.

S-231.1 Repeated AI failures on a page are red (P1)
- .1 happy: 3 failures on page P within 15 min → one red line for P with the count and the most common error, plus Inbox and flow links.
- .2 negative: 2 failures → no red line (see S-231.4 for orange).
- .3 negative: 3 failures spread over 20 min (only 2 inside the window) → no red line.
- .4 edge: failures on 2 pages → one line each, never merged.
- .5 edge: an empty answer and "did not answer" both count; "No AI load" skips do not.

S-231.2 Stuck AI requests are red (P1)
- .1 happy: a request older than 2 min with no answer and no error → red "AI stuck" for its page.
- .2 negative: 90 seconds → nothing.
- .3 edge: once answered, the line is gone on the next banner refresh.

S-231.3 Site-wide failure is one line (P1)
- .1 happy: failures on 6 of 10 pages that asked AI in 10 min → one site-wide red line, and no per-page AI lines.
- .2 negative: failures on 3 of 10 pages → per-page rules only.
- .3 edge: 5 of 9 pages → the site-wide line (more than half and at least 5).

S-231.4 A failure the customer never saw a reply for is orange (P1)
- .1 happy: 1 failure where the AI step's failed port is not wired → an orange line naming 1 customer with no reply.
- .2 negative: 1 failure where failed is wired → nothing.
- .3 edge: the flow was republished since; the check uses the flow version that ran (flow_version_id on the run or reply, as available; if the version can't be found, treat the port as not wired and say so in a Ruling).

S-231.5 It behaves like the other banner lines (P2)
- .1 happy: Acknowledge for 1 hour hides the line, and it comes back after.
- .2 happy: Done hides it until a new failure.
- .3 edge: a database error inside the check → the rest of the banner still shows.
- .4 edge: the banner HTML at 390 × 844 → the new lines wrap, with no sideways scroll.

## Constraints
**Threat model.** No new input: the check reads the database only. Medium tier.

**Rules**
- Don't start other Claude Code sessions.
- Run the ProblemsPanel and PageProblems tests often, and the full suite once at the end. Name any failure already red on b26149a5 as pre-existing.

## Budget
- Attempts: at most 2 tries at the same step, then stop and report.
- Size: medium.

## Done when
- [ ] Cases S-231.1 to S-231.5 pass, each with a test named after it.
- [ ] The full suite adds no new failures compared with b26149a5.
- [ ] qa/stories.md has slice 231.
- [ ] The result is filled in, with the query plan or index used and the measured banner build time on a seeded table of 50,000 AI rows.

## Out of scope
Anything on Forbeast, changes to how the AI step behaves, notifications outside the banner, and deploying (the reviewer releases it).

## Report back
Fill in docs/specs/231-ai-not-answering.result.md, including every ruling (`Ruling: <decision> — <why> — <cost if wrong>`). Then end with the "231:" line.
