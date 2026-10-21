# Result 081: on /chatbot/pages, each Seq N sits right after its Seq Img N

Branch `fix/081-seq-pairs`, cut from `main` at `d4e37a67`. Nothing was pushed.

## Commits

| Commit | What |
|---|---|
| `53a90583` | docs: the brief (verbatim) |
| `1e500f6b` | fix: the column order, and the test |
| (this commit) | docs: result, stories |

## What changed

`App\Services\ChatbotFields::defaults()` now builds the sequence columns in pairs after PICTURE: Seq Img 1, Seq 1, Seq Img 2, Seq 2, …, Seq Img 20, Seq 20.
- Keys and labels are unchanged, so no data changed and nothing was migrated.
- Columns added with "+ Column", and keys found only in page settings, still come after the defaults.

The same order shows wherever the columns are listed in order: the /chatbot/pages table and the editor's variable list. `ChatbotPage::botfields()` and the inbox panel look columns up by key, so the order doesn't affect them.

## Tests

- New `test_S_081_each_seq_sits_right_after_its_seq_img` (in `ChatbotPagesTest`):
  - the 40 columns after PICTURE are `seq_img_1, seq_1, …, seq_img_20, seq_20`, followed by an added column "Promo";
  - the labels start "Seq Img 1", "Seq 1", "Seq Img 2".
- Red run, before the fix: `Failed asserting that two arrays are identical.` (Seq 1–20 came first).
- `vendor/bin/phpunit tests/Feature/Chatblast`: 410 tests, 2732 assertions, all pass (PHPUnit deprecations only). I used `vendor/bin/phpunit` rather than `php artisan test`: this worktree has no environment file, and one must not be created.

## Story changes

New section S-081 (S-081.1).
