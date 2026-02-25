ECS := vendor/bin/ecs
PHPUNIT := vendor-bin/phpunit/vendor/bin/phpunit
INFECTION := vendor-bin/infection/vendor/bin/infection
PHPSTAN := ./vendor/bin/phpstan
PHP_COVERAGE := php -d xdebug.mode=coverage

.PHONY: install formatter formatter-fix phpunit infection phpstan qa test

install:
	composer install --no-interaction --prefer-dist

formatter:
	$(ECS) check

formatter-fix:
	$(ECS) check --fix

phpunit:
	$(PHPUNIT)

infection:
	XDEBUG_MODE=coverage $(PHP_COVERAGE) $(INFECTION)

phpstan:
	$(PHPSTAN) analyse

qa: formatter phpstan test

test: phpunit infection
