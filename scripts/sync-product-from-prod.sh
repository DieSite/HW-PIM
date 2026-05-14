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
#   LOCAL_PHP        PHP binary used locally (default: php on PATH)
#   LOCAL_DOCKER     Run local artisan inside this docker container
#                    (default: unopim-web). Set to empty string to run natively
#                    with LOCAL_PHP instead.
#   EXPORT_COMMAND   Artisan signature used to export (default: "product:export")
#   IMPORT_COMMAND   Artisan signature used to import (default: "product:import")
#   EXPORT_SUBDIR    Subdirectory under the project root for export files
#                    (default: storage/app/product-exports)

set -euo pipefail

if [[ -f "$(dirname "$0")/.env-sync-product" ]]; then
    # shellcheck disable=SC1091
    source "$(dirname "$0")/.env-sync-product"
fi

PROD_SSH="${PROD_SSH:-pim_prod@178.22.59.74}"
PROD_PATH="${PROD_PATH:-/home/pim_prod/domains/pim.huis-en-wonen.nl/current}"
PROD_PHP="${PROD_PHP:-/home/pim_prod/bin/php}"
LOCAL_PHP="${LOCAL_PHP:-php}"
LOCAL_DOCKER="${LOCAL_DOCKER:-unopim-web}"
EXPORT_COMMAND="${EXPORT_COMMAND:-product:export}"
IMPORT_COMMAND="${IMPORT_COMMAND:-product:import}"
EXPORT_SUBDIR="${EXPORT_SUBDIR:-storage/app/product-exports}"

if [[ $# -lt 1 ]]; then
    echo "Usage: $0 <product_id>" >&2
    exit 1
fi

PRODUCT_ID="$1"
if ! [[ "${PRODUCT_ID}" =~ ^[0-9]+$ ]]; then
    echo "Error: product_id must be a positive integer, got '${PRODUCT_ID}'" >&2
    exit 1
fi

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
FILENAME="product-${PRODUCT_ID}-${STAMP}.json"
REMOTE_FILE="${PROD_PATH}/${EXPORT_SUBDIR}/${FILENAME}"
LOCAL_DIR="${PROJECT_ROOT}/${EXPORT_SUBDIR}"
LOCAL_FILE="${LOCAL_DIR}/${FILENAME}"

mkdir -p "${LOCAL_DIR}"

cleanup() {
    rm -f "${LOCAL_FILE}" 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Exporting product ${PRODUCT_ID} on production"
ssh "${PROD_SSH}" "mkdir -p ${PROD_PATH}/${EXPORT_SUBDIR} && \
    cd ${PROD_PATH} && \
    ${PROD_PHP} artisan ${EXPORT_COMMAND} ${PRODUCT_ID} ${REMOTE_FILE} --no-interaction"

echo "==> Downloading export to local machine"
scp "${PROD_SSH}:${REMOTE_FILE}" "${LOCAL_FILE}"

echo "==> Importing product locally"
if [[ -n "${LOCAL_DOCKER}" ]]; then
    LOCAL_CONTAINER_FILE="/var/www/html/${EXPORT_SUBDIR}/${FILENAME}"
    docker exec -i "${LOCAL_DOCKER}" mkdir -p "/var/www/html/${EXPORT_SUBDIR}"
    docker cp "${LOCAL_FILE}" "${LOCAL_DOCKER}:${LOCAL_CONTAINER_FILE}"
    docker exec -i "${LOCAL_DOCKER}" php artisan "${IMPORT_COMMAND}" "${LOCAL_CONTAINER_FILE}" --no-interaction
    docker exec -i "${LOCAL_DOCKER}" rm -f "${LOCAL_CONTAINER_FILE}"
else
    (cd "${PROJECT_ROOT}" && "${LOCAL_PHP}" artisan "${IMPORT_COMMAND}" "${LOCAL_FILE}" --no-interaction)
fi

echo "==> Cleaning up remote export"
ssh "${PROD_SSH}" "rm -f ${REMOTE_FILE}"

echo "==> Done. Product ${PRODUCT_ID} synced from production to local."
