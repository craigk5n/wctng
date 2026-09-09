#!/usr/bin/env bash
#
# Fetches the Infection PHAR for `make mutation`.
#
# The PHAR rather than composer require --dev, which is what Infection's own
# docs recommend: it drags in a large dependency tree that would otherwise have
# to co-exist with the app's. It also keeps `composer install` from having to
# re-resolve the craigk5n/webcalendar-core VCS repository, which needs the
# GitHub API and is rate limited to 60 requests an hour without a token.
#
# The version is pinned and the download is checked against a recorded SHA-256:
# a build step that pulls an unverified binary off the internet is exactly the
# supply-chain shape this project's `composer audit` gate exists to avoid.

set -euo pipefail

INFECTION_VERSION="0.35.4"
INFECTION_SHA256="24e9d2ab5fc5613be6b9fea99cfd2c689234d6e6053f339b8f5e05136246070a"

API_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_DIR="${API_DIR}/var/tools"
TARGET="${TARGET_DIR}/infection.phar"
URL="https://github.com/infection/infection/releases/download/${INFECTION_VERSION}/infection.phar"

# Compare the digest as a string rather than with `sha256sum -c`: the check
# and quiet flags differ between BusyBox (-s) and GNU coreutils (--status),
# and getting that wrong fails as "checksum mismatch" rather than as a usage
# error, which is how this went unnoticed while the CI job was advisory.
# Both implementations print "<digest>  <path>", so cut is portable.
sha256_of() {
    sha256sum "$1" | cut -d ' ' -f 1
}

if [ -f "${TARGET}" ] && [ "$(sha256_of "${TARGET}")" = "${INFECTION_SHA256}" ]; then
    echo "Infection ${INFECTION_VERSION} already present at ${TARGET}"
    exit 0
fi

mkdir -p "${TARGET_DIR}"

echo "Downloading Infection ${INFECTION_VERSION}..."
curl -fsSL -o "${TARGET}.tmp" "${URL}"

ACTUAL_SHA256="$(sha256_of "${TARGET}.tmp")"

if [ "${ACTUAL_SHA256}" != "${INFECTION_SHA256}" ]; then
    rm -f "${TARGET}.tmp"
    echo "Checksum mismatch for infection.phar ${INFECTION_VERSION}; refusing to install." >&2
    echo "Expected ${INFECTION_SHA256}" >&2
    echo "Actual   ${ACTUAL_SHA256}" >&2
    exit 1
fi

mv "${TARGET}.tmp" "${TARGET}"
chmod +x "${TARGET}"
echo "Installed Infection ${INFECTION_VERSION} at ${TARGET}"
