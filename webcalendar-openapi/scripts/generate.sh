#!/usr/bin/env sh
set -e

echo "Generating TypeScript types from OpenAPI spec..."
npx openapi-typescript openapi.yaml -o generated/typescript/index.ts
echo "Done. Output: generated/typescript/index.ts"
