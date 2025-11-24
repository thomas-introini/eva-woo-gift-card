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

echo "Development environment is ready at http://localhost:8080"


