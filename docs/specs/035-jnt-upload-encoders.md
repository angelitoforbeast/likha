# Brief 035: Data Encoders may view /jnt_upload (no uploading), and see it in the menu

## Why

The owner wants the Data Encoders to see `/jnt_upload`, view only: they may not upload. Today `JntUploadController::checkAccess()` (about line 20) allows only CEO, Marketing - OIC and Marketing; every other role gets a deliberate 404, and the menu link is hidden for them too.

## Do

- Split access in the controller: a view check that also allows the roles `Data Encoder` and `Data Encoder - OIC` (same normalisation as the existing checks: trimmed, case-insensitive, any dash between "Data Encoder" and "OIC") for the read routes (GET /jnt_upload, the status and history endpoints); and the existing check, unchanged, for every route that writes or starts work (POST /jnt_upload `store`, and any other non-GET route of this controller). A Data Encoder calling a write route gets 403 (not 404). Other roles still get 404 everywhere.
- In the page, hide the upload form and every upload/start button for viewers who may not upload (decide from the same role check, passed to the view), and show a short line instead, e.g. "View only".
- Menu: find how the `/jnt_upload` link's visibility is decided (the layout guard the controller comment mentions, and/or the `nav_link_role_visibility` rows behind `NavLink::visibleFor()`). Make the link visible to those two roles the same way it is for Marketing. If visibility lives in database rows, add an idempotent migration that inserts the missing rows (no duplicates on rerun, no other rows touched), and say so in the result.
- Do not change `/jnt_upload_v2` or any other page.

## Tests

Data Encoder and Data Encoder - OIC get 200 on GET /jnt_upload, status and history, see no upload form, and get 403 on POST /jnt_upload (nothing stored, no job dispatched); CEO, Marketing, Marketing - OIC unchanged (form shown, upload allowed); another role (e.g. none or an unknown role) still 404; the menu shows the link for a Data Encoder. Red first where natural. The whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Final message

Commits; where the menu visibility lives and what you changed; the migration if any; test lines and suite result.
