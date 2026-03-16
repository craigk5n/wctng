.PHONY: lint generate ci

## Lint the OpenAPI spec with Spectral
lint:
	npx spectral lint openapi.yaml

## Generate TypeScript types from OpenAPI spec
generate:
	sh scripts/generate.sh

## Run all CI checks
ci: lint generate

## Show help
help:
	@echo "webcalendar-openapi commands:"
	@echo "  make lint      - Lint OpenAPI spec"
	@echo "  make generate  - Generate TypeScript types"
	@echo "  make ci        - Run all CI checks"
