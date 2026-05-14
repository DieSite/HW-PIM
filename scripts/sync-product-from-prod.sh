#!/usr/bin/env bash
#
# Export a single product on production, copy it to the local machine,
# import it into the local database, and remove the export file on both sides.
#
# Usage:
#   scripts/sync-product-from-prod.sh <product_id>
#
# Required environment (override defaults below by exporting them, or put them
# in scripts/.env-sync-product and the script will source it):
#   PROD_SSH         SSH target, e.g. "deploy@prod.example.com"
#   PROD_PATH        Absolute path to the project root on production
#   PROD_DOCKER      Web container name on production (default: unopim-web)
#   LOCAL_DOCKER     Local web container name        (default: unopim-web)
#   EXPORT_COMMAND   Artisan signature used to export (default: "product:export")
#   IMPORT_COMMAND   Artisan signature used to import (default: "import:products")
#   EXPORT_DIR       Directory inside the container where the file is written
#                    (default: /var/www/html/storage/app/product-exports)

set -euo pipefail

if [[ -f "$(dirname "$0")/.env-sync-product" ]]; then
    # shellcheck disable=SC1091
    source "$(dirname "$0")/.env-sync-product"
fi

PROD_SSH="${PROD_SSH:?PROD_SSH must be set (e.g. deploy@prod.example.com)}"
PROD_PATH="${PROD_PATH:?PROD_PATH must be set (project root on production)}"
PROD_DOCKER="${PROD_DOCKER:-unopim-web}"
LOCAL_DOCKER="${LOCAL_DOCKER:-unopim-web}"
EXPORT_COMMAND="${EXPORT_COMMAND:-product:export}"
IMPORT_COMMAND="${IMPORT_COMMAND:-import:products}"
EXPORT_DIR="${EXPORT_DIR:-/var/www/html/storage/app/product-exports}"

if [[ $# -lt 1 ]]; then
    echo "Usage: $0 <product_id>" >&2
    exit 1
fi

PRODUCT_ID="$1"
if ! [[ "${PRODUCT_ID}" =~ ^[0-9]+$ ]]; then
    echo "Error: product_id must be a positive integer, got '${PRODUCT_ID}'" >&2
    exit 1
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
FILENAME="product-${PRODUCT_ID}-${STAMP}.xlsx"
REMOTE_FILE="${EXPORT_DIR}/${FILENAME}"
LOCAL_TMP="$(mktemp -d)"
LOCAL_FILE="${LOCAL_TMP}/${FILENAME}"

cleanup() {
    rm -rf "${LOCAL_TMP}" || true
}
trap cleanup EXIT

echo "==> Exporting product ${PRODUCT_ID} on production"
ssh "${PROD_SSH}" "docker exec -i ${PROD_DOCKER} mkdir -p ${EXPORT_DIR} && \
    docker exec -i ${PROD_DOCKER} php artisan ${EXPORT_COMMAND} ${PRODUCT_ID} ${REMOTE_FILE} --no-interaction"

echo "==> Copying export file from container to production host"
ssh "${PROD_SSH}" "docker cp ${PROD_DOCKER}:${REMOTE_FILE} ${PROD_PATH}/${FILENAME}"

echo "==> Downloading export to local machine"
scp "${PROD_SSH}:${PROD_PATH}/${FILENAME}" "${LOCAL_FILE}"

echo "==> Copying export into local container"
docker cp "${LOCAL_FILE}" "${LOCAL_DOCKER}:${REMOTE_FILE}"

echo "==> Importing product locally"
docker exec -i "${LOCAL_DOCKER}" php artisan "${IMPORT_COMMAND}" "${REMOTE_FILE}" --no-interaction

echo "==> Cleaning up remote export (container + host)"
ssh "${PROD_SSH}" "docker exec -i ${PROD_DOCKER} rm -f ${REMOTE_FILE} && rm -f ${PROD_PATH}/${FILENAME}"

echo "==> Cleaning up local export (container)"
docker exec -i "${LOCAL_DOCKER}" rm -f "${REMOTE_FILE}"

echo "==> Done. Product ${PRODUCT_ID} synced from production to local."
