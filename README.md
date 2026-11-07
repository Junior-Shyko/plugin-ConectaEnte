# ConectaEnte (Mapas Culturais v5)

Plugin that sends the opportunities published by federated entities to CultBR (the Conecta Ente
platform), as required by the PNAB. Port of the v7 plugin to **v5** (PHP 7.2, Slim 2, Doctrine ORM 2.8,
Respect\Validation 1.x, PHPUnit 8).

Specification: this repository's issues. Porting plan: `ConectaEnte/plan.md` in the main repository.

## Activation

In `src/protected/application/conf/conf-base.d/plugins.php` (or in the installation's `config.d`):

```php
'ConectaEnte' => ['namespace' => 'ConectaEnte'],
```

## Configuration

Each key is read from `$app->config['conectaente.<key>']`, then from the environment variable
`CONECTAENTE_<KEY>`, then falls back to the default:

| Key | Variable | Default | Description |
|---|---|---|---|
| `mode` | `CONECTAENTE_MODE` | `dev` | `live` sends for real; any other value is simulated |
| `host` | `CONECTAENTE_HOST` | empty | Conecta Ente host (the contract does not declare `servers`) |
| `max_attempts` | `CONECTAENTE_MAX_ATTEMPTS` | `3` | Attempts per send, **counting the first one** (3 = one attempt plus up to two retries); minimum 1 |
| `retry_delay` | `CONECTAENTE_RETRY_DELAY` | `+15 seconds` | Interval between attempts (a `DateInterval` string; fixed, no backoff) |
| `par_ttl` | `CONECTAENTE_PAR_TTL` | `3600` | Seconds the PAR tree of each entity stays in the cache; minimum 60 |

> Warning: the default is **simulated**. Forgetting `CONECTAENTE_MODE=live` in production makes the
> plugin simulate silently.

## Structure

```
Plugin.php        _init() (hooks, job) and register() (controller, metadata)
Config.php        configuration reader
Controllers/ Entities/ Http/Transport/ Jobs/ Listeners/ Metadata/ Payload/
Repositories/ Services/ Vocabulary/ views/ layouts/ fixtures/ tests/
```

Autoloading is the core's (`namespace ConectaEnte` → `plugins/ConectaEnte/`); Composer is not used.

## Registration API (`/conecta-ente`)

Only `saasSuperAdmin` has access. Anonymous users are redirected to the login; any other user gets `403`.
Request bodies may be form-urlencoded or `application/json`.

| Action | Route | Fields |
|---|---|---|
| list | `GET /conecta-ente` or `GET /conecta-ente/list` | – |
| create | `POST /conecta-ente/create` | `name`, `seal_id`, `token` |
| verify token | `POST /conecta-ente/verifyToken` | `token` (verifies only, saves nothing; returns the suggested `name` and `cnpj`) |
| update | `PATCH /conecta-ente/update/<id>` | any of `name`, `seal_id`, `token` |
| remove | `DELETE /conecta-ente/remove/<id>` | – |

Responses carry `id`, `name`, `seal_id`, `cnpj`, `token_registered` and `token_last4`; the token itself
is never returned. Errors use `{"error": true, "data": ...}` with `400` (validation, rejected token),
`403`, `404`, `409` (seal already used by another entity, naming it) or `503` (CultBR unavailable or
`CONECTAENTE_HOST` missing in live mode).

## Admin screen

`saasSuperAdmin` sees an "Entes Federados" item in the panel menu, leading to `/painel/federative-entities`
(`views/panel/federative-entities.php`, assets in `assets/`). It lists the entities (name, seal name,
formatted CNPJ, creation date) and creates, edits and removes them through the API above; the token is
never shown, and a blank token field on edit keeps the current one. Other users get `403`. Outside
`CONECTAENTE_MODE=live` the screen shows a "simulated mode" banner.

## One entity per opportunity

An opportunity cannot hold the seals of two different federative entities, so the sending never has
to choose between tokens. Applying a second entity's seal is refused with the message naming both
entities; a seal that is not registered as an entity, and the same entity's seal again, pass. Pending
seal requests count as already applied. Only opportunities are checked (agents, spaces and projects
are not), and opportunities that already hold two entity seals are left alone.

Two layers enforce it (`Plugin::sealConflictMessage()`, rule in `Services/OpportunitySealGuard`):

- `Listeners/SingleEntityPerOpportunityListener`, a Doctrine `prePersist` listener, covers every code
  path that saves the relation and throws `BadRequest`. It is not the `save:before` entity hook: in v5
  only entities with `@ORM\HasLifecycleCallbacks` fire those, and `SealRelation` does not, so the hook
  would never run. The core does not turn `BadRequest` into a response.
- A `POST(opportunity.createSealRelation):before` hook, used by the seals screen of the opportunity,
  answers `400` with `{"error": true, "data": "<message>"}` (only for who may apply the seal).

Known limit: two administrators applying seals of different entities to the same opportunity at the
same instant can both pass the check, because each reads the database before the other's insert. The
check runs in `persist()`, before the `flush` transaction opens, so a row lock would not hold, and a
database constraint would mean changing `seal_relation`, a core table. Relations still waiting for a
`flush` in the same request are seen.

## PAR tree per federative entity (#52)

The PAR tree (exercise → goal → action → activity) comes from `GET /api/v1/par-information` on CultBR, with
the entity's token in the `token` header and pagination by `skip`/`limit`. The response is a list of
entities (`data`), and the tree used is the one whose `cnpj` is the entity's CNPJ; the CNPJs of the others
are logged, never used.

- **A page request never calls the API.** `ConectaEnte\Services\ParInformationService::cached()` reads
  only the cache; with no entry it returns `pending`, and the endpoint enqueues a fetch for the entity.
- **Cache per entity** (`conectaente:par-information:<sha1>`, `ttl` = `par_ttl`) in the `mscache` provider,
  not `cache`: its namespace is fixed, so the jobs (which run outside a subsite) and the pages read the same
  entry. The key hashes the mode and host, the entity, its CNPJ and its token, so another environment or a
  token that was replaced never reads an old entry. Definitive answers are cached (a tree, or "no tree for
  this CNPJ"). A failed call is kept for 5 minutes under `:failure`, so the page shows `unavailable` and the
  next fetch tries again.
- **Jobs.** `conectaente-par-fetch` fetches and caches, one job per request (its id carries a unique
  `requestId`, so a request made while another fetch runs is not dropped). `conectaente-par-sync` enqueues a
  fetch per entity, spaced by one second, and repeats through the core's recurrence every `par_ttl` seconds
  (`ITERATIONS` times). Both always return `true`; a failure is logged and does not stop the others.
- **Scheduling.** Saving a token (create, or update that verifies a token, accepted) forgets the cached tree,
  enqueues the fetch now and makes sure the sync exists. A rejected token schedules nothing. The page request
  also makes sure the sync exists, so installations that already had entities get it on the first visit. Both
  "make sure" steps use a flag of one `par_ttl` in `mscache`, so the sync is scheduled once per period and a
  new token does not postpone it. A page request queues at most one fetch per entity per minute.
- **Endpoint.** `GET /conecta-ente/parInformation/<opportunityId>` answers the tree of the opportunity's
  sealed entity, from the cache, in the shape of the `v7` route: `available`, `simulated`, `reason`,
  `message` and `exercicios` (`id`, `ano`, `metas` → `acoes` → `atividades`). `reason` is `pending`,
  `notFound` or `unavailable` when there is no tree; `pending` and `unavailable` also queue a fetch (rate
  limited above). The UI that picks from it is #53.
- **Simulated mode** reads `fixtures/par-information.json`, which has the real API shape.

Not verified here: the production contract still lists this path as absent (the #52 note), so it must be
confirmed before relying on it; the core's recurrence of `conectaente-par-sync` and the route
`parInformation` (argument and action name) need a run of the worker and the app.

## CultBR fields on the opportunity

CultBR requires data that Mapas v5 core does not have. The plugin registers it itself
(`Metadata/CultBrMetadata`, called from `Plugin::register()`; the vocabularies are the plugin's own, so
registration order does not matter):

| key | type | vocabulary |
| --- | --- | --- |
| `conectaente_executionType` | select | `ExecutionType` |
| `conectaente_segments`, `conectaente_culturalStages`, `conectaente_thematicAgendas`, `conectaente_priorityTerritories` | multiselect | `Segment`, `CulturalStage`, `ThematicAgenda`, `PriorityTerritory` |
| `conectaente_segmentsOther`, `conectaente_culturalStagesOther`, `conectaente_thematicAgendasOther` | string | the "specify" detail of the "Other" option |
| `conectaente_parExercicioId`, `conectaente_parMetaId`, `conectaente_parAcaoId`, `conectaente_parAtividadeId` | string | the PAR ids (digits only), the hierarchy exercise → goal → action → activity; all four are required to publish (`PublicationRequirements::parErrors()`) and go out as integers in `id_exercicio`, `id_meta`, `id_acao`, `id_atividade` |

The four PAR ids are typed in the tab by hand for now (`type: number` in the editable field); the cascade
that picks them from the PAR tree comes with #53. Only the chain order and presence are checked; checking
that the four ids form a valid path of the tree is #52.

Keys, labels and options follow the plugin's `develop` branch (v7), so the stored data is the same in both
versions. Each multiselect starts with the synthetic options `TargetingOption::NOT_TARGETED`
(`__edital_nao_se_direciona__`, text per field via `TargetingField::notTargetedText()`) and, for segments
only, `ALL_OPTIONS` (`__todas_opcoes__`, "Todas as opções"). `CultBrMetadata::toText($value, $key)` turns
those stored values into their text for the payload.

- The core stores a multiselect as one text separated by `;` (`CultBrMetadata::SEPARATOR`). The stored
  value is the fixed Portuguese text CultBR expects (or a synthetic value above); the label shown is the
  translated one. `CultBrMetadata::toText()` turns the stored text into the `"a, b"` string of the payload.
- `CultBrMetadata::missingSpecifications()` defines the rule "the Other option needs its detail". Blocking
  publication is issue #46.
- The tab **Ente Federado / CultBR** (`layouts/parts/conecta-ente/opportunity-cultbr.php`) is added to the
  opportunity edit page by `Plugin::hookCultBrTab()` only when the opportunity is *sealed*
  (`Services/SealedOpportunity`: an enabled relation to the seal of a registered entity). Phases are not
  sealed. Hooks: `template(opportunity.edit.tabs):end` and `template(opportunity.edit.tabs-content):end`.
- The core does not check a `select` value against its options on save; enforcing it is up to the
  publication check (#46).
### JSON blocks

Four more metadata hold JSON, with the same shape as the `develop` branch (v7):

| key | shape |
| --- | --- |
| `conectaente_fundingSources` | `{houveUtilizacao: "sim"\|"nao", recursosProprios, conveniosParcerias, emendasParlamentares, remanescentesCiclo1, outrasFontes: [{nomeFonte, valor}]}`; a source is `null` when unused and a number (0 included) when used |
| `conectaente_quotaReservation` | `[{label, vagas, valorDestinado, naoAplicavel}]`: the 3 legal quotas, any extras, and "Ampla concorrência" last (it gets the vacancies left over) |
| `conectaente_registrationChannels` | `{previstasNoEdital: "sim"\|"nao", formas: [{tipo, descricao}]}` |
| `conectaente_affirmativeActions` | `{opcoes: [...], <option with groups>: [...], outra_legislacao_descricao}` |

- The Save button of the edit page sends each metadata as a text in a form `PUT`, and the core `json`
  serializer would encode that text again. `Metadata/JsonBlock` stores a valid JSON text as it came
  (normalized) and encodes lists/objects; anything else clears the block. `JsonBlock::unserialize` returns
  an associative array.
- `Services/FundingSourceName` trims, strips HTML and cuts the free source names at 255 characters when
  the block is saved, whatever the save path.
- The screen (`assets/js/conecta-ente/opportunity-blocks.js`, jQuery) is configured by
  `Metadata/BlockUiConfig` (vocabularies and texts come from PHP, never repeated in JS). Each block keeps
  its state in an object mirrored into a hidden `.js-include-editable` field, the same mechanism the core
  uses for date-time fields. Opening the tab writes nothing: only blocks already stored or touched are sent.
  The scripts are enqueued by a `GET(opportunity.edit):before` hook, because they are printed in `<head>`.
- Total of vacancies: in Mapas v5 it is the core metadata `numberVacancies` (the v7 / issue name `vacancies`
  does not exist here). The core only shows it in "Configuração de Cotas" (SECULT seal + technical
  evaluation). When that field is not on the page, the quota block renders its own "Total de vagas" input
  bound to `numberVacancies`; the open competition row is always total minus the other rows.
- Detailed validation of the blocks (a source without value, quota sums, e-mail format...) is issue #46.
  Vocabulary note: `RegistrationChannel` (was `RegistrationForm`) and `ProponentType` keep the 3 extra
  contract types the `develop` branch does not have.

## Language convention

Everything in the plugin is in English: identifiers, database columns, request/response fields and
documentation. Only the texts shown to users (wrapped in `i::__()`) stay in Portuguese, and files in
`fixtures/` mirror the external CultBR contract and keep its field names as they are.

## Code restrictions (PHP 7.2)

No `enum`, `readonly`, `match`, named arguments, typed properties, `?->`, `fn` with a return type
or first-class callables. Check with:

```bash
docker run --rm -v "$PWD":/app -w /app php:7.2-cli sh -c \
  'find . -name "*.php" -not -path "./vendor/*" -print0 | xargs -0 -n1 php -l'
```

## Sending to CultBR (queue and retry)

A send never happens inside the user's request. Publishing, editing a published opportunity or sealing an
already published one only **enqueues** a job (`Jobs/SendOpportunityJob`, slug `conectaente-send-opportunity`);
the call to CultBR runs later, in the jobs worker. `Plugin::scheduleSend()` always enqueues the **root**
opportunity (editing a phase resends the root), and a failure while scheduling is logged and never breaks the
save.

- **One job per opportunity.** The job id is `conectaente-send:<opportunity id>` and the plugin uses
  `enqueueOrReplaceJob`, so N saves in a row become a single send.
- **Eligibility is checked again when the job runs**, since the state may have changed since it was enqueued.
  If the opportunity or the entity is gone, or it stopped being eligible, the outcome is `abandoned`.
- **Mode `dev` never sends.** The payload is built and validated, nothing leaves the server, and the outcome is
  `simulated`, never `success`.

### Outcomes and retry

`Services/RetryPolicy` classifies the answer by HTTP status range:

| Answer | Outcome |
|---|---|
| `200` with a JSON body | `success` |
| connection failure, `5xx`, or `200` without JSON (proxy or load balancer page) | `pending` (retried) until `max_attempts`, then `error` |
| any other status, such as `400`, `401`, `403`, `422` | `rejected`, **not** retried (a client answer does not change by itself) |
| opportunity or entity gone, or no longer eligible | `abandoned` |
| mode `dev` | `simulated` |

`408` and `429` are also `rejected`: the rule is by range, not per code. The reason is the `detail` sent by
CultBR (a list of `field: message` on `422`) or, when the body is not JSON, `HTTP <status>`.

The job always returns `true` and wraps everything in `try/catch (\Throwable)`: a job that throws or returns
`false` stays stuck in "processing" forever in the core. An unexpected failure ends as `error`. Retries are the
core's own recurrence for the same job (`iterations` = `max_attempts`, `intervalString` = `retry_delay`); the
attempt counter is the core's `iterationsCount`, not a field of the job `data`.

On `success` the plugin stores `id_par_edital` and the `x-request-id` of the response in the opportunity
metadata (`conectaente_*`), the key to match a send with CultBR's log. A failure while saving them is logged
with both ids and does not turn an accepted send into an error. There is no per-attempt history yet.

Each outcome is written to the application log as `[ConectaEnte] envio da oportunidade <id>: <outcome>`,
with the reason when there is one. A `rejected` or `error` reason shows up **only there**.

### Jobs worker

The send only happens if the core's jobs worker is running. In the Docker images, `compose/entrypoint.sh`
starts `/jobs-cron.sh` in the background; it loops running `scripts/execute-job.sh`, which runs
`src/protected/tools/execute-job.php` → `App::executeJob()`. Tunable by environment variable:

| Variable | Effect |
|---|---|
| `NUM_PROCESSES` | Maximum parallel workers (default: number of CPU cores) |
| `JOBS_INTERVAL` | Seconds between worker runs (default: 1) |

Without a worker (or outside Docker) nothing is sent: jobs pile up in the `job` table. To run one pass by hand:

```bash
scripts/execute-job.sh
```

## Tests

Unit tests (PHPUnit 8, PHP 7.2 in a container, no database or HTTP). `tests/run.sh` resolves its paths
itself, so it works from any directory; only the script path changes. From the main repository root
(`v5mapas`):

```bash
src/protected/application/plugins/ConectaEnte/tests/run.sh                         # whole suite
src/protected/application/plugins/ConectaEnte/tests/run.sh --filter testEditalType # filter by name
```

From this plugin's root:

```bash
tests/run.sh
tests/run.sh --filter testEditalType
```

Pass a test file after the options to run only it: `tests/run.sh --filter Smoke` or `tests/run.sh tests/src/SmokeTest.php`
(the path is relative to the plugin root).

Layout:

- `tests/phpunit.xml`: suite (`tests/src`, files ending in `Test.php`), bootstrap and the test environment.
- `tests/bootstrap.php`: loads the composer autoload and the `ConectaEnte\` and `Tests\ConectaEnte\` namespaces.
- `tests/src` (namespace `Tests\ConectaEnte`): the tests. `tests/src/Doubles` has `FakeTransport` (injected into
  `Http\Client`, so no request leaves the container) and `FakeOpportunity`.
- `SmokeTest` proves the plugin is wired without booting the `App`: it checks that `Plugin` extends the core plugin
  and that the source registers the expected hooks and the send job.

The environment is declared in `phpunit.xml`: `CONECTAENTE_MODE=dev` (sending is simulated), `CONECTAENTE_RETRY_DELAY=now`
and an empty `CONECTAENTE_HOST`. Booting the real `App` (database and plugin loaded) is the job of the core suite, not of
this one.

Always point PHPUnit at this plugin's suite. Running the core suite (`tests/` at the repository root) is a separate
command and needs PostgreSQL (`dev-scripts/run-tests.sh`).

## Publication requirements (#46)

A sealed root opportunity (a federative entity seal, no `parent`) cannot be published while the data CultBR
requires is missing. Drafts, opportunities without an entity seal and phases are not checked, and
`saasSuperAdmin` is not exempt.

- `Services/PublicationRequirements::missingFields($opportunity)` returns `field key => [messages]` (empty when
  it can be published). Every message names the field in PT-BR. It checks `numberVacancies`, the `rules` file,
  the description, both registration dates, the plugin metadata (including the two added here:
  `conectaente_totalResource` and `conectaente_proponentTypes`, which Mapas v5 does not have) and the four JSON
  blocks (funding answered, quota sums, registration channels with valid e-mail, affirmative actions with the
  140 characters limit). Minimum quota percentages are out of scope.
- The check runs on `entity(Opportunity).publish:before`. Unlike `save:before` (Doctrine `preUpdate`, inside
  `flush()`), stopping there does not close the EntityManager, and the entity already holds what the `PUT`
  sent. `PUT`/`PATCH` with `status` and the publish route all end in `Entity::publish()`.
- The answer is `HTTP 400` with `{"error": true, "data": {key: [messages]}}`, the shape the Mapas front maps to
  the fields (keys that match a `data-edit` are marked, the others are shown as messages). The opportunity stays
  in draft.
- The tab marks the required fields with `*`.

