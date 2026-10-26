# Brief 160: hide the "pause the bot" controls in the Inbox, keep the code

## Why
The owner doesn't want staff pausing the bot per customer from /chatbot/inbox ("itago mo na lang button, kaya lang yung code"). Today the Inbox can pause via the manual-reply `pause` option (`ChatbotInboxController` ~line 170) and a paused toggle (~line 213), plus a bot/human filter (`InboxFilters` ~line 126). 0 customers are paused on both sites today.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Hide every UI control that pauses/unpauses the bot in the Inbox (the pause checkbox/option on manual reply, the pause toggle, and the bot-paused filter option). No server code removed: the endpoints, `BOT_PAUSED`, the runner/sequence checks stay. | Owner: hide, keep the code. |
| 2 | Server side: ignore a `pause`/`paused=1` request from the Inbox (don't set BOT_PAUSED) behind a config flag `chatbot.inbox_bot_pause` (default false), so an old cached page can't pause a customer. With the flag true, behaviour is exactly as today. Unpause (`paused=0`) still works so any paused customer can be released. | Hidden must mean off. |
| 3 | If a customer is already paused, the Inbox still shows the "bot paused" badge with an "Unpause" action (so it can be undone). | No stuck customers. |

## Cases (S-160)
P1: .1 the Inbox page renders without pause controls (flag false); .2 POST reply with pause=1 sends the reply but does not set BOT_PAUSED; POST paused=1 doesn't set it; paused=0 clears it; .3 flag true → today's behaviour (existing tests pass with the flag on); .4 an already-paused customer shows the badge and Unpause works. P2: .5 390 px.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast` adds no new failures vs e0bbbdb7. Result; end with "160: <done|blocked> — <one line>".
