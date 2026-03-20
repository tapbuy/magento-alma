#!/usr/bin/env bash
# Runs PHPUnit for the alma module inside a Docker replica of the CI environment.
# Invoke via: make test
#
# Dependencies:
#   - tapbuy/magento-redirect-plugin must be cloned at ../redirect-tracking
#   - tapbuy/data-scrubber must be cloned at ../data-scrubber
#   - alma/alma-monthlypayments-magento2 is cloned automatically to ~/.tapbuy-ci-cache/ on first run
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
IMAGE="tapbuy-ci-php83"
REDIRECT_TRACKING="${SCRIPT_DIR}/../redirect-tracking"
DATA_SCRUBBER="${SCRIPT_DIR}/../data-scrubber"
CACHE_DIR="${HOME}/.tapbuy-ci-cache"

# Keep in sync with ALMA_MODULE_REF in .github/workflows/unit-tests.yml
ALMA_REF="v5.3.0"

if ! docker image inspect "$IMAGE" > /dev/null 2>&1; then
    echo "Building Docker image ${IMAGE} (first run only)..."
    docker build -t "$IMAGE" "$SCRIPT_DIR"
fi

if [ ! -d "$REDIRECT_TRACKING" ]; then
    echo "Error: redirect-tracking not found at ${REDIRECT_TRACKING}" >&2
    echo "Clone tapbuy/magento-redirect-plugin next to this module directory." >&2
    exit 1
fi

if [ ! -d "$DATA_SCRUBBER" ]; then
    echo "Error: data-scrubber not found at ${DATA_SCRUBBER}" >&2
    echo "Clone tapbuy/data-scrubber next to this module directory." >&2
    exit 1
fi

mkdir -p "$CACHE_DIR"
# Re-clone if the cached ref doesn't match the pinned ref.
# To switch to a different ref: update ALMA_REF above; this block will re-clone automatically.
if [ "$(cat "${CACHE_DIR}/alma-module-payment/.ref" 2>/dev/null)" != "${ALMA_REF}" ]; then
    echo "Cloning alma/alma-monthlypayments-magento2 @ ${ALMA_REF}..."
    rm -rf "${CACHE_DIR}/alma-module-payment"
    git clone --depth 1 --branch "${ALMA_REF}" https://github.com/alma/alma-monthlypayments-magento2.git \
        "${CACHE_DIR}/alma-module-payment"
    echo "${ALMA_REF}" > "${CACHE_DIR}/alma-module-payment/.ref"
fi

if [ ! -f "${SCRIPT_DIR}/auth.json" ]; then
    echo "Error: auth.json not found in this module directory." >&2
    echo "Copy auth.json.dist to auth.json and fill in your Magento repo credentials." >&2
    exit 1
fi

docker run --rm \
    -v "tapbuy-magento-2.4.7-p5-php83:/magento" \
    -v "${SCRIPT_DIR}:/module:ro" \
    -v "${REDIRECT_TRACKING}:/tapbuy-redirect-tracking:ro" \
    -v "${DATA_SCRUBBER}:/tapbuy-data-scrubber:ro" \
    -v "${CACHE_DIR}/alma-module-payment:/thirdparty-alma:ro" \
    -v "${SCRIPT_DIR}/auth.json:/root/.composer/auth.json:ro" \
    "$IMAGE" \
    bash /module/docker-entrypoint.sh
