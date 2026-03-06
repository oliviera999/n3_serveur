# Serveur unifié n3 IoT

Backend PHP (Slim 4) pour [iot.olution.info](https://iot.olution.info) : collecte des données (msp1, n3pp, ffp3), contrôle des sorties, galeries photo.

- **`site initial/`** : ancienne version des fichiers serveur (archive). **Ne pas modifier** — conservé pour consultation uniquement (référence, comparaison, historique).

- **Point d’entrée** : `public/index.php` (front controller unique).
- **Documentation détaillée** : voir [ffp3/README.md](ffp3/README.md) pour l’architecture, la configuration et les environnements PROD/TEST.

## Scripts de déploiement et de test (FFP3)

Les scripts de déploiement, diagnostic et test liés à FFP3 se trouvent dans **`ffp3/`** :

- **`ffp3/bin/`** : `deploy.sh`, `DEPLOY_NOW.sh` (à la racine ffp3), `deploy_diagnostics.sh`, etc.
- **`ffp3/tools/`** : tests POST, diagnostic (quick_diagnostic.sh, test_post_data.sh, etc.).
- **`ffp3/scripts/`** : utilitaires (ex. copy_photos_aquaponie.ps1).

**À exécuter depuis la racine de `ffp3/`** (répertoire contenant `composer.json`), sauf indication contraire dans le script ou dans `ffp3/bin/README.md`.
