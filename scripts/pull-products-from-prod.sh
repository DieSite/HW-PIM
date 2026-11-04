#!/usr/bin/env bash
#
# Mirror the whole product catalogue from production into the local database:
# products (parents + variants), their relations, categories, the attribute
# set-up and the DAM assets/mappings the product images point at.
#
# Usage:
#   scripts/pull-products-from-prod.sh [--with-competitor-data] [--yes] [--dry-run]
#
#   --with-competitor-data  also mirror competitor_prices, the price history and
#                           the review/exclusion tables of the competitor analysis
#   --yes                   do not ask for confirmation before replacing tables
#   --dry-run               only print the row counts on both sides
#
# Why a table mirror and not product:export/product:import per SKU: local is a
# snapshot of production, so product IDs are identical on both sides. Mirroring
# keeps them identical, which keeps every ID-keyed table (DAM mappings, price
# history, relations) consistent, and pulls ~65k products in a minute instead
# of hours.
#
# Safe for production: mysqldump runs with --single-transaction (a consistent
# read without table locks) and only reads. Locally, the tables are backed up
# to storage/app/prod-sync-backups/ before they are replaced. The dump is data
# only with column names (--complete-insert), so a local schema that is ahead of
# production (a new nullable column) keeps working.
#
# Product credentials for Bol.com (product_bol_com_credentials) are NOT pulled:
# they point at bol_com_credentials rows, which hold secrets and differ per
# environment.
#
# Runs on the host (it needs your SSH key for production), and talks to the
# local database through the shared devstack (`dev mysql`).
#
# Environment overrides (or put them in scripts/.env-sync-product):
#   PROD_SSH   SSH target (default: pim_prod@178.22.59.74)
#   PROD_PATH  Project root on production
#              (default: /home/pim_prod/domains/pim.huis-en-wonen.nl/current)
#   LOCAL_MYSQL_CONTAINER  local MySQL container (default: mysql84)
#   LOCAL_DB   local database (default: hw_pim)

set -euo pipefail

if [[ -f "$(dirname "$0")/.env-sync-product" ]]; then
    # shellcheck disable=SC1091
    source "$(dirname "$0")/.env-sync-product"
fi

PROD_SSH="${PROD_SSH:-pim_prod@178.22.59.74}"
PROD_PATH="${PROD_PATH:-/home/pim_prod/domains/pim.huis-en-wonen.nl/current}"
LOCAL_MYSQL_CONTAINER="${LOCAL_MYSQL_CONTAINER:-mysql84}"
LOCAL_DB="${LOCAL_DB:-hw_pim}"

CATALOG_TABLES=(
    attributes
    attribute_translations
    attribute_options
    attribute_option_translations
    attribute_families
    attribute_family_translations
    attribute_groups
    attribute_group_translations
    attribute_family_group_mappings
    attribute_group_mappings
    categories
    category_fields
    category_field_translations
    category_field_options
    category_field_option_translations
    products
    product_super_attributes
    product_relations
    dam_directories
    dam_assets
    dam_asset_directory
    dam_asset_resource_mappings
)

COMPETITOR_TABLES=(
    competitor_prices
    competitor_price_removals
    competitor_price_exclusions
    competitor_coverage_confirmations
    competitor_signal_reviews
    product_price_history
)

WITH_COMPETITOR=0
ASSUME_YES=0
DRY_RUN=0

for arg in "$@"; do
    case "$arg" in
        --with-competitor-data) WITH_COMPETITOR=1 ;;
        --yes|-y) ASSUME_YES=1 ;;
        --dry-run) DRY_RUN=1 ;;
        -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

TABLES=("${CATALOG_TABLES[@]}")
if [[ $WITH_COMPETITOR -eq 1 ]]; then
    TABLES+=("${COMPETITOR_TABLES[@]}")
fi

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="${PROJECT_ROOT}/storage/app/prod-sync-backups"
BACKUP_FILE="${BACKUP_DIR}/local-before-pull-${STAMP}.sql.gz"
DUMP_FILE="${BACKUP_DIR}/prod-${STAMP}.sql.gz"

hide_password_warning() {
    grep -v 'Using a password on the command line' >&2 || true
}

local_mysql() {
    docker exec -i "${LOCAL_MYSQL_CONTAINER}" mysql -usail -ppassword --default-character-set=utf8mb4 "${LOCAL_DB}" "$@" \
        2> >(hide_password_warning)
}

local_mysqldump() {
    docker exec -i "${LOCAL_MYSQL_CONTAINER}" mysqldump -usail -ppassword "$@" 2> >(hide_password_warning)
}

# Runs a script on production with the database credentials from its .env in
# a client option file, so they never show up in a process list or locally.
# $1 = the command to run, "$MYSQL_DEFAULTS" is the option file.
prod_with_db() {
    # ssh joins its arguments into one string for the remote shell, so quote them.
    ssh "${PROD_SSH}" "bash -s -- $(printf '%q ' "${PROD_PATH}" "$1")" <<'REMOTE'
set -euo pipefail
cd "$1"
env_value() {
    grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}
MYSQL_DEFAULTS="$(mktemp)"
trap 'rm -f "$MYSQL_DEFAULTS"' EXIT
chmod 600 "$MYSQL_DEFAULTS"
DB_HOST="$(env_value DB_HOST || true)"
DB_PORT="$(env_value DB_PORT || true)"
printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
    "$(env_value DB_USERNAME)" "$(env_value DB_PASSWORD)" \
    "${DB_HOST:-127.0.0.1}" "${DB_PORT:-3306}" > "$MYSQL_DEFAULTS"
DB_DATABASE="$(env_value DB_DATABASE)"
eval "$2"
REMOTE
}

count_query() {
    local sql="" table
    for table in "${TABLES[@]}"; do
        sql+="SELECT '${table}', COUNT(*) FROM ${table} UNION ALL "
    done
    echo "${sql% UNION ALL };"
}

echo "==> Row counts (production vs local)"
PROD_COUNTS="$(prod_with_db "mysql --defaults-extra-file=\"\$MYSQL_DEFAULTS\" -N \"\$DB_DATABASE\" -e \"$(count_query)\"")"
LOCAL_COUNTS="$(local_mysql -N -e "$(count_query)")"
LC_ALL=C join -t $'\t' -a1 -a2 -e '-' -o 0,1.2,2.2 <(LC_ALL=C sort <<<"$PROD_COUNTS") <(LC_ALL=C sort <<<"$LOCAL_COUNTS") \
    | awk -F'\t' 'BEGIN { printf "%-40s %10s %10s\n", "table", "prod", "local" } { printf "%-40s %10s %10s\n", $1, $2, $3 }'

if [[ $DRY_RUN -eq 1 ]]; then
    exit 0
fi

if [[ $ASSUME_YES -ne 1 ]]; then
    echo
    read -r -p "Replace these local tables with the production data? A backup is written first. [y/N] " answer
    [[ "$answer" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }
fi

mkdir -p "${BACKUP_DIR}"

echo "==> Backing up local tables to ${BACKUP_FILE}"
local_mysqldump --single-transaction --no-create-info --complete-insert --skip-triggers \
    --no-tablespaces "${LOCAL_DB}" "${TABLES[@]}" | gzip > "${BACKUP_FILE}"

echo "==> Dumping production tables"
prod_with_db "mysqldump --defaults-extra-file=\"\$MYSQL_DEFAULTS\" --single-transaction --quick \
    --no-create-info --complete-insert --skip-add-locks --skip-disable-keys --skip-triggers \
    --no-tablespaces --set-gtid-purged=OFF \
    --default-character-set=utf8mb4 \"\$DB_DATABASE\" ${TABLES[*]} | gzip" > "${DUMP_FILE}"

if ! gzip -t "${DUMP_FILE}" 2>/dev/null || [[ ! -s "${DUMP_FILE}" ]]; then
    echo "Error: production dump is empty or corrupt; local database left untouched." >&2
    rm -f "${DUMP_FILE}"
    exit 1
fi

if ! gunzip -c "${DUMP_FILE}" | tail -n 1 | grep -q 'Dump completed'; then
    echo "Error: production dump did not complete; local database left untouched." >&2
    rm -f "${DUMP_FILE}"
    exit 1
fi

# One transaction: DELETE instead of TRUNCATE (DDL commits implicitly), and the
# dump has no LOCK TABLES / DISABLE KEYS for the same reason. If the load fails
# halfway, the client exits, the transaction rolls back and local is unchanged.
echo "==> Replacing local tables"
{
    echo "SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0; SET autocommit=0;"
    for table in "${TABLES[@]}"; do
        echo "DELETE FROM ${table};"
    done
    gunzip -c "${DUMP_FILE}"
    echo "COMMIT; SET FOREIGN_KEY_CHECKS=1; SET UNIQUE_CHECKS=1;"
} | local_mysql

rm -f "${DUMP_FILE}"

echo "==> Clearing the application cache"
(cd "${PROJECT_ROOT}" && dev art cache:clear --no-interaction >/dev/null)

echo "==> Row counts after the pull"
local_mysql -N -e "$(count_query)" | awk -F'\t' '{ printf "%-40s %10s\n", $1, $2 }'

echo "==> Done. Restore the previous local state with:"
echo "    gunzip -c ${BACKUP_FILE#"${PROJECT_ROOT}"/} | dev mysql   (after emptying the tables)"
