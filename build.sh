#!/usr/bin/env bash
set -euo pipefail

# Build a distributable zip of the eva-gift-cards plugin for upload to WordPress.
#
# Output:
#   dist/eva-gift-cards-v<version>.zip
#
# Notes:
# - Version is read from the plugin header in eva-gift-cards.php
# - Common development files are excluded from the archive

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SLUG="eva-gift-cards"
PLUGIN_DIR_REL="wp-content/plugins/${PLUGIN_SLUG}"
PLUGIN_DIR="${ROOT_DIR}/${PLUGIN_DIR_REL}"
MAIN_PLUGIN_FILE="${PLUGIN_DIR}/${PLUGIN_SLUG}.php"
DIST_DIR="${ROOT_DIR}/dist"
BUILD_DIR="${ROOT_DIR}/build"
STAGING_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"

if [[ ! -f "${MAIN_PLUGIN_FILE}" ]]; then
  echo "Error: main plugin file not found at ${MAIN_PLUGIN_FILE}" >&2
  exit 1
fi

# Extract version from plugin header (robust to leading "*" and CRLF)
VERSION="$(LC_ALL=C grep -m1 -Ei '^[[:space:]]*(\*|//)?[[:space:]]*Version:[[:space:]]*' "${MAIN_PLUGIN_FILE}" \
  | sed -E 's/^.*Version:[[:space:]]*([^[:space:]]+).*$/\1/' \
  | tr -d '\r')"

# Fallback extraction using sed across the whole file
if [[ -z "${VERSION}" ]]; then
  VERSION="$(LC_ALL=C sed -nE 's/^.*Version:[[:space:]]*([^[:space:]]+).*$/\1/p; q' "${MAIN_PLUGIN_FILE}" | tr -d '\r')"
fi
if [[ -z "${VERSION}" ]]; then
  DATE_FALLBACK="$(date +%Y%m%d%H%M%S)"
  echo "Warning: could not detect Version from plugin header. Using timestamp ${DATE_FALLBACK}." >&2
  VERSION="${DATE_FALLBACK}"
fi

ZIP_NAME="${PLUGIN_SLUG}-v${VERSION}.zip"

echo "Preparing build for ${PLUGIN_SLUG} v${VERSION}..."
rm -rf "${STAGING_DIR}"
mkdir -p "${STAGING_DIR}"
mkdir -p "${DIST_DIR}"

# rsync plugin files into staging, excluding development and VCS files
rsync -a "${PLUGIN_DIR}/" "${STAGING_DIR}/" \
  --delete \
  --exclude ".git/" \
  --exclude ".github/" \
  --exclude ".gitignore" \
  --exclude ".gitattributes" \
  --exclude ".DS_Store" \
  --exclude ".editorconfig" \
  --exclude ".idea/" \
  --exclude ".vscode/" \
  --exclude "node_modules/" \
  --exclude "vendor/bin/" \
  --exclude "tests/" \
  --exclude "test/" \
  --exclude "bin/" \
  --exclude "*.md" \
  --exclude "*.MD" \
  --exclude "composer.*" \
  --exclude "package*.json" \
  --exclude "pnpm-*.yaml" \
  --exclude "yarn.lock" \
  --exclude "pnpm-lock.yaml" \
  --exclude "webpack.*" \
  --exclude "vite.config.*" \
  --exclude "rollup.config.*" \
  --exclude ".eslintrc.*" \
  --exclude ".prettierrc*" \
  --exclude ".phpcs.xml*" \
  --exclude ".distignore"

# Create the zip so it contains the plugin folder at the top-level
(
  cd "${BUILD_DIR}"
  rm -f "${DIST_DIR}/${ZIP_NAME}"
  zip -r -q "${DIST_DIR}/${ZIP_NAME}" "${PLUGIN_SLUG}"
)

echo "Build complete: ${DIST_DIR}/${ZIP_NAME}"
echo "You can upload this zip in the WordPress Plugins > Add New screen."


