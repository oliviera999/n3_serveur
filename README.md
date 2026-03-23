# Serveur unifié n3 IoT

Backend PHP (Slim 4) pour [iot.olution.info](https://iot.olution.info) : collecte des données (msp1, n3pp, ffp3), contrôle des sorties, galeries photo.

- **`archives/site-initial/`** : ancienne version des fichiers serveur (archive). **Ne pas modifier** — conservé pour consultation uniquement (référence, comparaison, historique).

- **`archives/ffp3/`** : ancien sous-projet FFP3 (archive). Un extrait des fichiers utiles pour analyse est dans **`analyse-ffp3/`**. Le code actif est dans `src/`, `config/`, `templates/` ; les outils PHP de diagnostic sont dans **`tools/`**. Voir section « Scripts FFP3 » ci-dessous.

- **Point d’entrée** : `public/index.php` (front controller unique).
- **Documentation détaillée** : voir [archives/ffp3/README.md](archives/ffp3/README.md) pour l’architecture FFP3, ou [analyse-ffp3/README.md](analyse-ffp3/README.md) pour l’extrait à analyser.

## Test local rapide

Pour tester rapidement le routage Slim, les templates et les assets sans Apache, utiliser le serveur intégré PHP avec le dossier `public/` comme document root :

```powershell
php -c "C:\php\php.ini" -S 127.0.0.1:8082 -t "c:\IOT_n3\serveur\public" "c:\IOT_n3\serveur\public\index.php"
```

Vérifications utiles :

- Navigation : barre horizontale au-dessus de 980px de largeur ; en dessous, bouton menu (sandwich) et panneau latéral — styles et accessibilité (ARIA, focus) sont centralisés dans `public/assets/css/main.css`, `theme-variables.css` et `public/assets/js/main.js` ; les entrées optionnelles depuis la supervision utilisent `page-nav-toggles.js`.
- Accueil : `http://127.0.0.1:8082/`
- CSS principal : `http://127.0.0.1:8082/assets/css/main.css`
- Description aquaponie : `http://127.0.0.1:8082/aquaponie-description`
- Description MSP1 (météo) : `http://127.0.0.1:8082/meteo-description`
- Description N3PP (serre) : `http://127.0.0.1:8082/serre-description`
- Aquaponie : `http://127.0.0.1:8082/aquaponie`
- Aquaponie classique : `http://127.0.0.1:8082/aquaponie-alt`
- Données MSP1 (météo) : `http://127.0.0.1:8082/meteo`
- Données N3PP (serre) : `http://127.0.0.1:8082/serre`
- Contrôle galerie MSP1 : `http://127.0.0.1:8082/gallery/msp1/control`
- Contrôle galerie N3PP : `http://127.0.0.1:8082/gallery/n3pp/control`
- Contrôle galerie FFP3 : `http://127.0.0.1:8082/gallery/ffp3/control`

Prérequis locaux :

- `pdo_mysql` doit être activé dans `C:\php\php.ini`
- la commande PHP doit charger ce `php.ini`

Comportement local actuel :

- `pdo_mysql` reste nécessaire si l’on veut tester les vraies données MySQL
- sans base joignable, le serveur intégré active un fallback local pour `aquaponie`, `aquaponie-alt`, `msp1` et `n3pp`
- ce fallback rend les pages avec des séries vides et des valeurs neutres, ce qui permet de vérifier localement le HTML, Twig, les assets CSS/JS, les formulaires et le routage sans erreur `DB connection failed`
- l’accueil, `login`, les galeries , `aquaponie-description`, `meteo-description` et `serre-description` restent aussi testables localement

## Scripts de déploiement et de test (FFP3)

Le dossier **`archives/ffp3/`** contient l’archive de l’ancien sous-projet FFP3. Un **extrait utile** (scripts bin/tools, doc) est dans **`analyse-ffp3/`**. Le code actif du serveur est dans `src/`, `config/`, `templates/` ; le point d’entrée réel est `public/index.php`. Les **outils PHP** de diagnostic (vérification tables, environnements, etc.) sont dans **`tools/`** (versions de référence avec .env).

Scripts FFP3 (extrait dans **`analyse-ffp3/`** ou archive **`archives/ffp3/`**) :

- **`analyse-ffp3/bin/`** ou **`archives/ffp3/bin/`** : `deploy.sh`, `deploy_diagnostics.sh`, `deploy_endpoints.ps1`, etc.
- **`analyse-ffp3/tools/`** ou **`archives/ffp3/tools/`** : scripts .sh/.ps1 de test POST, diagnostic (quick_diagnostic.sh, test_post_data.sh, etc.).
- **`analyse-ffp3/scripts/`** : ex. copy_photos_aquaponie.ps1.

Pour les scripts PHP de diagnostic (tables, environnements), utiliser **`tools/`** à la racine du serveur.

## Maintenance du changelog (durable)

Le dépôt applique une stratégie "rolling window" :

- `CHANGELOG.md` reste court (entrées récentes) ;
- l'historique est archivé dans `docs/changelog/archive/` ;
- un garde-fou `pre-commit` peut bloquer les anomalies de changelog avant commit.

Commandes utiles depuis `serveur/` :

```powershell
# Vérification stricte (UTF-8, doublons, taille)
composer changelog:check

# Rotation des anciennes entrées vers l'archive
composer changelog:rotate
```

Installation du hook local :

```bash
bash bin/install-hook-pre-commit.sh
```

Documentation : `docs/changelog/README.md`

## Configuration serveur (URLs /ffp3/*)

Pour eviter des 404 sur les URLs du type `https://iot.olution.info/ffp3/`, `/ffp3/dashboard`, etc., toutes les requetes doivent etre acheminees vers `public/index.php`. Voir [docs/CONFIG_SERVEUR_FFP3_URLS.md](docs/CONFIG_SERVEUR_FFP3_URLS.md) pour Apache (AllowOverride All) et Nginx.

## Diagnostic / Logs

- **Cronlog production** : [https://iot.olution.info/public/cronlog.txt](https://iot.olution.info/public/cronlog.txt) — log applicatif (Monolog) des erreurs et exceptions. À consulter pour retrouver une **référence d’erreur** (ex. `bb3262da436c`) affichée à l’utilisateur en cas d’erreur 500.
- **Processus de debug** : voir [docs/DEBUG_ERREURS_SERVEUR.md](docs/DEBUG_ERREURS_SERVEUR.md) pour le diagnostic à partir d’une référence et le lien avec `ErrorHandlerMiddleware` / `ErrorAlertService`.
