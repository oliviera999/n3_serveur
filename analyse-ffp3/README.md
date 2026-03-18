# Extrait FFP3 — à analyser

Ce dossier contient un **extrait des éléments utiles** de l’ancien dossier `serveur/ffp3/` pour analyse ultérieure.

- **Le reste** du contenu ffp3 (vendor, tests, doublons, etc.) se trouve dans **`serveur/archives/ffp3/`**.
- Les **outils PHP** de diagnostic (`check_tables_server.php`, `verify_environments.php`, `diagnostic_500_errors.php`, etc.) sont en **double** dans `serveur/tools/` ; les **versions de référence** (avec .env et vendor du serveur unifié) sont dans **`serveur/tools/`**.

## Contenu de cet extrait

- **Documentation** : `ENVIRONNEMENT_TEST.md`, `README.md`, `docs/` (ENDPOINTS_ESP32_SERVEUR, ERROR_ALERT_SERVICE, SYNCHRONISATION_BIDIRECTIONNELLE, index).
- **Scripts bin** : `deploy.sh`, `deploy_endpoints.ps1`, `deploy_diagnostics.sh`, `DEPLOY_NOW.sh`.
- **Scripts tools** (non présents dans serveur/tools/) : scripts .sh et .ps1 de test/diagnostic (test_post_data.sh, quick_diagnostic.sh, test_both_environments.sh, etc.).
- **Scripts** : `scripts/copy_photos_aquaponie.ps1`.
- **Référence** : `public/index.php` (stub de délégation vers le front controller principal).
- **Migrations** : scripts SQL de référence dans `migrations/`.

Le code applicatif actif du serveur (FFP3) est dans `serveur/src/`, `serveur/config/`, `serveur/templates/`, `serveur/public/` ; le point d’entrée est `serveur/public/index.php`.
