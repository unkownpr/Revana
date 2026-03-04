#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8081}"

if ! command -v php >/dev/null 2>&1; then
  echo "Error: php command not found." >&2
  exit 1
fi

echo "Starting Devana on http://${HOST}:${PORT}"
exec php -S "${HOST}:${PORT}" -t "${ROOT_DIR}" "${ROOT_DIR}/index.php"
