#!/usr/bin/env bash
# Applique les migrations SQL récentes + bootstrap admin.
# Usage (depuis la racine serveur/) :
#   bash tools/apply-recent-migrations.sh
#   bash tools/apply-recent-migrations.sh --dry-run

set -euo pipefail
cd "$(dirname "$0")/.."
exec php tools/apply-recent-migrations.php "$@"
