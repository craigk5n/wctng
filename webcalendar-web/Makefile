.PHONY: dev build test test-e2e lint format ci

## Start Vite dev server
dev:
	npm run dev

## Build for production
build:
	npm run build

## Run Vitest unit/component tests
test:
	npx vitest run

## Run Playwright E2E tests
test-e2e:
	npx playwright test

## Run ESLint
lint:
	npx eslint src/ --max-warnings 0

## Run Prettier
format:
	npm run format

## Check formatting without fixing
format-check:
	npm run format:check

## TypeScript type check
typecheck:
	npx tsc --noEmit

## Run all CI checks
ci: typecheck lint test build

## Show help
help:
	@echo "webcalendar-web development commands:"
	@echo "  make dev        - Start Vite dev server"
	@echo "  make build      - Build for production"
	@echo "  make test       - Run Vitest tests"
	@echo "  make test-e2e   - Run Playwright E2E tests"
	@echo "  make lint       - Run ESLint"
	@echo "  make format     - Run Prettier"
	@echo "  make typecheck  - TypeScript type check"
	@echo "  make ci         - Run all CI checks"
