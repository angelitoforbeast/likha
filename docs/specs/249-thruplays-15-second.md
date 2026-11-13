# Spec 249: the ads upload accepts Meta's "15-second ThruPlays" column

Type: bug, cause found. Weight: bounded. Risk tier: low (one header alias in the upload).

## Why
Since spec 246, `app/Jobs/ProcessAdsManagerReportsUpload.php` requires three video columns (`VIDEO_HEADERS`, about line 405). For ThruPlays it accepts only the normalized headers `thruplays` and `thruplay`. Meta's Ads Manager export now names the column **"15-second ThruPlays"**, and the export also has a separate "6-second ThruPlays" column. So every upload today fails with "Missing required column(s): ThruPlays" and 0 rows are inserted. "15-second ThruPlays" is the same metric the API sync stores in `video_thruplays` (`video_thruplay_watched_actions`: 15 seconds, or to the end of a shorter video).

## Stories
- S1 (P1). A file with a "15-second ThruPlays" header (any case or spacing, as `buildHeaderIndex` normalizes) uploads. Its value goes into `video_thruplays`.
- S2. A file with the old "ThruPlays" header still works as today.
- S3. Negative: a file with only "6-second ThruPlays" (and no 15-second or plain ThruPlays) is still refused with "Missing required column(s): ThruPlays". The 6-second value is never stored as ThruPlays.
- S4. Edge: when a file has both "ThruPlays" and "15-second ThruPlays", the first alias in `accepts` wins. Put the current Meta name, '15-second thruplays', first, as the docblock says ("the current name first, then the older names").
- The missing-column message keeps the label "ThruPlays".

## Constraints
- Tests first, in `tests/Feature/MetaAds/AdsUploadVideoColumnsTest.php` (follow its existing style). Cover S1–S4.
- Change only the `accepts` list, plus a short comment with the reason: Meta renamed the export column and also exports a 6-second one.
- No migration. No other files except the spec, the result and the test.
- Commit messages: Conventional Commits, no Co-Authored-By, no Claude-Session, no "Generated with" lines, no people's names. The first commit saves this spec text as `docs/specs/249-thruplays-15-second.md`.
- Worktree: `C:/mira/mira/projects/likha-wt247`. It is clean on branch fix/248-retrieve2-manila-dates at the live commit aa554883. Create the branch `fix/249-thruplays-15-second` from aa554883 there. Never touch the main checkout `C:/mira/mira/projects/likha` or the other worktrees.
- PHP is not on the Git Bash PATH. Use PowerShell with `C:\Users\Forbeast\.config\herd\bin\php.bat`. vendor/ is a junction, so run tests with the shim bootstrap:
  `php.bat vendor/bin/phpunit --bootstrap "C:/Users/Forbeast/AppData/Local/Temp/claude/2e760ef270dbe960/07319e29-2afc-45d1-9b8d-9083b1ed643e/scratchpad/wt247-bootstrap.php" tests/Feature/MetaAds`
  Run the full suite at the base first, then at the head. The known failures are ExampleTest and ItemTableFitScriptTest.
- No .env, server or deploy actions. Don't push to any `root@` remote. Push the branch to `origin` only.

## Done when
1. The new tests pass, and `tests/Feature/MetaAds` passes.
2. The full suite adds no new failures compared with aa554883.
3. The branch is pushed to origin.
4. `docs/specs/249-thruplays-15-second.result.md` is committed, with: what changed, the test names, the suite counts at base and head, and the head commit.
