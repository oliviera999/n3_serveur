#!/usr/bin/env bash
# Wrapper : délègue à PHP (Dotenv / PDO — même chargement .env que l'application).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
exec php "${ROOT}/tools/run-prod-prune-n3pp.php" "$@"
