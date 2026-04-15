.PHONY: test test-unit test-integration phpstan psalm cs-fix cs-check coverage ci check-sensitive-params

## Run PHPUnit tests
test:
	./vendor/bin/phpunit

## Run unit tests only
test-unit:
	./vendor/bin/phpunit tests/Unit/

## Run integration tests only
test-integration:
	./vendor/bin/phpunit tests/Integration/

## Run PHPStan (level 9)
phpstan:
	./vendor/bin/phpstan analyse --memory-limit=512M

## Run Psalm
psalm:
	./vendor/bin/psalm --no-cache

## Fix code style (PSR-12)
cs-fix:
	./vendor/bin/php-cs-fixer fix

## Check code style without fixing
cs-check:
	./vendor/bin/php-cs-fixer fix --dry-run --diff

## Generate test coverage report
coverage:
	XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text --coverage-html=var/coverage

## Guard: every $password/$token/$secret/etc param must carry #[\SensitiveParameter]
check-sensitive-params:
	php bin/check-sensitive-params.php

## Run all CI checks
ci: cs-check check-sensitive-params phpstan psalm test

## Show help
help:
	@echo "webcalendar-api development commands:"
	@echo "  make test      - Run PHPUnit tests"
	@echo "  make phpstan   - Run PHPStan (level 9)"
	@echo "  make psalm     - Run Psalm"
	@echo "  make cs-fix    - Fix code style"
	@echo "  make cs-check  - Check code style"
	@echo "  make coverage  - Generate coverage report"
	@echo "  make ci        - Run all CI checks"
