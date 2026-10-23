# Amendment 1 to brief 128 — storage in page_settings, no migration

| Change | Why |
|---|---|
| No migration, no new columns. Analyst values are stored in the existing `page_settings` table (Page / Provider / Key / Value, one active row per page+provider+key, history as replaced rows): provider `analyst`, key `rts_pct:<canonical item name>` (value like `33` or `32.5`) and `rts_reason:<canonical item name>` (at most 500 chars). Written through `PageSettings::set(..., system: true)`; validation allows provider `analyst` with keys starting `rts_pct:` / `rts_reason:` only, in system mode. `analyst_rts_at` is the created_at of the active `rts_pct:` row; the previous value is the latest replaced row of that key. | No new columns or tables when Page / Key / Value fits. |
| Provider `analyst` rows are hidden from the Page settings grid and the chatbot "+ Page" list. | They are not page settings to edit by hand. |
| No `page_item_settings_log` write for analyst values; page_settings history is the log. Everything else in the brief stays. | One source of truth. |
