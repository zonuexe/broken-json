ECS := vendor/bin/ecs
PHPUNIT := vendor-bin/phpunit/vendor/bin/phpunit
INFECTION := vendor-bin/infection/vendor/bin/infection
PHPSTAN := ./vendor/bin/phpstan
PHP_COVERAGE := php -d xdebug.mode=coverage
INFECTION_DIFF_BASE := master

.PHONY: install fmt fmt-fix phpunit infection infection-all phpstan qa qa-all test test-all

install:
	composer install --no-interaction --prefer-dist

fmt:
	$(ECS) check

fmt-fix:
	$(ECS) check --fix

phpunit:
	$(PHPUNIT)

infection:
	XDEBUG_MODE=coverage $(PHP_COVERAGE) $(INFECTION) --git-diff-filter=AM --git-diff-base=$(INFECTION_DIFF_BASE)

infection-all:
	XDEBUG_MODE=coverage $(PHP_COVERAGE) $(INFECTION)

phpstan:
	$(PHPSTAN) analyse

qa: fmt phpstan test

qa-all: fmt phpstan test-all

test: phpunit infection

test-all: phpunit infection-all
