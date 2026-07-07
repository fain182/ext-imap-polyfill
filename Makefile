# The greenmail-up readiness check uses bash's /dev/tcp; on Debian/Ubuntu
# (incl. GitHub Actions runners) /bin/sh is dash, which lacks it.
SHELL := /bin/bash

CONTAINER_RUNTIME ?= $(shell command -v podman 2>/dev/null || command -v docker 2>/dev/null)
GREENMAIL_IMAGE := docker.io/greenmail/standalone:2.1.0
GREENMAIL_NAME := ext-imap-polyfill-greenmail
GREENMAIL_PORT := 13143
NETWORK_NAME := ext-imap-polyfill-net
PARITY_IMAGE := ext-imap-polyfill-parity

.PHONY: install test test-unit test-integration phpstan greenmail-up greenmail-down parity parity-build

## --ignore-platform-reqs: webklex/php-imap declares ext-zip as a hard
## dependency for attachment-archiving helpers this polyfill never calls.
install:
	composer install --ignore-platform-reqs

phpstan: install
	vendor/bin/phpstan analyse --memory-limit=1G

test-unit: install
	vendor/bin/phpunit --testsuite unit

## Starts a disposable Greenmail IMAP server used as the fixture for integration
## tests: reachable from the host at 127.0.0.1:$(GREENMAIL_PORT), and from other
## containers on $(NETWORK_NAME) at "greenmail:3143" (used by the parity target).
## See docker-compose.yml for the equivalent service definition.
greenmail-up:
	$(CONTAINER_RUNTIME) network create $(NETWORK_NAME) >/dev/null 2>&1 || true
	$(CONTAINER_RUNTIME) rm -f $(GREENMAIL_NAME) >/dev/null 2>&1 || true
	$(CONTAINER_RUNTIME) run -d --name $(GREENMAIL_NAME) \
		--network $(NETWORK_NAME) --network-alias greenmail \
		-p $(GREENMAIL_PORT):3143 \
		-e GREENMAIL_OPTS='-Dgreenmail.setup.test.imap -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.users=testuser:testpass@localhost' \
		$(GREENMAIL_IMAGE)
	@echo "Waiting for Greenmail to greet IMAP clients on port $(GREENMAIL_PORT)..."
	@until exec 3<>/dev/tcp/127.0.0.1/$(GREENMAIL_PORT) && read -r -t 2 greeting <&3 && [[ "$$greeting" == '* OK'* ]]; do \
		exec 3<&- 2>/dev/null; sleep 1; \
	done

greenmail-down:
	$(CONTAINER_RUNTIME) rm -f $(GREENMAIL_NAME) >/dev/null 2>&1 || true
	$(CONTAINER_RUNTIME) network rm $(NETWORK_NAME) >/dev/null 2>&1 || true

test-integration: install greenmail-up
	vendor/bin/phpunit --testsuite integration; \
	status=$$?; \
	$(MAKE) greenmail-down; \
	exit $$status

test: test-unit test-integration

parity-build:
	$(CONTAINER_RUNTIME) build -f Dockerfile.parity -t $(PARITY_IMAGE) .

## Runs the integration suite against the real ext-imap extension on PHP 8.3
## (the last version where it shipped in core), against the same Greenmail
## fixture, to check that tests/Integration's assumptions also hold true
## against the genuine extension and not just against this polyfill.
parity: parity-build greenmail-up
	$(CONTAINER_RUNTIME) run --rm \
		--network $(NETWORK_NAME) \
		-e IMAP_POLYFILL_TEST_HOST=greenmail \
		-e IMAP_POLYFILL_TEST_PORT=3143 \
		-v $(CURDIR):/app:Z \
		$(PARITY_IMAGE) \
		sh -c 'composer install --ignore-platform-reqs --quiet && php -m | grep -q imap && vendor/bin/phpunit --testsuite integration'; \
	status=$$?; \
	$(MAKE) greenmail-down; \
	exit $$status
