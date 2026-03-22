#!/usr/bin/env bash
# Runs PHPMD and/or PHPCS (Magento2 standard) for this module inside the
# tapbuy-ci-php83 Docker image (same image used by `make test`).
# Invoke via: make phpmd | make phpcs | make lint
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
IMAGE="tapbuy-ci-php83"
TOOL="${1:-lint}"

if ! docker image inspect "$IMAGE" > /dev/null 2>&1; then
    echo "Building Docker image ${IMAGE} (first run only)..."
    docker build -t "$IMAGE" "$SCRIPT_DIR"
fi

run_phpmd() {
    echo ""
    echo "========================================================="
    echo " PHPMD -- $(basename "$SCRIPT_DIR")"
    echo "========================================================="
    docker run --rm \
        -v "${SCRIPT_DIR}:/module:ro" \
        "$IMAGE" \
        bash -c "cd /module && phpmd . text cleancode,codesize,controversial,design,naming,unusedcode --exclude Test"
}

run_phpcs() {
    echo ""
    echo "========================================================="
    echo " PHPCS (Magento2) -- $(basename "$SCRIPT_DIR")"
    echo "========================================================="
    docker run --rm \
        -v "${SCRIPT_DIR}:/module:ro" \
        "$IMAGE" \
        bash -c "cd /module && phpcs -s --extensions=php ." \
        | grep -v -E "^DEPRECATED|sniff is listening for"
}

case "$TOOL" in
    phpmd) run_phpmd ;;
    phpcs) run_phpcs ;;
    lint)
        # Run both tools; collect exit codes so both always run.
        PHPMD_EXIT=0; PHPCS_EXIT=0
        run_phpmd || PHPMD_EXIT=$?
        run_phpcs || PHPCS_EXIT=$?
        exit $(( PHPMD_EXIT | PHPCS_EXIT ))
        ;;
    *)
        echo "Usage: $0 {phpmd|phpcs|lint}" >&2
        exit 1
        ;;
esac
