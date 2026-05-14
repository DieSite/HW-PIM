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
#   PROD_SSH         SSH target (default: pim_prod@178.22.59.74)
#   PROD_PATH        Project root on production
#                    (default: /home/pim_prod/domains/pim.huis-en-wonen.nl/current)
#   PROD_PHP         PHP binary on production (default: /home/pim_prod/bin/php)
#   LOCAL_DOCKER     Local web container name (default: unopim-web)
#   EXPORT_COMMAND   Artisan signature used to export (default: "product:export")
#   IMPORT_COMMAND   Artisan signature used to import (default: "import:products")
#   EXPORT_SUBDIR    Subdirectory under the project root for export files
#                    (default: storage/app/product-exports)
#   LOCAL_IMPORT_DIR Directory inside the local container to drop the file in
#                    (default: /var/www/html/storage/app/product-exports)

set -euo pipefail

if [[ -f "$(dirname "$0")/.env-sync-product" ]]; then
    # shellcheck disable=SC1091
    source "$(dirname "$0")/.env-sync-product"
fi

PROD_SSH="${PROD_SSH:-pim_prod@178.22.59.74}"
PROD_PATH="${PROD_PATH:-/home/pim_prod/domains/pim.huis-en-wonen.nl/current}"
PROD_PHP="${PROD_PHP:-/home/pim_prod/bin/php}"
LOCAL_DOCKER="${LOCAL_DOCKER:-unopim-web}"
EXPORT_COMMAND="${EXPORT_COMMAND:-product:export}"
IMPORT_COMMAND="${IMPORT_COMMAND:-product:import}"
EXPORT_SUBDIR="${EXPORT_SUBDIR:-storage/app/product-exports}"
LOCAL_IMPORT_DIR="${LOCAL_IMPORT_DIR:-/var/www/html/storage/app/product-exports}"

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
FILENAME="product-${PRODUCT_ID}-${STAMP}.json"
REMOTE_FILE="${PROD_PATH}/${EXPORT_SUBDIR}/${FILENAME}"
LOCAL_TMP="$(mktemp -d)"
LOCAL_FILE="${LOCAL_TMP}/${FILENAME}"
LOCAL_CONTAINER_FILE="${LOCAL_IMPORT_DIR}/${FILENAME}"

cleanup() {
    rm -rf "${LOCAL_TMP}" || true
}
trap cleanup EXIT

echo "==> Exporting product ${PRODUCT_ID} on production"
ssh "${PROD_SSH}" "mkdir -p ${PROD_PATH}/${EXPORT_SUBDIR} && \
    cd ${PROD_PATH} && \
    ${PROD_PHP} artisan ${EXPORT_COMMAND} ${PRODUCT_ID} ${REMOTE_FILE} --no-interaction"

echo "==> Downloading export to local machine"
scp "${PROD_SSH}:${REMOTE_FILE}" "${LOCAL_FILE}"

echo "==> Copying export into local container"
docker exec -i "${LOCAL_DOCKER}" mkdir -p "${LOCAL_IMPORT_DIR}"
docker cp "${LOCAL_FILE}" "${LOCAL_DOCKER}:${LOCAL_CONTAINER_FILE}"

echo "==> Importing product locally"
docker exec -i "${LOCAL_DOCKER}" php artisan "${IMPORT_COMMAND}" "${LOCAL_CONTAINER_FILE}" --no-interaction

echo "==> Cleaning up remote export"
ssh "${PROD_SSH}" "rm -f ${REMOTE_FILE}"

echo "==> Cleaning up local export (container)"
docker exec -i "${LOCAL_DOCKER}" rm -f "${LOCAL_CONTAINER_FILE}"

echo "==> Done. Product ${PRODUCT_ID} synced from production to local."
