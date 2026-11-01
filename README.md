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

## CultBR fields on the opportunity

CultBR requires data that Mapas v5 core does not have. The plugin registers it itself
(`Metadata/CultBrMetadata`, called from `Plugin::register()`; the vocabularies are the plugin's own, so
registration order does not matter):

| key | type | vocabulary |
| --- | --- | --- |
| `conectaente_executionType` | select | `ExecutionType` |
| `conectaente_segments`, `conectaente_culturalStages`, `conectaente_thematicAgendas`, `conectaente_priorityTerritories` | multiselect | `Segment`, `CulturalStage`, `ThematicAgenda`, `PriorityTerritory` |
| `conectaente_segmentsOther`, `conectaente_culturalStagesOther`, `conectaente_thematicAgendasOther` | string | the "specify" detail of the "Other" option |

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

Tests live in `tests/src` (namespace `Tests\ConectaEnte`); `tests/bootstrap.php` loads the autoload.
