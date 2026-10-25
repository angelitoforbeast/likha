# 148 Connections popup: result

## Plan

Access for every new route: the same rule as today's token route (`ChatbotController::checkAccess`, NavAccess `/chatblast`; 403 for others) and the page must be on the chatbot list.

| Task | Covers | Tier | Side |
|---|---|---|---|
| T1 Connections backend: `ChatbotConnections` service and routes `GET /chatbot/connections`, `POST /chatbot/connections/botcake`, `POST /chatbot/connections/forbeast`, `POST /chatbot/connections/chat-block`. Token probe before save (Botcake custom_fields test; Forbeast `GET /v1/pages/{id}` name and id compare), takeover guard (Botcake `GET default_reply` URL hosts, Forbeast `webhook.url`), save through `PageSettings`, then `BotcakeChatBlock::turnOn` (key kept) or `ForbeastPageSetup::connect`. Answers carry last 4 only. | .1 .2 .3 .4 .5 .6 .13 | high (new routes, provider data) | backend |
| T2 Connected and Check: `connected` list per row in `/chatbot/data`; `PageContentCheck::setup` adds "Botcake: token not working" and "Forbeast: token not working". | .9 | medium | backend |
| T3 Nightly `chatbot:connections-check` after `botcake:setup-check`: Botcake token test, chat block re-applied only for active Botcake-provider pages without chat.key, Forbeast connect for pages with a token; counts only in the log. | .12 | medium | backend |
| T4 UI: Connections button and popup, row Forbeast buttons and prompt removed, page settings Forbeast lines replaced by a link, Connected column, Bot select of complete connections only, Flow text + ✎ popup with search and confirm, header filters, sticky header, 390 px. | .1 .7 .8 .10 .11 .14 .15 | medium | frontend |

JS behaviour (filters, confirms, popups) has no JS test runner in this repo; the PHPUnit tests pin the markup and the server side, the browser parts are listed under Evidence.

## Progress
- [ ] T1  - [ ] T2  - [ ] T3  - [ ] T4

## Files

## Routes added

## Removed

## Takeover guard

## Story changes

## Evidence per case

## Commits

## Open points
