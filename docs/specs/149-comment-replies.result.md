# 149 Comment replies: result

## Plan

Storage: a small `chatbot_comment_events` table (one row per comment), not `chatbot_replies`. Reasons: `chatbot_replies.kind` is `varchar(10)` (`comment_reply` does not fit, and widening it rebuilds a large MySQL table), `psid` is required (a comment may have no `from_id`), and one row per comment gives the dedupe (unique `comment_id`), the skip reason and both results in one place. The Replies page gets a "Comments" view that reads this table; Health counts from it.

Event path: `/api/gateway/events` (same signature check, unknown page 401) → `HandleGatewayEvent` sees `type: comment` → `CommentReplies::receive()` stores the row (`insertOrIgnore` on `comment_id`: the second copy is a duplicate) → job `HandleComment` (chatbot queue) → `CommentReplies::handle()`: checks (Forbeast provider, bot On, top level, not page-authored, not one of ours, values set), the per-page slot (20 per minute; the job is released to the next minute when full), then the private reply (Comment PM), then the public reply (one random Comment Reply). Sends go through `GatewayComments` (the page's Forbeast token, else `GATEWAY_KEY`, like `GatewayProvider`). Random seam: `CommentReplies::pickUsing(callable)`.

| Task | Covers | Tier | Side |
|---|---|---|---|
| T1 Columns: `comment_reply_1..3`, `comment_pm` as default botfields (long text), paste-mapping descriptions. | .1 | medium | backend |
| T2 Comment intake and replies: migration, `HandleGatewayEvent` comment branch, `CommentReplies`, `HandleComment`, `GatewayComments`. | .2 .3 .4 .5 .6 .7 .8 .10 | high (new event type from the public, publishing to Facebook) | backend |
| T3 Logs: Replies page "Comments" filter (server + small view change), Health counts (comments in, replied, failed). | .9 | medium | backend + frontend (one small view change, done by the same slice) |
| T4 Check: Forbeast's `GET /v1/pages/{id}` does not report scopes today, so the permission check is skipped and noted. | .11 | low | — |

## Progress
- [ ] T1
- [ ] T2
- [ ] T3
- [ ] T4
- [ ] Review

## Files

## Columns

## Story changes

## Evidence per case

## Commits

## Open points
