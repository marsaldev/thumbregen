#!/usr/bin/env bash
#
# Integration test: installs PrestaShop with demo data in Docker, installs the module
# and runs the console commands against the real catalog.
#
# Usage: tests/integration/run.sh <prestashop/prestashop image tag>    e.g. 8.2-8.1, 8.0-7.2
#
# Env:
#   COMPOSER_BIN  composer command used to generate vendor/ (default: composer)
#   KEEP=1        keep the containers at the end, for debugging
#
set -euo pipefail

TAG="${1:?usage: $0 <prestashop/prestashop image tag, e.g. 8.2-8.1>}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
ID="thumbregen-it-$$"
NET="$ID"
DB="$ID-db"
PS="$ID-ps"
WORK="$(mktemp -d)"
FAILURES=0

cleanup() {
    if [ "${KEEP:-0}" = 1 ]; then
        echo "Containers kept: $PS $DB (network $NET)"
    else
        docker rm -f "$PS" "$DB" > /dev/null 2>&1 || true
        docker network rm "$NET" > /dev/null 2>&1 || true
    fi
    rm -rf "$WORK"
}
trap cleanup EXIT

console() {
    docker exec -u www-data -w /var/www/html "$PS" php bin/console --env=prod --no-debug "$@"
}

sql() {
    docker exec "$DB" mysql -uroot -padmin -N -B prestashop -e "$1"
}

# img/p/1/2/3/123 for image 123, as Image::getImgFolderStatic()
image_base() {
    echo "img/p/$(echo "$1" | sed 's/./&\//g')$1"
}

file_exists() {
    docker exec "$PS" test -f "/var/www/html/$1"
}

pass() { echo "  PASS  $1"; }
fail() {
    echo "  FAIL  $1"
    if [ -n "${2:-}" ]; then echo "$2" | tail -40 | sed 's/^/        /'; fi
    FAILURES=$((FAILURES + 1))
}

# Runs a console command, stores its output in $OUT and checks the exit code.
expect_exit() {
    local want="$1" label="$2"
    shift 2
    set +e
    OUT="$(console "$@" 2>&1)"
    local got=$?
    set -e
    if [ "$got" -eq "$want" ]; then
        pass "$label (exit $got)"
        return 0
    fi
    fail "$label: expected exit $want, got $got" "$OUT"
    return 1
}

expect_output() {
    if grep -qE "$2" <<< "$OUT"; then pass "$1"; else fail "$1: output does not match /$2/" "$OUT"; fi
}

# Value of a row of the summary table, e.g. summary_value 'Products processed'
summary_value() {
    grep -E "^ *$1 +[0-9]+ *$" <<< "$OUT" | awk '{print $NF}' | head -1
}

echo "== PrestaShop image prestashop/prestashop:$TAG"

docker network create "$NET" > /dev/null
docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=admin mariadb:10.6 > /dev/null
docker run -d --name "$PS" --network "$NET" \
    -e DB_SERVER="$DB" -e DB_USER=root -e DB_PASSWD=admin -e DB_NAME=prestashop \
    -e PS_INSTALL_AUTO=1 -e PS_INSTALL_DB=1 -e PS_DOMAIN=localhost \
    -e PS_FOLDER_ADMIN=admin-dev \
    "prestashop/prestashop:$TAG" > /dev/null

echo "== Waiting for the PrestaShop installation (several minutes)"
for _ in $(seq 1 240); do
    LOGS="$(docker logs "$PS" 2>&1)"
    if grep -q 'installation failed' <<< "$LOGS"; then
        echo "$LOGS" | tail -60
        echo "PrestaShop installation failed"
        exit 1
    fi
    if grep -q 'Setup completed' <<< "$LOGS"; then
        break
    fi
    if [ "$(docker inspect -f '{{.State.Running}}' "$PS")" != true ]; then
        echo "$LOGS" | tail -60
        echo "PrestaShop container stopped"
        exit 1
    fi
    sleep 5
done
if ! grep -q 'Setup completed' <<< "$LOGS"; then
    echo "Timeout waiting for the PrestaShop installation"
    exit 1
fi
PS_VERSION="$(docker exec -u www-data "$PS" php -r 'require "/var/www/html/config/config.inc.php"; echo _PS_VERSION_;' 2> /dev/null)"
echo "   PrestaShop $PS_VERSION, PHP $(docker exec "$PS" php -r 'echo PHP_VERSION;')"

echo "== Installing the module"
mkdir -p "$WORK/thumbregen"
(cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | xargs -0 tar cf -) | tar xf - -C "$WORK/thumbregen"
$COMPOSER_BIN --working-dir="$WORK/thumbregen" dump-autoload -o -q --no-interaction
docker cp "$WORK/thumbregen" "$PS:/var/www/html/modules/thumbregen"
docker exec "$PS" chown -R www-data:www-data /var/www/html/modules/thumbregen

echo "== Tests"
expect_exit 0 'module install' prestashop:module install thumbregen || true
expect_exit 0 'cache clear' cache:clear --no-warmup || true

expect_exit 0 'list thumbregen' list thumbregen && {
    expect_output 'regenerate command registered' 'thumbregen:products:regenerate'
    expect_output 'list-types command registered' 'thumbregen:products:list-types'
}

expect_exit 0 'list-types' thumbregen:products:list-types && expect_output 'list-types shows home_default' 'home_default'

PRODUCT="$(sql 'SELECT id_product FROM ps_image GROUP BY id_product ORDER BY id_product LIMIT 1')"
IMAGE="$(sql "SELECT id_image FROM ps_image WHERE id_product = $PRODUCT ORDER BY position LIMIT 1")"
BASE="$(image_base "$IMAGE")"
HOME_THUMB="$BASE-home_default.jpg"
SMALL_THUMB="$BASE-small_default.jpg"
echo "   using product #$PRODUCT, image #$IMAGE ($BASE.jpg)"

# Single product, single type
docker exec "$PS" rm -f "/var/www/html/$HOME_THUMB"
expect_exit 0 'regenerate --product --type' thumbregen:products:regenerate --product="$PRODUCT" --type=home_default \
    && { file_exists "$HOME_THUMB" && pass 'home_default thumbnail generated' || fail "missing $HOME_THUMB"; }

# Dry run writes nothing
docker exec "$PS" rm -f "/var/www/html/$HOME_THUMB"
expect_exit 0 'dry-run' thumbregen:products:regenerate --product="$PRODUCT" --type=home_default --dry-run -v \
    && { file_exists "$HOME_THUMB" && fail 'dry-run wrote a thumbnail' || pass 'dry-run wrote nothing'; }
expect_output 'dry-run lists the planned file' "$IMAGE-home_default\\.jpg"

# --delete-old only touches the selected types
expect_exit 0 'regenerate home_default again' thumbregen:products:regenerate --product="$PRODUCT" --type=home_default || true
expect_exit 0 'delete-old small_default' thumbregen:products:regenerate --product="$PRODUCT" --type=small_default --delete-old \
    && {
        file_exists "$SMALL_THUMB" && pass 'small_default regenerated' || fail "missing $SMALL_THUMB"
        file_exists "$HOME_THUMB" && pass 'other types left alone' || fail "--delete-old removed $HOME_THUMB"
    }

# Multiple image formats: PS 8.1+ also generates the formats enabled in the image settings, PS 8.0 only jpg
WEBP_THUMB="$BASE-home_default.webp"
docker exec "$PS" rm -f "/var/www/html/$WEBP_THUMB"
sql "UPDATE ps_configuration SET value = 'jpg,webp' WHERE name = 'PS_IMAGE_FORMAT';
    INSERT INTO ps_configuration (name, value, date_add, date_upd)
    SELECT 'PS_IMAGE_FORMAT', 'jpg,webp', NOW(), NOW() FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM ps_configuration WHERE name = 'PS_IMAGE_FORMAT')"
if [ -n "$(sql "SHOW TABLES LIKE 'ps_feature_flag'")" ]; then
    sql "UPDATE ps_feature_flag SET state = 1 WHERE name = 'multiple_image_format'"
fi
expect_exit 0 'regenerate with jpg,webp enabled' thumbregen:products:regenerate --product="$PRODUCT" --type=home_default \
    && case "$PS_VERSION" in
        8.0.*) file_exists "$WEBP_THUMB" && fail 'PS 8.0 generated a webp thumbnail' || pass 'PS 8.0: jpg only' ;;
        *) file_exists "$WEBP_THUMB" && pass 'webp thumbnail generated' || fail "missing $WEBP_THUMB" "$OUT" ;;
    esac

# Category with subcategories, compared with the database
EXPECTED="$(sql "SELECT COUNT(DISTINCT cp.id_product) FROM ps_category_product cp
    INNER JOIN ps_category c ON c.id_category = cp.id_category
    INNER JOIN ps_category r ON r.id_category = 2
    WHERE c.nleft >= r.nleft AND c.nright <= r.nright")"
expect_exit 0 'category 2 with subcategories' thumbregen:products:regenerate --category=2 --with-subcategories --type=small_default \
    && { [ "$(summary_value 'Products processed')" = "$EXPECTED" ] && pass "category subtree: $EXPECTED products" \
        || fail "category subtree: expected $EXPECTED products, got $(summary_value 'Products processed')" "$OUT"; }

# Whole catalog with small batches (keyset pagination)
EXPECTED="$(sql 'SELECT COUNT(*) FROM ps_product')"
expect_exit 0 'whole catalog, --batch-size=3' thumbregen:products:regenerate --type=cart_default --batch-size=3 \
    && { [ "$(summary_value 'Products processed')" = "$EXPECTED" ] && pass "whole catalog: $EXPECTED products" \
        || fail "whole catalog: expected $EXPECTED products, got $(summary_value 'Products processed')" "$OUT"; }

# Invalid arguments: exit 1
expect_exit 1 '--product with --category' thumbregen:products:regenerate --product=1 --category=2 \
    && expect_output '  message' 'cannot be used together'
expect_exit 1 '--with-subcategories without --category' thumbregen:products:regenerate --with-subcategories \
    && expect_output '  message' 'requires --category'
expect_exit 1 'unknown --type' thumbregen:products:regenerate --type=does_not_exist \
    && expect_output '  message' 'does_not_exist'
expect_exit 1 'unknown product' thumbregen:products:regenerate --product=999999 \
    && expect_output '  message' 'does not exist'
expect_exit 1 'invalid --memory-limit' thumbregen:products:regenerate --memory-limit=lots \
    && expect_output '  message' 'Invalid --memory-limit'

# Corrupt original: image skipped, exit 0
docker exec "$PS" cp "/var/www/html/$BASE.jpg" /tmp/original.jpg
docker exec "$PS" sh -c "echo 'not an image' > /var/www/html/$BASE.jpg"
expect_exit 0 'corrupt original is skipped' thumbregen:products:regenerate --product="$PRODUCT" --type=home_default \
    && { [ "$(summary_value 'Images skipped \(missing/corrupt original\)')" -ge 1 ] && pass 'skip counted in the summary' || fail 'skip not counted' "$OUT"; }
docker exec "$PS" cp /tmp/original.jpg "/var/www/html/$BASE.jpg"

# Image folder not writable: run completes with errors, exit 2
DIR="$(dirname "$BASE")"
docker exec "$PS" chmod 555 "/var/www/html/$DIR"
expect_exit 2 'unwritable image folder' thumbregen:products:regenerate --product="$PRODUCT" --type=home_default \
    && expect_output 'error reported' 'not writable'
docker exec "$PS" chmod 755 "/var/www/html/$DIR"

# Product list from a file: header, CRLF, quoted columns, duplicates and unknown IDs.
# The 2nd and 3rd products are deleted from ps_product, so that with --batch-size=1 the selection has to
# skip empty chunks in the middle of the list (the database is thrown away at the end anyway).
IDS="$(sql 'SELECT id_product FROM ps_product ORDER BY id_product LIMIT 4')"
FIRST="$(sed -n 1p <<< "$IDS")"
{
    printf 'id_product;name\r\n'
    sed 's/$/;"name, with comma"\r/' <<< "$IDS"
    echo "$FIRST"
    echo 999999
} > "$WORK/ids.csv"
printf '1\nnot-an-id\n' > "$WORK/invalid.csv"
docker cp "$WORK/ids.csv" "$PS:/tmp/ids.csv"
docker cp "$WORK/invalid.csv" "$PS:/tmp/invalid.csv"
docker exec "$PS" chmod 644 /tmp/ids.csv /tmp/invalid.csv
sql "DELETE FROM ps_product WHERE id_product IN ($(sed -n '2,3p' <<< "$IDS" | paste -sd, -))"
expect_exit 0 '--product-file, --batch-size=1' thumbregen:products:regenerate --product-file=/tmp/ids.csv --type=small_default --batch-size=1 \
    && {
        [ "$(summary_value 'Products processed')" = 2 ] && pass 'product file: 2 existing products' \
            || fail "product file: expected 2 products, got $(summary_value 'Products processed')" "$OUT"
        expect_output 'unknown IDs reported' '3 product ID\(s\) of the file do not exist'
    }
expect_exit 1 '--product-file with an invalid ID' thumbregen:products:regenerate --product-file=/tmp/invalid.csv \
    && expect_output '  message' 'invalid product ID "not-an-id" on line 2'
expect_exit 1 'missing --product-file' thumbregen:products:regenerate --product-file=/tmp/does-not-exist.csv \
    && expect_output '  message' 'does not exist or is not readable'
expect_exit 1 '--product-file with --category' thumbregen:products:regenerate --product-file=/tmp/ids.csv --category=2 \
    && expect_output '  message' 'cannot be used together'

echo
if [ "$FAILURES" -gt 0 ]; then
    echo "== $FAILURES test(s) failed on prestashop/prestashop:$TAG"
    exit 1
fi
echo "== All tests passed on prestashop/prestashop:$TAG"
