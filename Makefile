ECS := vendor/bin/ecs
PHPUNIT := vendor-bin/phpunit/vendor/bin/phpunit
INFECTION := vendor-bin/infection/vendor/bin/infection
PHPSTAN := ./vendor/bin/phpstan

.PHONY: install formatter formatter-fix phpunit infection phpstan qa

install:
	composer install --no-interaction --prefer-dist

formatter:
	$(ECS) check

formatter-fix:
	$(ECS) check --fix

phpunit:
	$(PHPUNIT)

infection:
	$(INFECTION)

phpstan:
	$(PHPSTAN) analyse

qa: formatter phpstan phpunit
