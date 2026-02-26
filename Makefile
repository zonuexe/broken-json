ECS := vendor/bin/ecs
PHPUNIT := vendor-bin/phpunit/vendor/bin/phpunit
INFECTION := vendor-bin/infection/vendor/bin/infection
FUZZER := vendor-bin/fuzzer/vendor/bin/php-fuzzer
PHPSTAN := ./vendor/bin/phpstan
PHP_COVERAGE := php -d xdebug.mode=coverage
INFECTION_DIFF_BASE ?= master
FUZZ_MAX_RUNS ?= 5000
FUZZ_TIMEOUT ?= 5
FUZZ_WORKDIR := .tmp-fuzz/corpus

.PHONY: install fmt fmt-fix phpunit infection infection-all fuzz fuzz-single fuzz-minimize phpstan qa qa-all test test-all

install:
	composer install --no-interaction --prefer-dist

fmt:
	$(ECS) check

fmt-fix:
	$(ECS) check --fix

phpunit:
	$(PHPUNIT)

infection:
	XDEBUG_MODE=coverage $(PHP_COVERAGE) $(INFECTION) --git-diff-filter=AM --git-diff-base=$(INFECTION_DIFF_BASE) --test-framework-options="--do-not-fail-on-warning --do-not-fail-on-phpunit-warning"

infection-all:
	XDEBUG_MODE=coverage $(PHP_COVERAGE) $(INFECTION) --test-framework-options="--do-not-fail-on-warning --do-not-fail-on-phpunit-warning"

fuzz:
	rm -rf $(FUZZ_WORKDIR)
	mkdir -p .tmp-fuzz
	cp -R fuzz/corpus $(FUZZ_WORKDIR)
	$(FUZZER) fuzz --max-runs=$(FUZZ_MAX_RUNS) --timeout=$(FUZZ_TIMEOUT) fuzz/target_decoder.php $(FUZZ_WORKDIR)

fuzz-single:
	$(FUZZER) run-single fuzz/target_decoder.php $(INPUT)

fuzz-minimize:
	$(FUZZER) minimize-crash fuzz/target_decoder.php $(INPUT)

phpstan:
	$(PHPSTAN) analyse

qa: fmt phpstan test

qa-all: fmt phpstan test-all

test: phpunit infection

test-all: phpunit infection-all
