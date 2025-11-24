#!/usr/bin/env bash
# File: bin/dev-down.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="${SCRIPT_DIR%/bin}"

cd "${PROJECT_ROOT}"

docker compose down -v

echo "Development environment stopped and volumes removed."


