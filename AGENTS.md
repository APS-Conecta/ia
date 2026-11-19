# AGENTS.md — «IA»

Nextcloud 34 app for APS Conecta Gestión, next to Nextcloud's Assistant: it reviews documents and writes Bulletins
with the suite's local Model. The spec is APS-Conecta/gestion-aio#162; its tickets live in this repo.

## Working rules

- **Names:** code is English, but a domain term keeps the meaning and spelling `CONTEXT.md` gives it (Review,
  Bulletin, Edition, Reading, «Para ti»). Never rename a table, column, route, controller action (an OpenAPI
  operation id) or API field once it ships.
- **One path to the Model:** «IA» reaches it only through TaskProcessing and the standard connector, never by calling
  the model server (gestion-aio ADR 0003). Never `runTask` inside a web request.
- **Tests at the one seam** (gestion-aio#162, Testing Decisions): `tests/seam/` reaches the app only through its OCS
  API and occ, against the throwaway instance with the real Model. Assert only what doesn't depend on the
  Model's wording. `tests/unit/` holds the static gates, nothing else.
- **Gate before opening a PR**, what `ci.yml` and `release.yml` run: `npm ci`; `npm run lint-js`; `make bundles`;
  `make test`; `make deps && make instance-up && make test-seam`; `make openapi` with no drift in `openapi.json`;
  then `make instance-clean`. A step you could not run is named in the PR, never reported as passed.
- **`src/` changed → `npm run build`**: `js/` is build output, ignored. Never commit `js/`, `*.js.map` or
  `node_modules/`.
- **Never edit by hand** `package-lock.json`, `composer.lock`, the dependency lists in `package.json` and
  `composer.json` outside a ticket that names the dependency, or `openapi.json` (generated from `ApiController` by
  `make openapi`; MetadataTest and CI fail on drift).
- **Strings:** what a person reads (templates, pages, messages) is Spanish, written as literals, in tú as the mockup
  writes it; this app uses no `t()` / `$l->t()`, like its siblings. Code, comments, commits and PR text are English.
- **Theme only:** `src/` reads Nextcloud's CSS variables and never writes a colour or a font; the suite's theme gives
  the APS palette, Fraunces and Nunito Sans (`eslint.config.mjs` enforces it).
- **One ticket per PR**, and it contains every change its description claims.
- **The Model pin** (`MODEL`, `MODEL_DIGEST` in the Makefile) and the Ollama image in `compose.dev.yaml` follow the
  suite's pins in gestion-aio; move them only when the suite moves.

## Agent skills

### Issue tracker

GitHub Issues on `APS-Conecta/ia`, via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Canonical vocabulary, unmodified: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. All
five exist on the repo. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: [`CONTEXT.md`](CONTEXT.md) for the vocabulary, [`docs/adr/`](docs/adr/) for the decisions. See
`docs/agents/domain.md`.
