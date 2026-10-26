# Brief 155: Forbeast's real send performance in Likha's Bot Health, with an alarm on send lag

## Why
On 2026-10-26 from ~20:05 Manila Forbeast's send queue fell behind (2 workers): customers on 13 Forbeast pages waited up to ~77 minutes for replies, but Likha's Bot Health showed replies "sent" in 1–2 s because Forbeast accepts a send at once and queues it. ForbeastWatch never noticed. Forbeast is adding a send-health endpoint (its spec 016). Likha must read it, show it, and raise an alarm.

## The endpoint (contract, fake it in tests exactly like this)
`GET {config('services.gateway.url')}/v1/health/sends`, same auth as Likha's other gateway calls (the site key `services.gateway.key`, Bearer). Response:
```json
{"at":"2026-10-26T14:11:12Z",
 "overall":{"queued_sends":1001,"send_lag_seconds":2123,"sent_last_5m":5120,"sent_per_min_5m":1024,"failed_last_60m":157,"failed_pct_60m":1.4,"forward_lag_seconds":0,"workers":{"sends":32,"status":"running"}},
 "pages":[{"page_id":"1123777717482685","name":"Alexa Angeles","queued_sends":120,"send_lag_seconds":1800,"sent_per_min_5m":60,"failed_pct_60m":0.5}],
 "thresholds":{"send_lag_amber":30,"send_lag_red":120,"failed_amber":5,"failed_red":15}}
```
`page_id` = the Facebook page id (= the page's `botcake.page_id` in Likha). Missing numbers are 0/null. Until Forbeast deploys it the endpoint answers 404: treat 404 as "Forbeast health not available yet" (grey, no alarm).

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | The existing every-minute `chatbot:forbeast-watch` also fetches the endpoint (timeout 5 s; never blocks the rest of the watch) and keeps samples. **Lean schema:** no new table unless rows can't do it — prefer the cache (keep the last 24 h of per-minute overall samples + latest per-page) or the owner's row-based `app_settings`/`page_settings` precedent; justify the choice in the result. | Owner's no-new-table rule. |
| 2 | **Bot Health page gets a "Forbeast" panel** (when any page is on Forbeast): send lag now, queued sends, sent/min, failed % (60 m), forward lag, workers (count + status), each with the green/amber/red from the endpoint's thresholds; a per-page table for Forbeast pages (lag, queued, sent/min, failed %), worst first; a simple 24 h line of overall send lag (inline SVG or the chart lib the page already uses). "Last updated" time; grey "not available" when the endpoint is 404/unreachable/stale (> 5 min). | The owner: "dapat makita din sa likha yung performance nung forbeast". |
| 3 | **Alarm:** when overall `send_lag_seconds` > 120 for 3 consecutive samples (or a Forbeast page's lag > 120 for 3 samples), record an event row in the same place ForbeastWatch records auto-switch events (`chatbot.auto_switch` history, `result: "alarm"`, condition `send_lag`, error "Forbeast send lag <n>s, <q> queued", pages affected), at most one per 30 minutes while it lasts, plus one "recovered" event when lag is back under 30 s for 3 samples. **No automatic switch to Botcake** for this condition (Botcake copies are not reaching Likha on Forbeast pages, so a switch would leave customers unanswered). Show the active alarm as a red banner on Bot Health and on /chatbot/pages. | The owner's alarm routine reads those rows; a switch would be worse than the delay. |
| 4 | Tiles that already say "first reply" for Forbeast pages get a note: "accepted by Forbeast; real send lag: <n>s" when the endpoint has data. No change to how answered/first-reply are computed. | Honest numbers without a redesign. |
| 5 | Access: same roles as Bot Health today. | Same page. |

## Cases (add as S-155 to qa/stories.md)
P1: .1 fetch every minute, stores samples, 24 h kept; endpoint 404 → "not available yet", no alarm; timeout/500 → stale after 5 min, no crash, the rest of the watch still runs; .2 panel numbers and colours match a faked response (green/amber/red cases); .3 per-page table matches pages by Facebook page id and lists worst first; .4 three samples > 120 s → one alarm event (fields as above), no second within 30 min, then "recovered" after three samples < 30 s; .5 a single spike (1–2 samples) → no alarm; .6 no provider switch happens on send_lag; .7 banner on Bot Health and /chatbot/pages while the alarm is active; .8 Bot Health still renders when no page is on Forbeast (panel hidden); .9 roles unchanged. P2: .10 390 px.

## Done when
- P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode` adds no new failures vs 66ec2a4e (record the baseline first).
- Result: files, storage choice, the alarm rule, evidence per case, commits. End with "155: <done|blocked> — <one line>".

## Out of scope
Forbeast code; switching providers on send lag; Incepxion-only changes; deploying.
