.PHONY: test phpstan psalm cs-fix cs-check coverage ci

## Run PHPUnit tests
test:
	./vendor/bin/phpunit

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

## Run all CI checks
ci: cs-check phpstan psalm test

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
