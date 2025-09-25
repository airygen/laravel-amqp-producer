SHELL := /bin/bash

DOCKER_COMPOSE := docker compose
PHP_SERVICE := php
PHP_RUN := $(DOCKER_COMPOSE) run --rm $(PHP_SERVICE)

.PHONY: help install up down restart shell logs test unit integration coverage lint analyse fix clean ci

help:
	@echo "Available targets:"
	@echo "  install      - composer install inside container"
	@echo "  up           - start rabbitmq (and build php image)"
	@echo "  down         - stop all services"
	@echo "  restart      - restart services"
	@echo "  shell        - interactive shell in php container"
	@echo "  logs         - follow rabbitmq logs"
	@echo "  test         - run full test suite"
	@echo "  unit         - run unit tests"
	@echo "  integration  - run integration tests"
	@echo "  coverage     - generate coverage reports"
	@echo "  lint         - run code style check"
	@echo "  analyse      - run static analysis"
	@echo "  fix          - auto-fix style issues (phpcbf)"
	@echo "  clean        - remove vendor & coverage"
	@echo "  ci           - run fast checks (lint + analyse + unit)"

install:
	$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) composer install

up:
	$(DOCKER_COMPOSE) up -d rabbitmq
	$(DOCKER_COMPOSE) build $(PHP_SERVICE)

down:
	$(DOCKER_COMPOSE) down -v

restart: down up

shell:
	$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) bash

logs:
	$(DOCKER_COMPOSE) logs -f rabbitmq

test: unit integration

unit:
	$(PHP_RUN) php -d xdebug.mode=off vendor/bin/phpunit --testsuite Unit --no-coverage --testdox

integration:
	$(DOCKER_COMPOSE) run --rm -e INTEGRATION_TESTS=1 $(PHP_SERVICE) php -d xdebug.mode=off vendor/bin/phpunit --testsuite Integration --no-coverage --testdox

coverage:
	$(DOCKER_COMPOSE) run --rm -e XDEBUG_MODE=coverage $(PHP_SERVICE) php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-html=coverage/html --coverage-clover=coverage/clover.xml || true

lint:
	$(PHP_RUN) vendor/bin/phpcs --standard=phpcs.xml --report=full

analyse:
	$(PHP_RUN) vendor/bin/phpstan analyse --memory-limit=512M

fix:
	$(DOCKER_COMPOSE) run --rm $(PHP_SERVICE) vendor/bin/phpcbf --standard=phpcs.xml || true

clean:
	rm -rf vendor coverage .phpunit.cache || true

ci: lint analyse unit
