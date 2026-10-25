# 151 Comment content check: result

> Renumbered from 150 to 151, because the chatbot AI provider spec, also numbered 150, went live first. The brief in `docs/specs/151-comment-content-check.md` is kept verbatim, so its heading still says "Brief 150" and its cases say S-150; in the stories, tests and this result they are S-151.

## Plan

One new service, `App\Services\Chatbot\CommentCheck`, holds the comment checks. `PageContentCheck` and `ChatbotPageCheck` call it, so the nightly run, "Check again", the `--json` command and the Check column all see the same findings.

| Task | Covers | Tier | Side |
|---|---|---|---|
| T1 Rules (no AI): the Comment Reply 1–3 format (one variant, identical variants, link or phone, over 300 characters, another page named), the PM order-form cue and another page in the PM, and the info line for Botcake pages. `ChatbotPageCheck` merges the findings, also on a page without a flow. | .3 .2 (form cue) .8 | medium | backend |
| T2 Comment PM AI check: the same `TEXT_PROMPT`, cache (`chatbot_content_answers`) and cost columns as Prompt/Seq. Another item → X, a price that does not match → X, no price → warning. The nightly job and "Check again" ask the AI; the page view reads only the cache. | .1 .2 (price) .6 .7 | medium | backend |
| T3 Permissions: the Forbeast page check (`probe`, used by connect, Test and the nightly connections check) keeps the `permissions` field as `forbeast.permissions` (written only when it changes; removed when the field is absent and on disconnect). The Check shows an X when either scope is missing, "Forbeast: permissions unknown" as info when there is no field, and an X for a Meta #10/#200/#230 comment-send error in the last 24 h. | .5 | medium | backend |
| T4 "Use template": the template texts come from `CommentCheck::templates()`, which uses the page's real botfield labels for item and price. A button in the Edit modal (it fills the fields; Save still saves) and in the long-text cell editor (it fills the textarea; it saves on close, as that editor always does). | .4 | medium | backend + frontend (one view, one slice) |

The skeptic review ran once at the end (standard depth: medium tier, no new way in for untrusted input).

## Progress
- [x] T1, T2, T3, T4: built test-first in one slice. Red run: 16 S-151 tests, 13 failures and 2 errors (`Class "App\Services\Chatbot\CommentCheck" not found`; e.g. S-151.3 "one variant": `Failed asserting that two arrays are identical`). All green after the build.
- [x] Review: `skeptic-reviewer`, standard depth, on `727441d6..04a5b3e9`. No blockers or majors. Minors on the untrusted path were fixed in one wave, test-first:
  - the reply-cue words with Taglish prefixes (Mag-PM, Pakisend, Message lang, Sagutan);
  - no false link on "po.Online" (a missing space after a period);
  - one warning per group of identical replies;
  - an exact-count test that the PM finding shows once on a page with a flow;
  - the whitespace slip on the renamed line.

  The other minors are in `TODO.md` with reasons.
- [ ] Browser check: not run. This worktree has no running app or database. The "Use template" clicks are covered only by the server and markup test.
- Deviation: the kit wants the plan sent to the reviewer for a "go" before code. The brief said "write the plan, commit, continue", so I continued.

## Files
- New: `app/Services/Chatbot/CommentCheck.php`.
- Changed:
  - `app/Services/Chatbot/PageContentCheck.php`: `comments()` (the rules plus the PM AI check), called from `run()`. `FORM_FIELDS` and `fill()` are now public so `CommentCheck` can reuse them.
  - `app/Services/Chatbot/ChatbotPageCheck.php`: `run()` wraps the old body (now `runFlow()`) and merges the comment findings: X → reasons, warning → warnings, info → the new `notes`. The content loop skips kind `comment`, so nothing is counted twice.
  - `app/Services/Chatbot/ForbeastPageSetup.php`: `probe()` returns `permissions`; `connect()` and `retest()` keep it in `forbeast.permissions`; `disconnect()` removes it.
  - `app/Services/PageSettings.php`: `forbeast.permissions` is a system key, so staff cannot type it.
  - `resources/views/chatbot/index.blade.php`: "Use template" in the Edit modal and the cell editor, and notes in grey in the Check cell.
  - `tests/Feature/Chatblast/ChatbotContentCheckTest.php` (S-151) and `tests/Feature/Chatblast/ChatbotPageCheckTest.php` (S-149.34).
  - `qa/stories.md` (S-151) and `TODO.md`.

## Rules
Every value is checked after the page's `{{Botfield}}` variables are filled, the way it is sent.

**Comment PM**
| Rule | Level | Text |
|---|---|---|
| The item the AI finds is not the expected item (≥70% sure) | X | `Comment PM: <item> ≠ orders: <item> (90%)` |
| The price matches neither the COD nor PRICE (±₱1) | X | `Comment PM: price ₱399 ≠ orders ₱299 COD` |
| No price | warning | `Comment PM: no price` |
| Neither all four form lines (name, phone/mobile/number, address, landmark) nor a call to reply | warning | `Comment PM: no order form or call to reply: add Name, Mobile Number, Complete Address and Landmark, or ask the customer to reply for the form` |
| Another page named | warning | `Comment PM: names another page (<page>)` |

The call to reply is a word list: reply, sagot, sumagot, sagutan, type, message, PM or send, alone or with paki-, pa-, mag- or i-.

**Comment Reply 1–3**
| Rule | Level | Text |
|---|---|---|
| Only one variant filled | warning | `Comment Reply: only one variant: add 2–3 variants: the same text on every comment can be flagged as spam` |
| Identical variants, ignoring case and spaces (one warning per group) | warning | `Comment Reply 1 and Comment Reply 2 are the same: …` |
| A link: `http(s)://`, `www.`, or a lowercase domain with 3+ characters before a TLD (`shop.ph`) | warning | `Comment Reply 1: has a link: keep the public reply short; the details go in the PM` |
| A phone number: a PH mobile (`09…`, `+639…`) or 7+ digits in a row | warning | `Comment Reply 1: has a phone number: …` |
| Over 300 characters | warning | `Comment Reply 1: 301 characters: …` |
| Another page named | X | `Comment Reply 2: names another page (<page>)` |

**Another page.** Another page "is named" when a page with a Botcake ID appears in the text as whole words, case-insensitive. Names inside this page's own name don't count, and neither do names shorter than 3 characters.

**Permission** (Forbeast-provider pages with any comment column filled):
| Rule | Level | Text |
|---|---|---|
| Stored scopes lack `pages_manage_engagement` or `pages_read_user_content` | X | `Forbeast: walang comment permission — i-connect ulit ang page` |
| A comment send failed with Meta #10, #200 or #230 in the last 24 h (`pm_error`/`reply_error`) | X | the same text |
| No stored scopes (Forbeast sends no `permissions` field) | info (notes) | `Forbeast: permissions unknown` |

**Botcake-provider page** with comment columns: info (notes), `Comment columns: comments are answered only on Forbeast pages`.

## Prompt text added
None. The Comment PM goes to the AI with the existing Prompt/Seq `TEXT_PROMPT`, unchanged, as `Expected item: …\nField: Comment PM\n<<<TEXT … TEXT>>>`. It uses the same cache key shape (`v<row id>:<sha1 of the filled text>` plus the expected item) and the same cost columns in `chatbot_content_answers`.

## Story changes
- **S-149.34 (`ChatbotPageCheckTest`):** the test now asserts only the length warnings ("Forbeast sends at most"). Its fixture (one 8001-character reply, a PM with no form) now also gets the S-151 format warnings, which is correct.
- **S-151.3:** added two rows the brief did not list. Three identical replies give one warning, and "po.Online" (a missing space after a period) is not a link. The review showed both false alarms.
- **S-151.4:** the cell editor has no Save button; it saves when closed, as it always has. "Use template" there fills the textarea without the typing auto-save, and shows "Not saved yet: edit it, then close to save".

## Evidence per case
| Case | Test | Result |
|---|---|---|
| S-151.1 | `test_S_151_1_a_comment_pm_naming_another_item_is_an_x_and_a_matching_item_and_price_passes` | pass |
| S-151.2 | `test_S_151_2_a_comment_pm_without_a_price_or_without_a_form_or_reply_cue_is_a_warning` (5 cue phrases) | pass |
| S-151.3 | `test_S_151_3_comment_replies_get_format_warnings_and_another_page_is_an_x` (9 rows), `test_S_151_3_a_comment_pm_naming_another_page_is_a_warning_and_its_own_name_is_fine` | pass |
| S-151.4 | `test_S_151_4_use_template_fills_the_pm_and_replies_with_the_pages_botfield_names_and_saves_nothing` (server and markup; clicks not run in a browser) | pass |
| S-151.5 | `test_S_151_5_a_forbeast_token_without_the_comment_scopes_is_an_x_and_no_field_is_unknown`, `test_S_151_5_a_meta_permission_error_on_a_comment_send_in_the_last_24_hours_is_an_x` | pass |
| S-151.6 | `test_S_151_6_an_unchanged_comment_pm_is_not_asked_again` | pass |
| S-151.7 | `test_S_151_7_the_nightly_run_and_check_again_include_the_comment_checks` | pass |
| S-151.8 | `test_S_151_8_a_botcake_page_with_comment_columns_gets_an_info_line` | pass |

Done-when run: `phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode`.
- First build, on 52974859: 1531 tests before, 1549 after, 0 failures, 1 skipped.
- On `feat/151-comment-content-check` (on 3d5ffc6a): 1624 tests, 0 failures, 1 skipped, 75 PHPUnit deprecation notices (the same count as before).

## Moved onto 3d5ffc6a
The work was cherry-picked from `feat/150-comment-content-check` onto 3d5ffc6a, which already holds the live chatbot AI provider work.

Conflicts:
- `app/Services/PageSettings.php`: both specs extend the `SYSTEM_KEYS` line. Both sets are kept: `forbeast.permissions` plus the `chatbot.ai_*` keys. The new `keyLike()` rule stays as is.
- `qa/stories.md` and `TODO.md`: both specs appended at the end. Both are kept, the AI provider entries first.
- No other conflicts. `resources/views/chatbot/index.blade.php` merged on its own.

The AI provider chain (`ChatbotAiChain`, `ChatbotAiProviders`) routes only the bot's AI step. 3d5ffc6a does not change `ContentAi`, `PageContentCheck` or `config('services.openai.content_check_model')`. The Comment PM check therefore keeps the same `ContentAi` call and the same cost logging in `chatbot_content_answers`, like Prompt/Seq, and needed no change.

## Commits
On `feat/151-comment-content-check`, cherry-picked with their original subjects:
- `146b08e9` docs: spec and plan for the comment content check (spec 150)
- `fe1dc1dc` feat: the Check column checks comment texts' content and format, flags pages without comment permission, and offers a template (spec 150)
- `b31a105f` fix: comment check finds reply cues with Taglish prefixes, ignores a missing space after a period, and warns once per group of identical replies (spec 150)
- `dcd1f84a` docs: result, stories and accepted minors for the comment content check (spec 150)
- docs: renumber the comment content check to 151 after the move onto the live AI provider work (spec 151) (this commit)

On the old branch `feat/150-comment-content-check`, left as it was: `727441d6`, `04a5b3e9`, `60c44871`, `fb450c20`.

## Open points
- **Forbeast handoff 015** must add `permissions` (an array of scope names) to `GET /v1/pages/{id}`. Until then every Forbeast page with comment columns shows "Forbeast: permissions unknown". The next Test or nightly connections check stores the field once it is there.
- **Meta #10** also covers some messaging errors that are not about permissions, so such an error can show the permission X for up to 24 h. The codes were decided; the owner may want to narrow them.
- **Browser check** of "Use template" (Edit modal and cell editor) and the grey notes: not run.
- **Accepted minors** are in `TODO.md`:
  - one-word page names;
  - the 10-minute cache lag for new send errors;
  - the not-done count on flowless pages;
  - the double read;
  - the broad cue word list;
  - the untested connect/disconnect paths.

151: done — the Check column now checks the Comment PM's item, price and order cue (AI, cached) and the Comment Replies' format, flags missing comment permission, and offers "Use template".
