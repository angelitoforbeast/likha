# AI Boardroom (`/boardroom`)

Mga role (CEO/Moderator, CTO, COO, Reviewer) na nag-uusap tungkol sa isang objective. Bawat sagot ay
**hiwalay na API call** ng role na iyon, gamit ang **sarili nitong API key, model, at settings**.
CEO lang ang may access.

Walang browser control, shell execution, production deployment, o awtomatikong pag-message sa customer.
Text lang ang ginagawa ng mga role.

---

## 1. Setup

```bash
php artisan migrate --force
php artisan queue:restart
```

- Ang migration ay gumagawa ng 13 bagong table (lahat may prefix na `br_`) at naglalagay ng seed:
  4 na role, ang group na "Core Boardroom", at ang model-capability registry. Walang ginagalaw na existing table.
- **Kailangan ng tumatakbong queue worker** sa queue na `default` (o sa `BOARDROOM_QUEUE`). Kung walang worker,
  mananatiling "Nakapila" ang mga turn. Ang `queue:restart` ay para mabasa ng worker ang bagong code.
- Opsyonal pero inirerekomenda, **bago** mag-save ng kahit anong API key:

  ```bash
  php artisan boardroom:key
  ```

  Ilagay ang lalabas na linya sa `.env` ng server (`BOARDROOM_ENCRYPTION_KEY=...`). Kapag blangko, `APP_KEY` ang gamit.
  Alinman ang gamitin, nasa `.env` ito — hiwalay sa database. **Kapag pinalitan ang key na ito pagkatapos,
  hindi na mababasa ang mga naka-save na API key** at kailangang i-enter ulit.
- Nav link: idagdag ang `/boardroom` sa `/owner/nav-settings` (para sa role na CEO).

## 2. Paggamit

1. `/boardroom/agents` — sa bawat role: piliin ang provider at model, ilagay ang API key, i-Save, tapos **Test Connection**.
   Ang Test Connection ay maliit pero totoong request — maaaring kumonsumo ng kaunting credits.
2. `/boardroom` — gumawa ng project, tapos **+ Meeting**: title, objective, constraints, mga role na kasali, at mga limit.
3. **Create & start**. Lalabas ang usapan habang tumatakbo (nagre-refresh kada ~2.5 segundo).
4. Mag-type ng instruction anumang oras. `@CTO` (o ibang handle) = direktang tanong sa role na iyon (isang model call).
5. **Pause / Resume / Stop / Retry** sa itaas. Ang mga lumang meeting ay nasa kaliwa.

### Daloy ng meeting

| Phase | Sino | Ano |
|---|---|---|
| A. Brief | CEO | Nililinaw ang objective, saklaw, at constraints |
| B. Proposals | CTO, COO | Magkahiwalay; iisang brief snapshot; hindi nakikita ang isa't isa |
| C. Review | Reviewer | Naglilista ng mga isyu (may severity at target) |
| D. Discussion | CEO → isang role | CEO ang pumipili kung sino ang sasagot sa bawat isyu |
| E. Revision | Contributors | Binabago ang proposal; pwedeng hindi sumang-ayon |
| F. Final | CEO | Approach, dahilan, alternatibo, hindi pa resolved, next actions + owner, desisyong kailangan ng approval |

Hindi pinipilit ang consensus. Pwedeng magtapos sa "kailangan ng sagot mo" o "blocked".

### Mga limit (ipinapatupad ng backend)

- Hanggang **3** review/revision cycle at **16** model call kada meeting (pwedeng babaan kada meeting).
- Binibilang ang **lahat** ng call: routing, review, repair, final, at bawat retry na naipadala.
- Laging may **isang call na nakatabi** para sa final recommendation.
- Nirereserba ang budget **bago** mag-schedule ng sabay-sabay na proposals.
- Opsyonal: limit sa kabuuang output tokens at spending limit (USD). Ang spending limit ay gumagamit ng
  worst-case na tantiya (max output tokens sa presyo ng registry). Hindi ito tinatanggap kung may role na
  ang model ay walang alam na presyo.
- Kapag naabot ang limit: diretso sa final kung kaya pa; kung hindi, hihinto at ililista ang hindi pa resolved.

## 3. Arkitektura

```
app/Boardroom/
  Providers/       ProviderAdapter (interface) · OpenAIAdapter · AnthropicAdapter · DeepSeekAdapter · AdapterFactory
  Capabilities/    CapabilityRegistry — anong settings ang pwedeng ipadala kada (provider, model)
  Orchestrator/    MeetingOrchestrator · TurnRunner · ContextBuilder · OutputInterpreter · Budget · Rules · Prompts
  Support/         Secrets (encrypt / mask / redact) · Presenter (tanging daan ng data papunta sa browser)
app/Jobs/Boardroom/RunTurnJob.php          isang job = isang turn = isang model call
app/Http/Controllers/Boardroom/            BoardroomController · AgentController
app/Models/Boardroom/                      11 models (br_* tables)
resources/views/boardroom/                 index (chat) · agents (settings)
```

- **Orchestrator sa backend.** Ito ang nagpapasya kung sino ang susunod, bumubuo ng context, kumukuha ng credential,
  nagse-save ng output at usage, at nagpapatupad ng limit. Ang CEO ay **nagmumungkahi** lang ng susunod na hakbang
  (structured JSON); ang mga ID at transition ay vina-validate laban sa database.
- **Pagkakakilanlan ng nagsalita** ay galing sa server (`turn.agent_id`), hindi sa sinabi ng model.
- **Dedupe.** Bawat turn ay may unique na `(meeting_id, dedupe_key)`; atomic ang pag-claim ng job
  (`queued → generating`); isang message lang kada turn. Ang refresh, dobleng job, retry, at resume ay hindi nagdodoble.
- **Context.** Kinukuha lang gamit ang `meeting_id` ng turn. Magkakahiwalay ang: approved company knowledge,
  approved decisions ng project, history ng meeting, at instructions ng role. Text lang ang isinasama —
  walang reasoning object ng provider na sine-save o ipinapasa.
- **Walang fallback.** Walang tahimik na paglipat sa ibang model, provider, o key. Kapag nabigo ang isang turn,
  hihinto ang meeting at ipapakita ang dahilan; ikaw ang mag-aayos at magre-Retry.
- **Snapshot.** Ang non-secret na config ng bawat role ay kinukunan ng snapshot kapag nagsimula ang meeting.
  Sa Retry, nire-refresh ang config ng role na nabigo (may paalala sa chat).

### Seguridad

- Lahat ng provider call ay server-side. Ang API key ay nasa request header lang.
- Naka-encrypt ang key sa `br_credentials.secret_encrypted`; ang encryption key ay nasa `.env`.
- Write-only ang key sa UI: naka-mask lang ang ipinapakita (huling 4 na character). Walang endpoint na nagbabalik ng key.
- Walang key sa prompt, chat history, log, queue payload, o Git. Walang ginagamit na browser storage.
- Ang error mula sa provider ay nire-redact bago i-save o i-log.
- CEO lang ang may access (`EnsureCeo`); ang project at meeting ay sa user na gumawa lang.
- Ang output ng model ay ipinapakita bilang text (`x-text`), hindi bilang markup.

## 4. Tests

```bash
php artisan test tests/Feature/Boardroom
```

Lahat ng test dito ay **MOCKED** (`Http::fake`) maliban sa `BoardroomLiveSmokeTest`, na naka-skip maliban kung buksan:

```bash
BOARDROOM_LIVE_TEST=1 BOARDROOM_LIVE_OPENAI_KEY=<key> php artisan test --filter=BoardroomLiveSmokeTest
```

Patakbuhin ito sa sarili mong terminal. Huwag ilagay ang key sa kahit anong file. Ang provider na walang key ay
naka-SKIP — hindi "passed".

| # | Kinakailangan | Test |
|---|---|---|
| 1 | Sariling credential, model, settings kada role | `RoleSettingsTest::test_each_role_uses_its_own_credential_model_and_settings` |
| 2 | Ang pag-edit kay CTO ay hindi gumagalaw kay CEO | `RoleSettingsTest::test_editing_cto_does_not_change_ceo` |
| 3 | Hindi nakakarating sa provider ang unsupported na parameter | `RoleSettingsTest::test_unsupported_parameters_*` |
| 4 | Tamang recipient ang napupuntahan ng message | `MeetingFlowTest::test_messages_go_to_the_correct_recipient`, `test_invalid_routing_ids_*` |
| 5 | Iisang brief snapshot ang gamit ng proposals | `MeetingFlowTest::test_independent_proposals_use_the_same_brief_snapshot` |
| 6 | Hindi nagbabahagi ng context ang mga room | `MeetingFlowTest::test_rooms_do_not_share_context` |
| 7 | Pinipigilan ng stop at limit ang scheduling | `MeetingFlowTest::test_stop_and_pause_*`, `test_call_limit_*`, `test_review_revision_cycles_are_capped` |
| 8 | Walang dobleng turn sa retry at resume | `MeetingFlowTest::test_retries_and_resume_do_not_duplicate_turns` |
| 9 | Walang secret sa log at API response | `SecurityTest::test_secrets_are_excluded_from_logs_and_api_responses` |
| 10 | Natatapos ang buong meeting | `MeetingFlowTest::test_full_meeting_completes_with_mocked_providers` |

## 5. Mga integration na HINDI PA napapatunayan sa totoong provider

Ang mga adapter ay isinulat ayon sa opisyal na docs (binasa noong 2026-09-28) at nasubok lang laban sa mga
**mocked** na sagot. Wala pang totoong request na naipadala ng Boardroom sa kahit anong provider.

| Provider | Endpoint | Estado |
|---|---|---|
| OpenAI | `POST /v1/responses` | Hindi pa nasusubok nang live mula sa Boardroom. Ginagamit na ng ibang bahagi ng app ang parehong API. |
| Anthropic | `POST /v1/messages` | Hindi pa nasusubok nang live. |
| DeepSeek | `POST /chat/completions` | Hindi pa nasusubok nang live. |

Mga partikular na dapat patunayan sa unang Test Connection:

- **OpenAI** — `text.format` na `json_schema` (strict) para sa review, routing, at final.
- **OpenAI `gpt-5.2`** — nasa registry pero **unverified**: walang effort o schema na ipinapadala hangga't hindi
  ito minamarkahang verified (may doc source at petsa).
- **Anthropic** — `output_config.effort`, `output_config.format`, at `thinking` sa bawat model.
  Raw HTTP ang gamit (Laravel `Http`), hindi ang opisyal na PHP SDK, para walang bagong composer dependency
  at iisa ang paraan ng pag-test sa tatlong provider. Walang streaming, kaya panatilihing katamtaman ang max output tokens.
- **DeepSeek** — endpoint na `POST https://api.deepseek.com/chat/completions`. Magkaiba ang dalawang doc page sa
  pwesto ng `reasoning_effort`; top-level ang ipinapadala. Kapag tinanggihan (400), lalabas ito bilang
  `unsupported_parameter` at walang awtomatikong pag-ulit na may ibang hugis.
- **Presyo** — galing sa docs noong 2026-09-28; tantiya lang ang gastos sa UI. Ang aktwal na singil ay nasa dashboard
  ng provider, kada API key.

Kapag nagtagumpay ang isang totoong request, minamarkahan ang model sa registry ng petsa ("Live").

## 6. Hindi kasama sa MVP na ito

- Streaming ng sagot (kumpleto na ang message paglabas).
- Markdown rendering (plain text na may label badges).
- Higit sa isang reviewer o moderator kada meeting.
- Web search o anumang tool para sa mga role.
