#!/usr/bin/env bash
# Runs PHPUnit for the alma module inside a Docker replica of the CI environment.
# Invoke via: make test
#
# Dependencies:
#   - tapbuy/magento-redirect-plugin must be cloned at ../redirect-tracking
#   - alma/alma-monthlypayments-magento2 is cloned automatically to ~/.tapbuy-ci-cache/ on first run
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
IMAGE="tapbuy-ci-php83"
REDIRECT_TRACKING="${SCRIPT_DIR}/../redirect-tracking"
CACHE_DIR="${HOME}/.tapbuy-ci-cache"

if ! docker image inspect "$IMAGE" > /dev/null 2>&1; then
    echo "Building Docker image ${IMAGE} (first run only)..."
    docker build -t "$IMAGE" "$SCRIPT_DIR"
fi

if [ ! -d "$REDIRECT_TRACKING" ]; then
    echo "Error: redirect-tracking not found at ${REDIRECT_TRACKING}" >&2
    echo "Clone tapbuy/magento-redirect-plugin next to this module directory." >&2
    exit 1
fi

mkdir -p "$CACHE_DIR"
if [ ! -f "${CACHE_DIR}/alma-module-payment/registration.php" ]; then
    echo "Cloning alma/alma-monthlypayments-magento2 (first run only)..."
    rm -rf "${CACHE_DIR}/alma-module-payment"
    git clone --depth 1 https://github.com/alma/alma-monthlypayments-magento2.git \
        "${CACHE_DIR}/alma-module-payment"
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
    -v "${CACHE_DIR}/alma-module-payment:/thirdparty-alma:ro" \
    -v "${SCRIPT_DIR}/auth.json:/root/.composer/auth.json:ro" \
    "$IMAGE" \
    bash /module/docker-entrypoint.sh
