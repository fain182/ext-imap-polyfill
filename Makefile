# The greenmail-up readiness check uses bash's /dev/tcp; on Debian/Ubuntu
# (incl. GitHub Actions runners) /bin/sh is dash, which lacks it.
SHELL := /bin/bash

CONTAINER_RUNTIME ?= $(shell command -v podman 2>/dev/null || command -v docker 2>/dev/null)
## Prefix of every container, network and image name, so that checkouts of
## the repository in other directories (git worktrees) run their fixtures side
## by side instead of removing each other's. A checkout named after the
## repository keeps the plain names and the default ports.
##
## Every other checkout moves its host ports up by a multiple of 100 drawn
## from a checksum of its name: 1 to 180 hundreds, which keeps the highest
## port under Linux's ephemeral range (32768). Two names can draw the same
## offset, and then any port can be passed by hand (`make test
## PORT_OFFSET=...` or `GREENMAIL_PORT=...`).
##
## The name is lowercased and stripped to what container and image names
## accept: an image repository refuses capitals.
CHECKOUT := $(shell basename -- '$(subst ','\'',$(CURDIR))' | tr 'A-Z' 'a-z' | tr -c 'a-z0-9_.\n-' '-')
IS_MAIN_CHECKOUT := $(filter ext-imap-polyfill,$(CHECKOUT))
INSTANCE ?= ext-imap-polyfill$(if $(IS_MAIN_CHECKOUT),,-$(CHECKOUT))
PORT_OFFSET ?= $(if $(IS_MAIN_CHECKOUT),0,$(shell printf %s '$(CHECKOUT)' | cksum | awk '{ print ($$1 % 180 + 1) * 100 }'))

## Greenmail's imap, pop3, imaps and pop3s, then Dovecot's imap and pop3.
## Shifted by a multiple of 100, two of them would collide across checkouts
## if they ended in the same two digits, so that is refused here.
FIXTURE_BASE_PORTS := 13143 13110 13993 13995 13144 13111
ifneq ($(shell printf '%s\n' $(FIXTURE_BASE_PORTS) | awk '{ print $$1 % 100 }' | sort | uniq -d),)
$(error Two of FIXTURE_BASE_PORTS end in the same two digits, so a PORT_OFFSET can put one checkout's port on another's)
endif
FIXTURE_PORTS := $(shell for port in $(FIXTURE_BASE_PORTS); do echo $$((port + $(PORT_OFFSET))); done)

GREENMAIL_IMAGE := docker.io/greenmail/standalone:2.1.12
GREENMAIL_NAME := $(INSTANCE)-greenmail
GREENMAIL_PORT ?= $(word 1,$(FIXTURE_PORTS))
GREENMAIL_POP3_PORT ?= $(word 2,$(FIXTURE_PORTS))
GREENMAIL_IMAPS_PORT ?= $(word 3,$(FIXTURE_PORTS))
GREENMAIL_POP3S_PORT ?= $(word 4,$(FIXTURE_PORTS))
## One user per suite that can run at the same time as another: testuser for
## the suite on the host, parity for the one in the parity container.
GREENMAIL_OPTS := -Dgreenmail.setup.test.imap -Dgreenmail.setup.test.pop3 -Dgreenmail.setup.test.imaps -Dgreenmail.setup.test.pop3s -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.users=testuser:testpass@localhost,parity:testpass@localhost
DOVECOT_IMAGE := docker.io/dovecot/dovecot:latest
DOVECOT_NAME := $(INSTANCE)-dovecot
DOVECOT_PORT ?= $(word 5,$(FIXTURE_PORTS))
DOVECOT_POP3_PORT ?= $(word 6,$(FIXTURE_PORTS))
DOVECOT_SSL_DIR := $(CURDIR)/tests/fixtures/dovecot-ssl
NETWORK_NAME := $(INSTANCE)-net
PARITY_IMAGE := $(INSTANCE)-parity
PARITY_VENDOR_VOLUME := $(INSTANCE)-parity-vendor

## What the suite reads to find the fixtures started on the ports above. A
## port left at the suite's own default is not passed, and one is never
## passed over a value already exported, so .env and `FOO=bar make test`
## still point the suite elsewhere.
fixture_env = $(if $(filter-out $(2),$(3)),$(1)=$${$(1):-$(3)})
FIXTURE_ENV := \
	$(call fixture_env,IMAP_POLYFILL_TEST_PORT,$(word 1,$(FIXTURE_BASE_PORTS)),$(GREENMAIL_PORT)) \
	$(call fixture_env,IMAP_POLYFILL_TEST_POP3_PORT,$(word 2,$(FIXTURE_BASE_PORTS)),$(GREENMAIL_POP3_PORT)) \
	$(call fixture_env,IMAP_POLYFILL_TEST_IMAPS_PORT,$(word 3,$(FIXTURE_BASE_PORTS)),$(GREENMAIL_IMAPS_PORT)) \
	$(call fixture_env,IMAP_POLYFILL_TEST_POP3S_PORT,$(word 4,$(FIXTURE_BASE_PORTS)),$(GREENMAIL_POP3S_PORT)) \
	$(call fixture_env,IMAP_POLYFILL_DOVECOT_PORT,$(word 5,$(FIXTURE_BASE_PORTS)),$(DOVECOT_PORT)) \
	$(call fixture_env,IMAP_POLYFILL_DOVECOT_POP3_PORT,$(word 6,$(FIXTURE_BASE_PORTS)),$(DOVECOT_POP3_PORT))

## What a running fixture must have been started with to be reused: a change
## to the image, its options, its host ports, dovecot.conf or the certificate
## replaces it. Dovecot's is `=` rather than `:=` so that it is computed when
## dovecot-up runs, after its prerequisite has generated the certificate.
GREENMAIL_CONFIG := $(shell echo '$(GREENMAIL_IMAGE) $(GREENMAIL_OPTS) $(GREENMAIL_PORT) $(GREENMAIL_POP3_PORT) $(GREENMAIL_IMAPS_PORT) $(GREENMAIL_POP3S_PORT)' | cksum | cut -d' ' -f1)
DOVECOT_CONFIG = $(shell (echo '$(DOVECOT_IMAGE) $(DOVECOT_PORT) $(DOVECOT_POP3_PORT)'; cat tests/fixtures/dovecot.conf $(DOVECOT_SSL_DIR)/tls.crt) | cksum | cut -d' ' -f1)
CONFIG_LABEL := ext-imap-polyfill.config

## Narrows a phpunit run: `make test-integration FILTER=ImapSavebodyTest`.
FILTER ?=
PHPUNIT_FILTER := $(if $(FILTER),--filter '$(FILTER)')

.PHONY: arch fuzz install install-lowest check test test-unit test-integration cross-check phpstan phpt greenmail-up greenmail-down dovecot-up dovecot-down fixtures-down parity parity-build

install:
	composer install

## Resolves to the oldest releases composer.json allows. The test targets'
## `install` prerequisite then installs from that lock instead of resolving
## again, which is what carries the old versions into the run.
install-lowest:
	composer update --prefer-lowest --prefer-stable

phpstan: install
	vendor/bin/phpstan analyse --memory-limit=1G

arch: install
	vendor/bin/phparkitect check

test-unit: install
	vendor/bin/phpunit --testsuite unit $(PHPUNIT_FILTER)

## Starts a disposable Greenmail IMAP server used as the fixture for integration
## tests: reachable from the host at 127.0.0.1:$(GREENMAIL_PORT), and from other
## containers on $(NETWORK_NAME) at "greenmail:3143" (used by the parity target).
## See docker-compose.yml for the equivalent service definition.
##
## The -up targets leave alone a running container started with the same
## configuration, and no test target stops one, so a fixture outlives the run
## that started it and the next run skips the start. `make fixtures-down`
## stops them.
greenmail-up:
	@$(CONTAINER_RUNTIME) network create $(NETWORK_NAME) >/dev/null 2>&1 || true
	@if [ "$$($(CONTAINER_RUNTIME) container inspect -f '{{.State.Running}} {{index .Config.Labels "$(CONFIG_LABEL)"}}' $(GREENMAIL_NAME) 2>/dev/null)" = "true $(GREENMAIL_CONFIG)" ]; then \
		echo "Greenmail is already running."; \
	else \
		$(CONTAINER_RUNTIME) rm -f $(GREENMAIL_NAME) >/dev/null 2>&1 || true; \
		$(CONTAINER_RUNTIME) run -d --name $(GREENMAIL_NAME) \
			--label $(CONFIG_LABEL)=$(GREENMAIL_CONFIG) \
			--network $(NETWORK_NAME) --network-alias greenmail \
			-p $(GREENMAIL_PORT):3143 \
			-p $(GREENMAIL_POP3_PORT):3110 \
			-p $(GREENMAIL_IMAPS_PORT):3993 \
			-p $(GREENMAIL_POP3S_PORT):3995 \
			-e GREENMAIL_OPTS='$(GREENMAIL_OPTS)' \
			$(GREENMAIL_IMAGE); \
	fi
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
## The certificate the fixture presents at STARTTLS. Generated rather than
## committed, and self-signed on purpose: every spec in the suite carries
## /novalidate-cert, so a certificate that validates would prove nothing —
## and one test opens without the switch to check it is refused.
## The file names are the image's own: its /etc/dovecot/conf.d/ssl.conf
## points cert_file and key_file at this directory, and Dovecot refuses to
## start when they aren't there.
$(DOVECOT_SSL_DIR)/tls.crt:
	@mkdir -p $(DOVECOT_SSL_DIR)
	openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
		-subj '/CN=dovecot' -addext 'subjectAltName=DNS:dovecot,DNS:localhost,IP:127.0.0.1' \
		-keyout $(DOVECOT_SSL_DIR)/tls.key -out $(DOVECOT_SSL_DIR)/tls.crt 2>/dev/null
	@chmod 644 $(DOVECOT_SSL_DIR)/tls.key

dovecot-up: $(DOVECOT_SSL_DIR)/tls.crt
	@$(CONTAINER_RUNTIME) network create $(NETWORK_NAME) >/dev/null 2>&1 || true
	@if [ "$$($(CONTAINER_RUNTIME) container inspect -f '{{.State.Running}} {{index .Config.Labels "$(CONFIG_LABEL)"}}' $(DOVECOT_NAME) 2>/dev/null)" = "true $(DOVECOT_CONFIG)" ]; then \
		echo "Dovecot is already running."; \
	else \
		$(CONTAINER_RUNTIME) rm -f $(DOVECOT_NAME) >/dev/null 2>&1 || true; \
		$(CONTAINER_RUNTIME) run -d --name $(DOVECOT_NAME) \
			--label $(CONFIG_LABEL)=$(DOVECOT_CONFIG) \
			--network $(NETWORK_NAME) --network-alias dovecot \
			-p $(DOVECOT_PORT):31143 \
			-p $(DOVECOT_POP3_PORT):31110 \
			-e USER_PASSWORD=testpass \
			-v $(CURDIR)/tests/fixtures/dovecot.conf:/etc/dovecot/conf.d/10-test.conf:ro,Z \
			-v $(DOVECOT_SSL_DIR):/etc/dovecot/ssl:ro,Z \
			$(DOVECOT_IMAGE); \
	fi
	@echo "Waiting for Dovecot to greet IMAP clients on port $(DOVECOT_PORT)..."
	@until exec 5<>/dev/tcp/127.0.0.1/$(DOVECOT_PORT) && read -r -t 2 greeting <&5 && [[ "$$greeting" == '* OK'* ]]; do \
		exec 5<&- 2>/dev/null; sleep 1; \
	done

dovecot-down:
	$(CONTAINER_RUNTIME) rm -f $(DOVECOT_NAME) >/dev/null 2>&1 || true

## In this order: greenmail-down also removes the network, which fails while
## Dovecot is still attached to it, and prerequisites may run in parallel.
fixtures-down:
	$(MAKE) dovecot-down
	$(MAKE) greenmail-down

test-integration: install greenmail-up dovecot-up
	$(FIXTURE_ENV) vendor/bin/phpunit --testsuite integration $(PHPUNIT_FILTER)

test: test-unit test-integration

## Everything the CI workflow checks, in one run against one start of the
## fixtures. In parallel because nearly all of the time is spent waiting on the
## servers, and safe to be: each suite logs in as a user of its own (testuser,
## parity, a fresh one for phpt), and parity keeps its vendor/ in a volume of
## its own.
check:
	$(MAKE) --jobs --output-sync=target phpstan arch test phpt parity

## Runs the Greenmail-targeted suite against Dovecot instead, skipping the
## tests tagged greenmail-only (each carries the reason it cannot move).
## A failure here means a test has grown attached to one server's behaviour,
## or this polyfill leans on it — which is how the POP3 uid bug surfaced.
## Not part of `make test`: it is an audit to run before a release.
cross-check: install dovecot-up
	$(FIXTURE_ENV) \
	IMAP_POLYFILL_TEST_HOST=127.0.0.1 \
	IMAP_POLYFILL_TEST_PORT=$(DOVECOT_PORT) \
	IMAP_POLYFILL_TEST_POP3_PORT=$(DOVECOT_POP3_PORT) \
	vendor/bin/phpunit --testsuite integration --exclude-group greenmail-only $(PHPUNIT_FILTER)

parity-build:
	$(CONTAINER_RUNTIME) build -f Dockerfile.parity -t $(PARITY_IMAGE) .

## Runs the integration suite against the real ext-imap extension on PHP 8.3
## (the last version where it shipped in core), against the same Greenmail
## fixture, to check that tests/Integration's assumptions also hold true
## against the genuine extension and not just against this polyfill.
## Runs the extension's own .phpt suite (tests/phpt, vendored from
## php/pecl-mail-imap) against the polyfill. The tests are characterization
## tests written by the people who wrote the extension, so a failure here is
## a divergence from it — the nine that cannot be answered from userland
## carry an --XFAIL-- saying why, and run-tests.php reports those apart.
##
## An XFAIL that starts passing is only a warning to run-tests, which would
## leave the job green while a note in the tree went stale; the grep below
## is what turns it into a failure.
##
## The setup creates mailboxes with fixed names and refuses to run over one
## that exists, and the fixture outlives the run, so a run cut short before
## its --CLEAN-- would break every later one. Each run logs in as a user
## nobody has used yet: the fixture accepts any name and gives each an empty
## mail store.
phpt: install dovecot-up
	@set -o pipefail; 	$(FIXTURE_ENV) IMAP_POLYFILL_DOVECOT_USER=phpt$$(date +%s%N) REPORT_EXIT_STATUS=1 TEST_PHP_EXECUTABLE="$$(command -v php)" 		php tests/phpt/run-tests.php -q 			-d auto_prepend_file=$(CURDIR)/tests/bootstrap.php 			tests/phpt/tests | tee tests/phpt/run.log; 	status=$$?; 	if grep -q 'XFAIL section but test passes' tests/phpt/run.log; then 		echo 'An --XFAIL-- test passed: the reason it carries is out of date.'; 		status=1; 	fi; 	rm -f tests/phpt/run.log; 	exit $$status

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
		-e IMAP_POLYFILL_DOVECOT_POP3_PORT=31110 \
		-e IMAP_POLYFILL_TEST_USER=parity \
		-e IMAP_POLYFILL_DOVECOT_USER=parity \
		-e FILTER='$(FILTER)' \
		-v $(CURDIR):/app:Z \
		-v $(PARITY_VENDOR_VOLUME):/app/vendor \
		$(PARITY_IMAGE) \
		sh -c 'composer install --quiet && php -m | grep -q imap && vendor/bin/phpunit --testsuite integration $${FILTER:+--filter "$$FILTER"}'

## Differential fuzzing of the connectionless functions: the same mutated
## corpus through this package and through the genuine extension in the
## parity image, then a diff. It is an occasional campaign rather than part
## of `make test` — it needs the parity image, it takes minutes, and what it
## finds belongs in a characterization test, not in a run nobody repeats.
##
## FUZZ_SEED picks the corpus, so a run that found something can be run
## again. FUZZ_COUNT is how many mutations follow the seeds.
FUZZ_SEED ?= 1
FUZZ_COUNT ?= 2000
FUZZ_DIR := $(CURDIR)/tests/fuzz/run

fuzz: parity-build
	@mkdir -p $(FUZZ_DIR)
	php tests/fuzz/generate-corpus.php $(FUZZ_SEED) $(FUZZ_COUNT) > $(FUZZ_DIR)/corpus
	php tests/fuzz/evaluate.php $(FUZZ_DIR)/corpus > $(FUZZ_DIR)/answers.polyfill
	$(CONTAINER_RUNTIME) run --rm -v $(CURDIR):/app:Z $(PARITY_IMAGE) \
		sh -c 'php -m | grep -q imap && php tests/fuzz/evaluate.php tests/fuzz/run/corpus' \
		> $(FUZZ_DIR)/answers.real
	php tests/fuzz/compare.php $(FUZZ_DIR)/corpus $(FUZZ_DIR)/answers.polyfill $(FUZZ_DIR)/answers.real
