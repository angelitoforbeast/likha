# Result 065: the Chats "Botcake setup" box shows the Botcake variables that work

Branch `fix/065-chat-setup-vars`, cut from `main` at c8bc3c71. Not pushed.

## Commits

| Commit | What |
|---|---|
| `14075807` | docs: the brief, verbatim |
| `5d288da9` | fix: one Botcake block for every page with the working variables; `user.id` and `user.full_name` are no longer suggested |
| (this commit) | docs: result and stories |

## Changed files

- `app/Http/Controllers/ChatblastChatController.php`:
  - `setup()` no longer takes the selected page.
  - The values are `{{page_id}}`, `{{psid}}`, `{{user_full_name}}` and `{{last_text_input}}`, used both for the JSON body and for the GET Params rows.
- `resources/views/chatblast/chats.blade.php`: the intro line now says "The same block works on any page: Botcake fills in the page, the customer and the text itself, so the values below can be pasted unchanged." The "type each page's own Botcake ID" wording is gone.
- `app/Http/Controllers/BotcakeChatController.php`: only the comment changed (Botcake does fill `{{page_id}}`). The aliases are unchanged, so old blocks keep working.
- `tests/Feature/Chatblast/BotcakeChatTest.php`:
  - S-059.16 is replaced by S-065.1, and S-065.2 is added.
  - One wrong comment in S-059.3 is corrected; it called `user.id` and `user.full_name` Botcake variables.
- `qa/stories.md`: S-059.16 is marked as replaced; S-065 is added.

## Done-when checks

- The body is shown with and without a page selected: S-065.1 asserts it on `/chatblast/chats` and `/chatblast/chats?page_id=2002`. It also asserts that the page's own ID never appears in the body.
- `git grep -n "user\.id\|user\.full_name" -- resources/views/chatblast app/Http/Controllers/ChatblastChatController.php` prints nothing.
- `user_full_name` is stored as the name: S-065.2.
- `php artisan test tests/Feature/Chatblast`: every test passes (1489 assertions). The only warnings come from the environment: the worktree has no env file.

## Case table

| Case | Test | Result |
|---|---|---|
| S-065.1 | `test_S_065_1_the_setup_box_gives_one_body_with_botcake_variables_for_every_page` | pass |
| S-065.2 | `test_S_065_2_user_full_name_is_stored_as_the_name` | pass |

**Red run:** S-065.1 failed first; the page still showed the old body with `{{user.id}}`. S-065.2 passed at once, because the endpoint has accepted `user_full_name` since 059 amendment 1. It pins that behaviour.

## Full suite

`php artisan test` on `5d288da9`: **2 failed, 175 passed, 1636 passed with warnings** (19575 assertions, 394 s). The only failures are the known `ExampleTest` and `NightRunImportStartTest`. There are no new failures.

## What the brief got wrong

- **"You may add `user_full_name` to the name aliases":** it was already there. The alias list has been `name`, `full_name`, `user.full_name`, `user_full_name` since 059 amendment 1. Nothing was added; S-065.2 now pins it.
- **The brief's wording "The GET Params rows show the same four values" is met.** The rows were already built from the same values as the body, and the `key` row is unchanged.

## Review

No reviewer pass. This is a copy change in one setup box behind the existing access check: a low tier under the kit, so lint, the Chatblast tests and the full suite stand in for a review.

## Merge danger

None. There is no migration and no config change, and the endpoint accepts the same params as before. Blocks already set up with `{{user.id}}` or `{{user.full_name}}` keep being accepted, but those values arrive as literal text, so those blocks need the new body pasted in.
