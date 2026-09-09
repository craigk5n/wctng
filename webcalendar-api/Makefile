.PHONY: test test-unit test-integration phpstan psalm cs-fix cs-check coverage ci check-sensitive-params check-clock-injection check-schema-drift

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

## Fix code style (PER-CS 3.0 via php-cs-fixer)
cs-fix:
	./vendor/bin/php-cs-fixer fix

## Check code style without fixing (PER-CS 3.0)
cs-check:
	./vendor/bin/php-cs-fixer fix --dry-run --diff

## Generate test coverage report
coverage:
	XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text --coverage-html=var/coverage

## Guard: every $password/$token/$secret/etc param must carry #[\SensitiveParameter]
check-sensitive-params:
	php bin/check-sensitive-params.php

## Guard: business logic must read time via injected ClockInterface, not new \DateTimeImmutable()
check-clock-injection:
	php bin/check-clock-injection.php

## Guard: webcalendar-core's schema must match schema/core-schema-snapshot.json
## (add --db to check a live database instead; needs DATABASE_URL)
check-schema-drift:
	php bin/check-schema-drift.php

## Mutation testing over the whole src tree (slow: minutes per file)
mutation:
	@bin/install-infection.sh
	php var/tools/infection.phar --threads=$(shell nproc) --show-mutations

## Mutation testing limited to lines this branch changed (same gate as CI).
## Run from the host, not the php-fpm container: only webcalendar-api/ is
## mounted there, so git cannot find the repository root to diff against.
mutation-diff:
	@bin/install-infection.sh
	php var/tools/infection.phar --threads=$(shell nproc) --git-diff-lines --git-diff-base=origin/main \
		--min-covered-msi=60 --ignore-msi-with-no-mutations --logger-github

## Run all CI checks
ci: cs-check check-sensitive-params check-clock-injection phpstan psalm test

## Show help
help:
	@echo "webcalendar-api development commands:"
	@echo "  make test      - Run PHPUnit tests"
	@echo "  make phpstan   - Run PHPStan (level 9)"
	@echo "  make psalm     - Run Psalm"
	@echo "  make cs-fix    - Fix code style"
	@echo "  make cs-check  - Check code style"
	@echo "  make coverage  - Generate coverage report"
	@echo "  make mutation  - Mutation testing (slow; whole src tree)"
	@echo "  make mutation-diff - Mutation testing on this branch's changed lines"
	@echo "  make ci        - Run all CI checks"
