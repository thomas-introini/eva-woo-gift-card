#!/usr/bin/env bash
# File: bin/dev-up.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="${SCRIPT_DIR%/bin}"

cd "${PROJECT_ROOT}"

docker compose up -d --build

echo "Waiting for WordPress to be ready..."

RETRIES=30
SLEEP_SECONDS=5

for i in $(seq 1 "${RETRIES}"); do
  if docker compose exec -T wordpress wp core is-installed --allow-root >/dev/null 2>&1; then
    echo "WordPress is already installed."
    break
  else
    echo "Attempt ${i}/${RETRIES}: WordPress not installed yet, trying to install..."
    if docker compose exec -T wordpress wp core install \
      --url="http://localhost:8080" \
      --title="Eva Gift Cards Dev" \
      --admin_user="admin" \
      --admin_password="admin" \
      --admin_email="admin@example.com" \
      --skip-email \
      --allow-root >/dev/null 2>&1; then
      echo "WordPress installed."
      break
    fi
  fi
  sleep "${SLEEP_SECONDS}"
done

echo "Ensuring WooCommerce is installed and activated..."
docker compose exec -T wordpress wp plugin install woocommerce --activate --allow-root --path=/var/www/html || true

echo "Activating Eva Gift Cards plugin..."
docker compose exec -T wordpress wp plugin activate eva-gift-cards --allow-root --path=/var/www/html || true

echo "Creating or updating test 'Gift card' product..."
docker compose exec -T wordpress bash -lc '
  set -e
  PRODUCT_ID="$(wp post list --post_type=product --meta_key=_sku --meta_value=gift-card-test --format=ids --allow-root --path=/var/www/html | tr \" \" \"\n\" | head -n1)"
  if [ -z "$PRODUCT_ID" ]; then
    echo "Creating Gift card product..."
    PRODUCT_ID="$(wp post create --post_type=product --post_status=publish --post_title="Gift card" --porcelain --allow-root --path=/var/www/html)"
    wp post update "$PRODUCT_ID" --post_name=gift-card --allow-root --path=/var/www/html
    echo "Gift card product created with ID $PRODUCT_ID"
  else
    echo "Gift card product already exists with ID $PRODUCT_ID"
  fi
  # Ensure product type and basic WooCommerce meta
  wp post term set "$PRODUCT_ID" product_type simple --allow-root --path=/var/www/html || true
  wp post meta update "$PRODUCT_ID" _sku gift-card-test --allow-root --path=/var/www/html
  wp post meta update "$PRODUCT_ID" _regular_price 50 --allow-root --path=/var/www/html
  wp post meta update "$PRODUCT_ID" _price 50 --allow-root --path=/var/www/html
  wp post meta update "$PRODUCT_ID" _virtual yes --allow-root --path=/var/www/html
  wp post meta update "$PRODUCT_ID" _stock_status instock --allow-root --path=/var/www/html
  # Ensure Eva Gift Cards flags and amount
  wp post meta update "$PRODUCT_ID" _eva_is_gift_card yes --allow-root --path=/var/www/html
  wp post meta update "$PRODUCT_ID" _eva_gift_card_amount 50 --allow-root --path=/var/www/html
  wp post meta update "$PRODUCT_ID" _eva_gift_card_message "Buono regalo di test" --allow-root --path=/var/www/html
' || true

echo "Development environment is ready at http://localhost:8080"


