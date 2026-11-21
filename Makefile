# Local dev and test for «IA»: Estadística's harness, cloned (README, «Desarrollo»).
#
#   `make test`       the static gates in a bare PHP container, no Nextcloud.
#   `make instance-*` a throwaway Nextcloud 34 + PostgreSQL 18 + Ollama (compose.dev.yaml).
#   `make test-seam`  the one seam (gestion-aio#162): «IA»'s OCS API and occ against that
#                     instance, with the real Model behind TaskProcessing and the standard
#                     connector, as on the suite.

IMG := ia-test
DOCKER := docker run --rm -v "$(CURDIR)":/app -w /app $(IMG)
COMPOSE := docker compose -f compose.dev.yaml
OCC := $(COMPOSE) exec --user www-data nextcloud php occ

# The suite's Model, as gestion-aio's aps/provisionador/model.json pins it: Ollama pulls by tag
# and the harness refuses any other digest (make model-check). Move both with the suite's pin.
MODEL := gemma4:e2b
MODEL_DIGEST := sha256:b37049369adfe3d2b653af0ab301a062ca5cbe96ace6aa0d3f2559ee9b563fc2
CONNECTOR := integration_openai
# What «IA local» imports for the connector on the suite (gestion-aio's local_ai.py and #167). The
# Model thinks by default, which spends max_tokens before it answers: reasoning_effort none stops it.
SETTINGS := {"apps": {"$(CONNECTOR)": {"url": "http://ollama:11434/v1", "default_completion_model_id": "$(MODEL)", \
	"chat_endpoint_enabled": "1", "request_timeout": "900", "max_tokens": "1000", \
	"llm_extra_params": "{\"reasoning_effort\": \"none\"}"}}}
# AIO's TaskProcessing worker: one process, restarted when its lifetime ends. AIO's lasts 300 s;
# this one 30 s, as it reads config.php once per run and a seam test moves the server's timezone
# (SeamTestCase::timezone).
WORKER := while :; do php occ background-job:worker -t 30 'OC\TaskProcessing\SynchronousBackgroundJob'; done

.PHONY: test bundles build-test-image deps instance-up instance-occ instance-down instance-clean model-check test-seam openapi model-digest clean

build-test-image:
	docker build -q -f Dockerfile.test -t $(IMG) . >/dev/null

# Vendor lands through the container, so no host PHP is needed.
deps: build-test-image
	$(DOCKER) composer install --no-interaction --no-progress

test: build-test-image
	$(DOCKER) sh -c "composer install --no-interaction --no-progress -q && vendor/bin/phpunit --configuration phpunit.xml --testdox"

# js/ is built, not committed: the release builds it before packing. This asserts the bundle
# and its licence file exist, the failure pack-app itself cannot see.
BUNDLES := main.js main.js.LICENSE.txt admin.js admin.js.LICENSE.txt

bundles:
	npm run build
	@for f in $(BUNDLES:%=js/ia-%); do \
		test -s "$$f" || { echo "::error::npm run build produced no $$f"; exit 1; }; \
	done

# The connector gets the settings «IA local» gives it on the suite (gestion-aio#167). The enable
# runs the app's migrations as a clinic's occ app:enable does.
instance-up:
	$(COMPOSE) up -d --wait
	$(COMPOSE) exec ollama ollama pull $(MODEL)
	# Docker creates custom_apps/ root-owned for this repo's bind mount, and the App Store
	# installs the connector there.
	$(COMPOSE) exec nextcloud chown www-data:www-data /var/www/html/custom_apps
	# libfaketime: a seam test runs a tick later than now, as a server would half an hour on.
	$(COMPOSE) exec nextcloud sh -c 'apt-get update -qq && apt-get install -y -qq --no-install-recommends libfaketime >/dev/null'
	$(OCC) app:enable $(CONNECTOR) 2>/dev/null || $(OCC) app:install $(CONNECTOR)
	# Assistant, as on the suite: it loads its own smalot/pdfparser, which «IA»'s prefixed copy must not meet.
	$(OCC) app:enable assistant 2>/dev/null || $(OCC) app:install assistant
	echo '$(SETTINGS)' | $(COMPOSE) exec -T --user www-data nextcloud php occ config:import
	$(OCC) app:enable ia
	# Restart AFTER enabling: the web workers cache the route collection and the enabled apps
	# in APCu, and occ cannot invalidate it (Estadística's Makefile, farmacia d4b7e43).
	$(COMPOSE) restart nextcloud
	$(COMPOSE) up -d --wait
	$(COMPOSE) exec -d --user www-data nextcloud sh -c "$(WORKER)"
	@echo "Instance ready at http://localhost:8093 (admin / admin)"

# Run any occ command:  make instance-occ CMD="app:list"
instance-occ:
	$(OCC) $(CMD)

instance-down:
	$(COMPOSE) down

# Wipe the instance including its database. The Model stays in its own directory.
instance-clean:
	$(COMPOSE) down -v

# The Model answers one core:text2text task through TaskProcessing, at the pinned digest.
model-check:
	$(COMPOSE) exec --user www-data nextcloud php custom_apps/ia/scripts/model-check.php $(MODEL) $(MODEL_DIGEST)

# The seam suite, then the groups that need the instance changed between two phases: Ollama
# stopped mid-task and started again, Nextcloud restarted mid-Run, and the Model down.
PHPUNIT := $(COMPOSE) exec --user www-data -w /var/www/html/custom_apps/ia nextcloud \
	php vendor/bin/phpunit --configuration phpunit.seam.xml --testdox
test-seam: model-check
	$(PHPUNIT) --exclude-group ollama-stop-a,ollama-stop-b,restart-a,restart-b,model-down
	$(PHPUNIT) --group ollama-stop-a
	$(COMPOSE) stop ollama
	$(COMPOSE) up -d --wait ollama
	$(PHPUNIT) --group ollama-stop-b
	$(PHPUNIT) --group restart-a
	$(COMPOSE) restart nextcloud
	$(COMPOSE) up -d --wait
	$(COMPOSE) exec -d --user www-data nextcloud sh -c "$(WORKER)"
	$(PHPUNIT) --group restart-b
	$(COMPOSE) stop ollama
	$(PHPUNIT) --group model-down; status=$$?; $(COMPOSE) up -d --wait ollama; exit $$status

# Regenerate openapi.json from ApiController's attributes and docblocks.
openapi:
	scripts/openapi.sh

# CI's cache key for the Model's directory.
model-digest:
	@echo $(MODEL_DIGEST)

clean:
	docker rmi $(IMG) 2>/dev/null || true
