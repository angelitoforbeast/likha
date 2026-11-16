# 279 Show "Walang comment permission" from the gateway, and keep the page on the gateway

Branch `feat/279-comment-permission`, based on `81c26d5e` (the commit live on Likha). The same code also runs incepxion.pro; its release is handled separately.

## Why
The forbeast gateway (incepxionbot.com and app.forbeast.app) is getting a fix: a comment reply that Meta refuses for permission no longer marks the page token as needing a reconnect. Instead the gateway reports that comments are not allowed for that page and Meta app. Likha must show this, keep the page on the gateway (messages still flow), and treat a refused comment as its own reason.

## Gateway contract (from its spec 066; treat every value as untrusted)
- The page check `GET /v1/pages/{id}` gains `comments`: `{"<app_key>": "ok" | "no_permission" | null}`, next to the existing `apps`. A gateway without the field (older version) means "not known".
- The comment endpoints (`POST /v1/pages/{page}/comments/{comment}/reply` and `.../private-reply`, called from `app/Services/Chatbot/GatewayComments.php`) answer a refused comment with the machine code `comments_not_allowed` in the error body. Read the code from the body without assuming the HTTP status (it will be a 4xx).

## Decisions already made (do not reopen)
| Decision | Reason |
|---|---|
| No comment permission never moves a page off the gateway | The owner: show it, but proceed |
| The label is shown, not an error banner | It is a known limit, not an outage |

## Stories
1. The page check result's `comments` is kept per page and gateway the way `apps` is (`ForbeastPageSetup::appStatuses` / `keepApps` / `apps`, used from `ForbeastWatch` about L613 and `ChatbotController` about L384): a new `<gateway>.comments` page setting (JSON), written only when it changes; known keys and values only; a missing field changes nothing.
2. A comment reply or private reply (`CommentReplies`, `GatewayComments`) answered with `comments_not_allowed` records `no_permission` for that page and route, logs the reply with its own clear reason (not a generic failure), and is not retried in a loop.
3. A later successful comment reply on that route sets it back to `ok`.
4. The chatbot page list (`ChatbotController` around L170-210, where `gateway_app` and `route_apps` are passed, and its view) shows "Walang comment permission" beside the page's current gateway route when that route is `no_permission`. The comment Check (`app/Services/Chatbot/CommentCheck.php`) shows the same reason.
5. `ForbeastWatch` never switches a page back to Botcake because of comment refusals or `no_permission`; the existing `apps` check (L628-633) is unchanged.

## Constraints
- A feature test per story (Http::fake for the gateway) that fails before and passes after; the whole suite adds no new failures (the base has 3 known failures in `tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php`; state the counts).
- Run PHP with `C:/Users/Forbeast/.config/herd/bin/php84/php.exe` (php is not on PATH).
- Comments in code follow the file's existing style (Taglish is fine).
- When done, push the branch: `git push origin feat/279-comment-permission`. Never push to main.
- Budget: medium job, at most 2 attempts per step. If it needs much more than these files, stop and write why under Questions.

## Report back
The result file: what changed per story, test output, head commit, migrations (if any), and the page setting keys added.
