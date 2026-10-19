Small task: spec 081, about 15–20 minutes.

**Branch.** In this worktree, run `git checkout -b fix/081-seq-pairs main`. `main` is d4e37a67 and is live.

**Rules**
- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/081-seq-pairs.md` and the result as `docs/specs/081-seq-pairs.result.md`.

# Brief 081: on /chatbot/pages, each Seq N sits right after its Seq Img N

## Why

`ChatbotFields::defaults()` lists Seq 1–20 first, then Seq Img 1–20, so a step's text and image are 20 columns apart. The owner, 2026-10-19: "image 1 sequence 1 magkatabi para madali lang makita". The PREORDER SEQUENCE steps send image and text together.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| The default column order after PICTURE is Seq Img 1, Seq 1, Seq Img 2, Seq 2, …, Seq Img 20, Seq 20. The image comes first because the step sends the image first, then the text. Keys and labels are unchanged, so no data changes. | The owner. |
| Columns added with "+ Column" still come after the defaults. | — |

## Done when

- A test asserts the order.
- `php artisan test tests/Feature/Chatblast` passes.
- Report the commits.
