# 150 Comment content check: result

## Plan

One new service, `App\Services\Chatbot\CommentCheck`, holds the comment checks. `PageContentCheck` and `ChatbotPageCheck` call it, so the nightly run, "Check again", the `--json` command and the Check column all see the same findings.

| Task | Covers | Tier | Side |
|---|---|---|---|
| T1 Rules (no AI): the Comment Reply 1–3 format (one variant, identical variants, link or phone, over 300 characters, another page named), the PM order-form cue and another page in the PM, and the info line for Botcake pages. `ChatbotPageCheck` merges the findings, also on a page without a flow. | .3 .2 (form cue) .8 | medium | backend |
| T2 Comment PM AI check: the same `TEXT_PROMPT`, cache (`chatbot_content_answers`) and cost columns as Prompt/Seq. Another item → X, a price that does not match → X, no price → warning. The nightly job and "Check again" ask the AI; the page view reads only the cache. | .1 .2 (price) .6 .7 | medium | backend |
| T3 Permissions: the Forbeast page check (`probe`, used by connect, Test and the nightly connections check) keeps the `permissions` field as `forbeast.permissions` (written only when it changes; removed when the field is absent and on disconnect). The Check shows an X when either scope is missing, "Forbeast: permissions unknown" as info when there is no field, and an X for a Meta #10/#200/#230 comment-send error in the last 24 h. | .5 | medium | backend |
| T4 "Use template": the template texts come from `CommentCheck::templates()`, which uses the page's real botfield labels for item and price. A button in the Edit modal (it fills the fields; Save still saves) and in the long-text cell editor (it fills the textarea; it saves on close, as that editor always does). | .4 | medium | backend + frontend (one view, one slice) |

Rules:
- Another page is named when another chatbot page's name appears in the text as whole words, case-insensitive. A name that is part of this page's own name does not count.
- A link is `http(s)://`, `www.` or a domain such as `shop.ph`. A phone number is a PH mobile number (`09…`/`+639…`) or a run of 7 or more digits.
- The order-form cue matches the lines Name, Mobile/Phone, Address and Landmark (the same patterns as the Fill-Up Form check), or a call to reply such as "reply", "mag-reply", "i-message", "PM" or "send".
- The Check gets a new `notes` list, shown in grey, for info lines.

The skeptic review runs once at the end (standard depth: medium tier, no new way in for untrusted input).

## Progress
- [ ] T1
- [ ] T2
- [ ] T3
- [ ] T4
- [ ] Review

## Files

## Rules

## Prompt text added

## Story changes

## Evidence per case

## Commits

## Open points
