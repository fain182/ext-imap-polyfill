# The greenmail-up readiness check uses bash's /dev/tcp; on Debian/Ubuntu
# (incl. GitHub Actions runners) /bin/sh is dash, which lacks it.
SHELL := /bin/bash

CONTAINER_RUNTIME ?= $(shell command -v podman 2>/dev/null || command -v docker 2>/dev/null)
GREENMAIL_IMAGE := docker.io/greenmail/standalone:2.1.12
GREENMAIL_NAME := ext-imap-polyfill-greenmail
GREENMAIL_PORT := 13143
GREENMAIL_POP3_PORT := 13110
GREENMAIL_IMAPS_PORT := 13993
GREENMAIL_POP3S_PORT := 13995
DOVECOT_IMAGE := docker.io/dovecot/dovecot:latest
DOVECOT_NAME := ext-imap-polyfill-dovecot
DOVECOT_PORT := 13144
DOVECOT_POP3_PORT := 13111
NETWORK_NAME := ext-imap-polyfill-net
PARITY_IMAGE := ext-imap-polyfill-parity

.PHONY: install test test-unit test-integration cross-check phpstan greenmail-up greenmail-down dovecot-up dovecot-down parity parity-build

install:
	composer install

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
		-p $(GREENMAIL_POP3_PORT):3110 \
		-p $(GREENMAIL_IMAPS_PORT):3993 \
		-p $(GREENMAIL_POP3S_PORT):3995 \
		-e GREENMAIL_OPTS='-Dgreenmail.setup.test.imap -Dgreenmail.setup.test.pop3 -Dgreenmail.setup.test.imaps -Dgreenmail.setup.test.pop3s -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.users=testuser:testpass@localhost' \
		$(GREENMAIL_IMAGE)
	@echo "Waiting for Greenmail to greet IMAP clients on port $(GREENMAIL_PORT)..."
	@until exec 3<>/dev/tcp/127.0.0.1/$(GREENMAIL_PORT) && read -r -t 2 greeting <&3 && [[ "$$greeting" == '* OK'* ]]; do \
		exec 3<&- 2>/dev/null; sleep 1; \
	done
	@echo "Waiting for Greenmail to greet POP3 clients on port $(GREENMAIL_POP3_PORT)..."
	@until exec 4<>/dev/tcp/127.0.0.1/$(GREENMAIL_POP3_PORT) && read -r -t 2 greeting <&4 && [[ "$$greeting" == '+OK'* ]]; do \
		exec 4<&- 2>/dev/null; sleep 1; \
	done

greenmail-down:
	$(CONTAINER_RUNTIME) rm -f $(GREENMAIL_NAME) >/dev/null 2>&1 || true
	$(CONTAINER_RUNTIME) network rm $(NETWORK_NAME) >/dev/null 2>&1 || true

## Second fixture, for the two things Greenmail has no support for at all:
## THREAD and ACL (tests/Integration/DovecotTestCase). Its unprivileged IMAP
## listener is on 31143, not 143. Tests skip themselves when it isn't up, so
## this is only needed for the Dovecot-specific classes.
dovecot-up:
	$(CONTAINER_RUNTIME) network create $(NETWORK_NAME) >/dev/null 2>&1 || true
	$(CONTAINER_RUNTIME) rm -f $(DOVECOT_NAME) >/dev/null 2>&1 || true
	$(CONTAINER_RUNTIME) run -d --name $(DOVECOT_NAME) \
		--network $(NETWORK_NAME) --network-alias dovecot \
		-p $(DOVECOT_PORT):31143 \
		-p $(DOVECOT_POP3_PORT):31110 \
		-e USER_PASSWORD=testpass \
		-v $(CURDIR)/tests/fixtures/dovecot.conf:/etc/dovecot/conf.d/10-test.conf:ro,Z \
		$(DOVECOT_IMAGE)
	@echo "Waiting for Dovecot to greet IMAP clients on port $(DOVECOT_PORT)..."
	@until exec 5<>/dev/tcp/127.0.0.1/$(DOVECOT_PORT) && read -r -t 2 greeting <&5 && [[ "$$greeting" == '* OK'* ]]; do \
		exec 5<&- 2>/dev/null; sleep 1; \
	done

dovecot-down:
	$(CONTAINER_RUNTIME) rm -f $(DOVECOT_NAME) >/dev/null 2>&1 || true

test-integration: install greenmail-up dovecot-up
	vendor/bin/phpunit --testsuite integration; \
	status=$$?; \
	$(MAKE) dovecot-down; \
	$(MAKE) greenmail-down; \
	exit $$status

test: test-unit test-integration

## Runs the Greenmail-targeted suite against Dovecot instead, skipping the
## tests tagged greenmail-only (each carries the reason it cannot move).
## A failure here means a test has grown attached to one server's behaviour,
## or this polyfill leans on it — which is how the POP3 uid bug surfaced.
## Not part of `make test`: it is an audit to run before a release.
cross-check: install dovecot-up
	IMAP_POLYFILL_TEST_HOST=127.0.0.1 \
	IMAP_POLYFILL_TEST_PORT=$(DOVECOT_PORT) \
	IMAP_POLYFILL_TEST_POP3_PORT=$(DOVECOT_POP3_PORT) \
	vendor/bin/phpunit --testsuite integration --exclude-group greenmail-only; \
	status=$$?; \
	$(MAKE) dovecot-down; \
	exit $$status

parity-build:
	$(CONTAINER_RUNTIME) build -f Dockerfile.parity -t $(PARITY_IMAGE) .

## Runs the integration suite against the real ext-imap extension on PHP 8.3
## (the last version where it shipped in core), against the same Greenmail
## fixture, to check that tests/Integration's assumptions also hold true
## against the genuine extension and not just against this polyfill.
parity: parity-build greenmail-up dovecot-up
	$(CONTAINER_RUNTIME) run --rm \
		--network $(NETWORK_NAME) \
		-e IMAP_POLYFILL_TEST_HOST=greenmail \
		-e IMAP_POLYFILL_TEST_PORT=3143 \
		-e IMAP_POLYFILL_TEST_POP3_PORT=3110 \
		-e IMAP_POLYFILL_TEST_IMAPS_PORT=3993 \
		-e IMAP_POLYFILL_TEST_POP3S_PORT=3995 \
		-e IMAP_POLYFILL_DOVECOT_HOST=dovecot \
		-e IMAP_POLYFILL_DOVECOT_PORT=31143 \
		-v $(CURDIR):/app:Z \
		$(PARITY_IMAGE) \
		sh -c 'composer install --quiet && php -m | grep -q imap && vendor/bin/phpunit --testsuite integration'; \
	status=$$?; \
	$(MAKE) dovecot-down; \
	$(MAKE) greenmail-down; \
	exit $$status
