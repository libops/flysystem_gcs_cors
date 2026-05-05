.PHONY: test test-network test-chromedriver test-stop

DRUPAL_VERSION ?= 11.3
PHP_VERSION ?= 8.3
ENABLE_MODULES ?= flysystem_gcs_cors
TEST_SUITE ?=
CI_NETWORK ?= ci-default
CHROMEDRIVER_CONTAINER ?= chromedriver
DRUPAL_CI_CONTAINER ?= drupal-ci-$(DRUPAL_VERSION)-$(PHP_VERSION)

test: test-network test-chromedriver
	docker run \
		--name $(DRUPAL_CI_CONTAINER) \
		--hostname drupal \
		--rm \
		--volume "$$(pwd):/var/www/drupal/web/modules/contrib/$(ENABLE_MODULES):ro" \
		--env ENABLE_MODULES="$(ENABLE_MODULES)" \
		--env TEST_SUITE="$(TEST_SUITE)" \
		--network $(CI_NETWORK) \
		ghcr.io/islandora/ci:$(DRUPAL_VERSION)-php$(PHP_VERSION)

test-network:
	@if ! docker network inspect $(CI_NETWORK) >/dev/null 2>&1; then \
		docker network create $(CI_NETWORK); \
	fi

test-chromedriver:
	@if ! docker ps --format '{{.Names}}' | grep -Fx $(CHROMEDRIVER_CONTAINER) >/dev/null 2>&1; then \
		docker run -d \
			--rm \
			--name $(CHROMEDRIVER_CONTAINER) \
			--network $(CI_NETWORK) \
			drupalci/webdriver-chromedriver:production \
			chromedriver --log-path=/dev/null --verbose --allowed-ips= --allowed-origins=*; \
	fi

test-stop:
	-@docker stop $(CHROMEDRIVER_CONTAINER) >/dev/null 2>&1 || true
