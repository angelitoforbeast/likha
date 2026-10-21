# Plan 099: pages check and history

1. **Check service** (backend, medium): `App\Services\Chatbot\ChatbotPageCheck` (missing, pairs, unknown, images) and
   `App\Services\Chatbot\ImageLinkCheck` (HEAD / ranged GET, 5 s, SSRF guard with an injectable resolver, 24 h cache per
   URL, at most 20 network checks per run). The page result is cached 10 minutes under a key that includes a fingerprint
   of the page's settings (max id, max replaced_at) and of the flows and sequences (max updated_at), so any write makes
   the old entry unreachable without touching `PageSettings`. Cases S-099.1 to S-099.5.
2. **Endpoints** (backend, medium): in `ChatbotController`: `GET /chatbot/check` (cached), `POST /chatbot/check` (Check
   again), `GET /chatbot/preview`, `GET /chatbot/history`, `POST /chatbot/restore`, `GET /chatbot/changes`. Access as
   `/chatbot/pages` (`checkAccess`); restore as a cell edit. Cases S-099.6 to S-099.10.
3. **Screen** (frontend, medium): `chatbot/index.blade.php`: Check column after FLOW (icons from page settings), Preview
   on the row and in the Edit modal, History per field with Restore, Changes button and modal with filters and pager.
   Case S-099.11 (browser).

`PageSettings` is not changed (another worker adds a cache to `get`). The 097 grouping helper is not merged, so the
check has its own split: system variables (`ChatbotFlowController::RUN_VARS`), botfields (column keys and labels,
normalized as `ChatbotVars::norm`) and customer fields (fields set by any flow, and keys in `chatbot_customer_values`).
