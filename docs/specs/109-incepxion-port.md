# Brief 109: bring Incepxion up to Likha's main (specs 066–105) so its chatbot can be tested

**Branch.** `git checkout -b release/incepxion-port refs/remotes/vps2/main` (Incepxion's live code, 4a241ce0 = spec 065, Oct 19). Likha `main` (7234bd0a, spec 105) is 239 commits ahead; Incepxion has 2 commits Likha's main may hold under other ids (f8f4fb19 spec 064, 4a241ce0 spec 065).

Save this brief as `docs/specs/109-incepxion-port.md` and the result as `docs/specs/109-incepxion-port.result.md`. Budget: 2 attempts per failing step.

## Why

Incepxion runs the same app as Likha on its own server and database. It stopped at spec 065 (Oct 19). Since then Likha got the chatbot (flows, AI, sequences, results), page settings for the chatbot, the Redis/Horizon queue work and its speed-ups, ad accounts history, item-settings log and more. The owner, 2026-10-21, wants to test the chatbot on Incepxion now ("pwede na magtest ngayon", then "go mo na" for the port).

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Merge Likha `main` into this branch** (a merge commit, not a rebase). For each conflict, check whether 064/065's change is already in main (compare the code, not the ids); prefer main's version when it contains it; keep any Incepxion-only difference only if it is truly Incepxion-specific, and list it. | One codebase, one update for both. |
| **No Likha-specific data or settings** may be baked into code (page names, sheet links, tokens, IPs, fixed names). Anything per-business must be config/env or DB rows. Grep the diff for Likha page names, the LDT sheet id, Likha IPs, likhaaitech.com, and list each hit with what you did. | Incepxion is a different business. |
| **List every migration** that will run on Incepxion (those after the last one present at 4a241ce0), each with: new tables / columns, any data backfill, and whether it is safe on a database that never had the chatbot. | The release needs the owner's yes per migration batch. |
| **List the server prerequisites** the new code needs, from config and docs/specs results: Redis (Likha runs two: 6379 queue/locks noeviction+AOF, 6380 cache allkeys-lru), predis, Horizon supervisors (chatbot, chatblast), supervisor programs (chatbot-ai pool, botcake send pool, queue default), env keys by NAME only (QUEUE/CACHE/REDIS/CHATBOT_*/CHATBLAST_*/REDIS_QUEUE_BLOCK_FOR/BOTCAKE_* …), scheduled commands, nginx/PHP-FPM settings Likha changed. | So the deploy can be planned. |
| No product changes in this branch beyond the merge and conflict fixes. | A port, not a feature. |

## Done when

- `release/incepxion-port` contains Likha main 7234bd0a (`git merge-base --is-ancestor 7234bd0a HEAD` true).
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake tests/Feature/AdAccount tests/Feature/OwnerPrivate` passes; full suite: no failures beyond those also failing on Likha main (list them).
- The result has: conflicts and how each was resolved; the Likha-specific grep hits; the migration list; the server prerequisites; the env key names; and the commits.
