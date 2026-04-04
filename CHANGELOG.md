# Changelog FFP3 Datas

Toutes les modifications notables de ce projet sont documentees dans ce fichier.
Le format est base sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
et ce projet adhere a [Semantic Versioning](https://semver.org/lang/fr/).

## Politique de maintenance

- Ce fichier reste volontairement court (fenetre recente).
- Les entrees anciennes sont archivees dans `docs/changelog/archive/`.
- Les garde-fous automatiques sont assures par `tools/changelog-maintenance.ps1`.
- Rotation recommandee : conserver les 40 dernieres entrees, taille cible <= 300KB.

## [5.0.283] - 2026-04-04

### Correctif - aquaponie : niveaux d’eau mm → cm et format décimal français
- **Résumé** : les colonnes `EauAquarium`, `EauReserve`, `EauPotager` sont stockées en **millimètres** ; la page aquaponie (et le bilan hydrique) les affichaient comme des cm sans conversion. Conversion ÷10 côté serveur (`Ffp3WaterLevelUnit`), même logique pour dashboard FFP3, API temps réel capteurs, `WaterBalanceService`, `TideAnalysisService`. Affichage avec **virgule** décimale (Twig `number_format`, JS `toLocaleString('fr-FR')`, `stats-updater.js`). Documentation `docs/ENDPOINTS_ESP32_SERVEUR.md`, tests `tests/Util/Ffp3WaterLevelUnitTest.php`.

---

## [5.0.282] - 2026-03-31

### Ajouté - tests d'intégration repositories + suites PHPUnit Unit / Integration
- **Résumé** : `IntegrationDbTestCase` (`#[BackupGlobals(false)]`, PDO, seuil snapshot, `TableConfig` prod) ; `SensorRepositoriesSnapshotIntegrationTest` (SensorReadRepository, MspSensorRepository, N3ppSensorRepository, BoardRepository, fenêtres 10 min ; comparaisons dates en `strcmp` format SQL). `phpunit.xml` : suites **Unit** et **Integration** ; scripts Composer `test:unit`, `test:integration`. Documentation `README.md` et skill PHPUnit.

### Correctif - `SensorReadRepository::getLastReadings` (ORDER BY + LIMIT sous MySQL)
- **Résumé** : le `LIMIT` lié (`:limit`) pouvait retourner des lignes dans un ordre incorrect selon le driver PDO MySQL ; passage à une limite entière dans la requête après validation de la table (`TableValidator`) et plafond 10 000.
- Fichiers : `src/Repository/SensorReadRepository.php`, `tests/Integration/`, `phpunit.xml`, `composer.json`, `README.md`, `.cursor/skills/tests-phpunit-serveur/SKILL.md`, `VERSION`

---

## [5.0.281] - 2026-03-31

### Modifié - footer galeries : version firmware uploadphotosserver
- **Résumé** : les pages timelapse et grille admin (`/gallery/{slug}`, `/admin/gallery/{slug}`) passent la même version que la page contrôle caméra (GPIO 100, POST `post-uploadphotoserver-version.php`) au pied de page unifié (`_footer.twig`). Page d’index des galeries : pas de badge firmware unique (trois appareils). `GalleryControlController` normalise `firmware_version` en chaîne vide si absente pour le `footer_config` Twig.
- Fichiers : `src/Controller/Gallery/GalleryViewController.php`, `GalleryControlController.php`, `config/dependencies.php`, `VERSION`

---

## [5.0.280] - 2026-03-31

### Ajouté - import dump production vers BDD Docker locale (tests etendus)
- **Résumé** : script `tools/import-mysql-dump-to-local-docker.ps1` (import dans `iot_n3_import_staging` puis synchro vers `iot_n3_local`) ; SQL `docker/mysql/sync-import-staging-to-local.sql` avec mappage des colonnes (Boards, ffp3Data/post_id, Heartbeat timestamp → reading_time, sorties board en varchar, etc.). Tests `tests/Integration/RealDatasetDockerDbTest.php` ; documentation `README.md`, regle Cursor `serveur-validation-locale-docker.mdc`, skill PHPUnit. Correctif whitelist JS : `n3-stock-chart-bootstrap.js` dans `config/routes_config.php`.
- Fichiers : outils et SQL ci-dessus, `tests/Integration/`, `config/routes_config.php`, `README.md`, `VERSION`

---

## [5.0.279] - 2026-03-30

### Modifié - remplissage sous courbe (areaspline) MSP1/N3PP aligné aquaponie
- **Résumé** : ajout de `n3AreaGradientFill` dans `chart-helpers.js` ; séries continues en `areaspline` avec dégradé vertical sur météo et serre (températures, humidités, luminosité, humidité sol, cycles avec opacité plus légère) ; `plotOptions.areaspline` par défaut dans `n3-stock-chart-bootstrap.js` (`connectNulls: false`, marqueurs désactivés). Les séries en colonnes et la tendance linéaire N3PP restent inchangées.
- Fichiers modifiés : `public/assets/js/chart-helpers.js`, `public/assets/js/n3-stock-chart-bootstrap.js`, `templates/msp1_data.twig`, `templates/n3pp_data.twig`, `docs/AUDIT_GRAPHIQUES_HIGHCHARTS.md`, `VERSION`

---

## [5.0.278] - 2026-03-30

### Modifié - unification affichage Highcharts MSP1/N3PP sur modèle aquaponie
- **Résumé** : ajout d’un bootstrap partagé (`n3-stock-chart-bootstrap.js`) pour créer les graphiques uniquement quand les conteneurs sont réellement dimensionnés (retry borné + reflow AOS), factorisation de l’initialisation des charts MSP1/N3PP, alignement du layout Stock (`navigator.xAxis.ordinal = false`, légende dense), et fiabilisation du live update via `ChartUpdaterGeneric` (dédoublonnage timestamp, insertion triée hors ordre, `redraw(false)`).
- Fichiers modifiés : `public/assets/js/chart-updater-generic.js`, `public/assets/js/n3-stock-chart-layout.js`, `public/assets/js/n3-stock-chart-bootstrap.js`, `templates/msp1_data.twig`, `templates/n3pp_data.twig`, `templates/data_page.twig`, `docs/AUDIT_GRAPHIQUES_HIGHCHARTS.md`, `VERSION`

---

## [5.0.277] - 2026-03-30

### Modifié - seuil de rupture Highcharts (gap) porté à 6 h
- **Résumé** : `gapSize` global (`gapUnit: 'value'`) passé de 1 h à **6 h** (21600000 ms) pour ne couper les courbes qu’après une absence de relevés plus longue.
- Fichiers modifiés : `public/assets/js/highcharts-defaults.js`, `VERSION`

---
## [5.0.276] - 2026-03-30

### Correctif - courbes Highcharts invisibles (aquaponie, séries temps réel)
- **Résumé** : le `gapSize` global avec `gapUnit: 'relative'` fragmentait les séries dès qu’existait une paire de timestamps très rapprochés ; les segments disparaissaient (marqueurs désactivés) tout en restant actifs au survol. Passage à `gapUnit: 'value'` avec un seuil de 1 h (3600000 ms) sur l’axe datetime pour ne couper la courbe qu’après une vraie coupure de relevés.
- Fichiers modifiés : `public/assets/js/highcharts-defaults.js`, `VERSION`

---
## [5.0.275] - 2026-03-30

### Correctif - smoke test local et diagnostic environnements sous Docker
- **Résumé** : délai HTTP du smoke test paramétrable (`-TimeoutSec`, défaut 60 s) pour limiter les timeouts sur pages lourdes ; `verify_environments.php` utilise `Database::getConnection()` et le `.env` (`DB_HOST=db` en stack Docker) au lieu d’identifiants MySQL codés en dur.
- Fichiers modifiés : `tools/local-smoke-test.ps1`, `tools/verify_environments.php`, `README.md`, `VERSION`

---
## [5.0.274] - 2026-03-30

### Modifié - unification du layout Highcharts et robustesse thème système
- **Résumé** : reconstruction de `theme-toggle.js` et `aquaponie-chart-layout.js` après corruption locale, ajout d'un module partagé `n3-stock-chart-layout.js` (hauteurs/options/load/resize) réutilisé par MSP1/N3PP et composé côté aquaponie, avec mise à jour du thème Highcharts lors d'un changement de préférence système sans thème stocké.
- Fichiers modifiés : `public/assets/js/theme-toggle.js`, `public/assets/js/aquaponie-chart-layout.js`, `public/assets/js/n3-stock-chart-layout.js`, `templates/msp1_data.twig`, `templates/n3pp_data.twig`, `templates/aquaponie.twig`, `templates/aquaponie_alt.twig`, `config/routes_config.php`, `public/assets/css/common-data.css`, `VERSION`

## [5.0.273] - 2026-03-30

### Correctif - cohérence VERSION et CHANGELOG après régression
- **Résumé** : réintégration de l’entrée **[5.0.272]** (ordre `highcharts-theme.js` / `head_scripts` dans `layout.twig`) supprimée par erreur lors d’un commit ultérieur ; incrément **5.0.273** pour reprendre la suite sémantique sans réécrire l’historique Git.
- Fichiers modifiés : `CHANGELOG.md`, `VERSION`

---
## [5.0.272] - 2026-03-30

### Correctif - ordre de chargement Highcharts theme/defaults (MSP1/N3PP)
- **Résumé** : dans `layout.twig`, `highcharts-theme.js` est chargé avant `{% block head_scripts %}` afin que `n3HighchartsBuildThemeOptions()` soit disponible lorsque `highcharts-defaults.js` appelle `Highcharts.setOptions()`. Cela aligne l’initialisation du thème au premier rendu et corrige les incohérences visuelles observées sur le graphique « Paramètres physiques » du potager.
- Fichiers modifiés : `templates/layout.twig`, `VERSION`

---
## [5.0.271] - 2026-03-30

### Modifié - alignement chargement Highcharts MSP1/N3PP sur Aquaponie
- **Résumé** : déplacement de `highcharts-defaults.js` et `chart-helpers.js` de `{% block scripts %}` vers `{% block head_scripts %}` dans les vues MSP1 et N3PP, pour harmoniser l’ordre de chargement avec Aquaponie et réduire le risque de régression lié à l’ordre des scripts inline.
- Fichiers modifiés : `templates/msp1_data.twig`, `templates/n3pp_data.twig`, `VERSION`

---
## [5.0.270] - 2026-03-30

### Correctif - favicon n3 orange versionné et cache PHPUnit ignoré
- **Résumé** : ajout du fichier `public/assets/icons/favicon-n3-orange.png` référencé par les layouts et `routes_config.php` ; ajout de `.phpunit.cache/` au `.gitignore` (PHPUnit 10).
- Fichiers modifiés : `.gitignore`, `public/assets/icons/favicon-n3-orange.png`, `VERSION`

---
## [5.0.269] - 2026-03-30

### Correctif - graphique aquaponie : corde droite sur les niveaux d'eau
- **Résumé** : `afterSetExtremes` était invoqué au premier redraw Highcharts avec `e.trigger` non défini ; le `setData` des tendances utilisait des indices de séries fixes (vue alt sans contrôle du nom), ce qui pouvait écraser les séries `areaspline` avec les points de régression linéaire — ligne droite du premier au dernier point. Ignorer ces appels sans `trigger`, ne mettre à jour que les séries `type: 'line'` identifiées par le libellé, `setData` sans animation, et `connectNulls: false` sur les aires.
- Fichiers modifiés : `templates/aquaponie.twig`, `templates/aquaponie_alt.twig`, `VERSION`

---
## [5.0.268] - 2026-03-28

### Modifié - Ajout stack Docker locale et fiabilisation smoke/PHPUnit
- **Résumé** : Ajout stack Docker locale et fiabilisation smoke/PHPUnit.

---
## [5.0.267] - 2026-03-28

### Ajout - deploiement local complet Docker (app + MySQL + phpMyAdmin)
- **Resume** : ajout d'une stack locale 100% Docker pour tester le serveur de bout en bout (pages publiques, controle/auth, endpoints API, upload galerie), avec bootstrap BDD from-scratch + seed minimal et scripts PowerShell de pilotage/smoke test (`local-docker.ps1`, `local-smoke-test.ps1`).
- Fichiers modifies : `docker-compose.local.yml`, `docker/php/Dockerfile`, `docker/php/start.sh`, `docker/php/php.ini`, `docker/mysql/init/00-schema.sql`, `docker/mysql/init/10-seed.sql`, `.env.docker.example`, `tools/local-docker.ps1`, `tools/local-smoke-test.ps1`, `README.md`, `VERSION`

---

## [5.0.266] - 2026-03-26

### Correctif - enregistrement fiable des paramètres meteo-control avec authentification token
- **Résumé** : propagation automatique du paramètre `token` de l'URL vers les requêtes AJAX de contrôle (`toggle` et `parameters`) pour éviter les erreurs d'authentification lors de la sauvegarde des champs modifiables sur `meteo-control`.
- Fichiers modifies : `public/assets/js/control-auto-save.js`, `public/assets/js/control-actions.js`, `templates/partials/_control_init_js.twig`, `VERSION`

---
## [5.0.265] - 2026-03-26

### Modifié - favicon n3 orange harmonisé avec le thème
- **Résumé** : ajout d'un favicon `n3` orange (`#FF6300`) dérivé du logo et raccordé aux layouts principaux pour appliquer l'icône sur l'ensemble du site.
- Fichiers modifies : `public/assets/icons/favicon-n3-orange.png`, `templates/layout.twig`, `templates/layout_base.twig`, `config/routes_config.php`, `VERSION`

---
## [5.0.264] - 2026-03-26

### Correctif - highcharts locaux + correctifs aquaponie et audit navigateur
- **Résumé** : highcharts locaux + correctifs aquaponie et audit navigateur.

---
## [5.0.263] - 2026-03-24

### Modifié - refacto dark mode datas-control et convergence theme Highcharts
- **Résumé** : refacto dark mode datas-control et convergence theme Highcharts.

---
## [5.0.262] - 2026-03-24

### Modifié - refacto dark mode datas/control avec référence datas
- **Résumé** : simplification de la cascade dark mode en limitant les surcharges globales de `realtime-styles.css` sur les pages de contrôle, déduplication et harmonisation visuelle des composants partagés (`context badges`, `quick actions`, `warnings`, titres) dans `control-styles.css`, et convergence du thème Highcharts sur les tokens CSS (`theme-variables.css`) pour un rendu light/dark plus cohérent.
- Fichiers modifies : `public/assets/css/realtime-styles.css`, `public/assets/css/control-styles.css`, `public/assets/js/highcharts-theme.js`, `VERSION`

---
## [5.0.261] - 2026-03-24

### Modifié - unification footer_config pages de controle
- **Résumé** : unification footer_config pages de controle.

---
## [5.0.260] - 2026-03-24

### Correctif - correctif Highcharts N3PP: alignement des series live avec la tendance
- **Résumé** : correctif Highcharts N3PP: alignement des series live avec la tendance.

---
## [5.0.259] - 2026-03-24

### Correctif - corrige l enregistrement du service worker et supprime le warning highcharts #15
- **Résumé** : corrige l enregistrement du service worker et supprime le warning highcharts #15.

---
## [5.0.258] - 2026-03-24

### Correctif - tuiles statut et derniere reception restaurees sur MSP/N3PP
- **Resume** : le bloc commun `Etat du systeme` des pages de donnees MSP1/N3PP affiche de nouveau les tuiles `Statut` et `Derniere reception` (avec les memes identifiants et le meme markup que les autres pages), ce qui re-synchronise l'UI avec les mises a jour live de `realtime-updater.js`.
- Fichiers modifies : `templates/partials/_filter_health_row.twig`, `public/assets/js/realtime-updater.js`, `VERSION`

---

## [5.0.257] - 2026-03-24

### Correctif - coherence mode sombre pages de controle serveur
- **Résumé** : coherence mode sombre pages de controle serveur.

---
## [5.0.256] - 2026-03-24

### Correctif - coherence mode sombre sur les pages de controle serveur
- **Resume** : harmonisation des surfaces et bordures des cartes de controle via les variables de theme (`control-styles.css`), correction du fond `control-wrapper` avec le token `--wrapper-control-bg`, et ajout des variantes `[data-theme="dark"]` manquantes pour les journaux de polling (`realtime-styles.css`) afin d'eviter les fonds clairs residuels en mode sombre.
- Fichiers modifies : `public/assets/css/control-styles.css`, `public/assets/css/realtime-styles.css`, `VERSION`

## [5.0.255] - 2026-03-24

### Correctif - nettoie les doublons du changelog et fiabilise la publication
- **Résumé** : nettoie les doublons du changelog et fiabilise la publication.

---
## [5.0.254] - 2026-03-24

### Modifie - mode servo auto/manuel MSP (serveur + page controle)
- **Resume** : ajout d'un parametre persistant `ServoModeAuto` (GPIO virtuel `111`) pour la station MSP, avec switch Auto/Manuel sur la page de controle, validation serveur des angles servos (`ServoGD` 1-179, `ServoHB` 40-145), et normalisation stricte des valeurs booleennes (`WakeUp`, `ServoModeAuto`).
- Fichiers modifies : `src/Repository/MspOutputRepository.php`, `src/Controller/Msp/MspOutputController.php`, `src/Controller/AbstractOutputController.php`, `templates/msp1_control.twig`, `templates/partials/_control_init_js.twig`, `public/assets/js/control-values-updater.js`, `VERSION`

## [5.0.252] - 2026-03-24

### Correctif - reset MSP/N3PP en one-shot (anti-boucle)
- **Resume** : acquittement automatique des commandes one-shot (`GPIO 108/109/110`) lors de la lecture firmware (`outputs_state`) pour les flux legacy MSP1/N3PP. Le firmware recoit la commande active puis la valeur est remise a `0` cote serveur afin d'eviter un redemarrage/redeclenchement en boucle au cycle suivant.
- Fichiers modifies : `src/Repository/AbstractOutputRepository.php`, `VERSION`

## [5.0.251] - 2026-03-24

### Modifie - footer unifie sur les pages de controle
- **Resume** : le template de base des pages de controle MSP1/N3PP reutilise maintenant le partial standard `partials/_footer.twig`, ce qui aligne le pied de page avec la page d'accueil et les autres pages du site.
- Fichiers modifies : `templates/partials/_control_base.twig`, `VERSION`

## [5.0.250] - 2026-03-24

### Correctif - reset mode fiabilise + toggle outputs en POST
- **Resume** : fiabilisation du reset distant ESP32 (GPIO 110) en evitant l'ecrasement immediat par les POST firmware grace a une fenetre de priorite web de 20s cote serveur, et durcissement du controle web en migrant l'action `toggle` de `GET` vers `POST` (routes Slim + front `control-actions.js`).
- Fichiers modifies : `src/Repository/OutputRepository.php`, `config/routes_helpers.php`, `config/routes_ffp3.php`, `src/Controller/AbstractOutputController.php`, `public/assets/js/control-actions.js`, `VERSION`

## [5.0.249] - 2026-03-23

### Correctif - suppression des fonds blancs en mode sombre (galerie + controle)
- **Resume** : harmonisation du fond principal `#main` pour les pages galeries et controle camera avec la variable de theme (`--bg-main`), afin d'eviter les fonds blancs residuels en mode sombre.
- Fichiers modifies : `public/assets/css/gallery-styles.css`, `public/assets/css/control-styles.css`

---

## [5.0.253] - 2026-03-24

### Correctif - corrige les fonds blancs en mode sombre sur les pages de controle
- **Résumé** : corrige les fonds blancs en mode sombre sur les pages de controle.

---
## [5.0.248] - 2026-03-23

### Modifie - pied de page standard sur les pages controle camera
- **Resume** : les pages `gallery/{slug}/control` utilisent maintenant le pied de page standard (`partials/_footer.twig`) comme le reste du site, en retirant le footer specifique integre au contenu pour garder un affichage coherent.
- Fichier modifie : `templates/gallery_control.twig`

---

## [5.0.247] - 2026-03-23

### Correctif - coherence complete en mode sombre pour controle camera
- **Resume** : finition dark mode de `gallery/{slug}/control` avec contraste homogene des champs, placeholders, etats de sauvegarde (`saving/success/error`) et surbrillance des champs en cours d'enregistrement, pour un rendu visuel coherent avec les autres pages de controle.
- Fichier modifie : `public/assets/css/control-styles.css`

---

## [5.0.246] - 2026-03-23

### Modifie - harmonisation visuelle des blocs du controle camera
- **Resume** : la page `gallery/{slug}/control` aligne maintenant ses blocs sur les autres pages de controle (cartes de section, panneaux de parametres, champs, hints et etats de sauvegarde), avec adaptations dediees en mode clair/sombre. Le markup des interrupteurs a aussi ete nettoye pour eviter les labels imbriques.
- Fichiers modifies : `templates/gallery_control.twig`, `public/assets/css/control-styles.css`

---

## [5.0.245] - 2026-03-23

### Modifie - tuiles supervision pour controle uploadphotosserver
- **Resume** : ajout de trois tuiles de navigation dans `supervision` vers les pages de controle camera (`/gallery/msp1/control`, `/gallery/n3pp/control`, `/gallery/ffp3/control`) afin d'acceder rapidement aux parametres uploadphotosserver depuis le panneau central.
- Fichier modifie : `templates/supervision.twig`

---

## [5.0.244] - 2026-03-23

### Modifie - controle distant uploadphotosserver (msp1/n3pp/ffp3)
- **Resume** : ajout d'une couche de controle distante pour les cameras ESP32-CAM avec pages de pilotage par galerie, endpoints REST + aliases legacy `.php`, et mapping BDD dedie (`UploadPhoto1/2/3Outputs`, boards 5/6/7). Le firmware recupere les champs distants au reveil (`mail`, `mailNotif`, `forceWakeUp`, `sleepTime`, `resetMode`) et poste sa version firmware.
- Fichiers modifies : `config/routes_gallery.php`, `config/dependencies.php`, `config/routes_config.php`, `src/Controller/Gallery/GalleryControlController.php`, `src/Repository/GalleryControlRepository.php`, `templates/gallery_control.twig`

---

## [5.0.243] - 2026-03-23

### Modifie - harmonisation libelles periode personnalisee
- **Resume** : uniformisation des libelles du partiel commun de filtrage avec la casse et les accents standards (`Début`, `Fin`) pour rester coherent avec les pages Aquaponie.
- Fichier modifie : `templates/partials/_filter_health_row.twig`

---

## [5.0.242] - 2026-03-23

### Correctif - centrage du bloc de filtrage (debut/fin + actions)
- **Resume** : les champs `debut`/`fin` et les boutons `Afficher les mesures`/`Telecharger CSV` sont maintenant centres dans le bloc de filtrage, avec un comportement coherent sur desktop et mobile.
- Fichiers modifies : `public/assets/css/common-data.css`, `public/assets/css/aquaponie.css`, `public/assets/css/realtime-styles.css`

---

## [5.0.241] - 2026-03-21

### Correctif - Badge LIVE en vert (plus d’override orange)
- **CSS** : suppression de la règle qui forçait `#live-badge.badge-success` en orange ; le badge LIVE reprend le vert standard `#27ae60` (`.badge-success`)
- Fichier modifié : `public/assets/css/realtime-styles.css`

### Modifié - Aquaponie et vues données communes
- **Aquaponie** : grille `.datetime-grid` (colonnes `minmax`), libellés « Début » / « Fin » ; alignements mineurs sur `aquaponie_alt`, `tide_stats`, `common-data.css`.

---

## [5.0.240] - 2026-03-21

### Modifie - deploiement OTA ESP32-CAM MSP1 (uploadphotosserver 2.19, verification a chaque boot)
- **Resume** : publication OTA de la cible `msp1` du firmware `uploadphotosserver` en version `2.19` (cadence OTA firmware ajustee a chaque boot via `OTA_CHECK_EVERY_N_BOOTS=1`, binaire OTA regenere, metadata msp1 et `sha256` mis a jour).

---

## [5.0.239] - 2026-03-21

### Modifie - deploiement OTA ESP32-CAM MSP1 (uploadphotosserver 2.18)
- **Resume** : publication OTA de la cible `msp1` du firmware `uploadphotosserver` en version `2.18` (binaire `ota/cam/msp1/firmware.bin` regénéré, `sha256` et `version` msp1 mis à jour dans `ota/cam/metadata.json`).

---

## [5.0.238] - 2026-03-21

### Modifie - deploiement OTA ESP32-CAM MSP1 (uploadphotosserver 2.14)
- **Resume** : publication OTA de la cible `msp1` du firmware `uploadphotosserver` en version `2.14` (mise a jour de `ota/cam/msp1/firmware.bin` et du `sha256` associe dans `ota/cam/metadata.json`).

---

## [5.0.237] - 2026-03-21

### Correctif - agencement Highcharts aquaponie (timeline / légende)
- **Résumé** : harmonisation avec potager (N3PP) et élevage (MSP1) — hauteurs 300–440 px selon breakpoint, espacements réduits, légende compacte (`maxHeight`), navigator avec `margin` rapproché, suppression des `responsive.rules` qui imposaient des hauteurs contradictoires avec `setSize`. Nouveau module [`public/assets/js/aquaponie-chart-layout.js`](public/assets/js/aquaponie-chart-layout.js) ; chargement de `highcharts-defaults.js` + `chart-helpers.js` sur `aquaponie.twig` et `aquaponie_alt.twig`. CSS : `aquaponie.css` (wrapper) et `common-data.css` (min-height mobile sur `#chart-stock-area-*`).

---

## [5.0.236] - 2026-03-21

### Correctif - icone menu sandwich identique smartphone / laptop zoome
- **Resume** : harmonisation de la police d'icones du bouton menu replie (`#navPanelToggle`) et du bouton fermer (`#navPanel .close`) avec `Font Awesome 6 Free` (fallback `Font Awesome 5 Free`) dans `main.css`. Le glyphe burger (`\\f0c9`) reste identique quel que soit l'appareil.

---

## [5.0.235] - 2026-03-21

### Modifie - menu sandwich unifie smartphone / laptop zoom (a11y + resize)
- **Resume** : retour du focus sur le bouton menu apres toute fermeture (overlay, lien, Esc, swipe, bouton fermer) via suivi de `is-navPanel-visible` ; piège à focus Tab dans `#navPanel` ; fermeture automatique du panneau au passage en vue desktop ; `util.js` appelle `_hide` pour les liens `#navPanel` et expose un hook optionnel `onHide`. `page-nav-toggles.js` re-injecte les liens dynamiques après resize/orientation. Variable `--nav-sandwich-clearance` pour aligner le badge LIVE sur la zone du bouton menu.

---

## [5.0.234] - 2026-03-21

### Modifie - accueil : exposant n³ dans le titre hero
- **Resume** : le h1 du hero utilise `n<sup>3</sup>` comme sur le reste du site (sous-titre, liens).

---

## [5.0.233] - 2026-03-21

### Modifie - accueil : suppression du section-header duplique « Internet des objets »
- **Resume** : retrait du bloc `.section-header` (icone Wi-Fi + h2) sous le hero sur `home.twig`, le titre etant deja porte par le h1 du hero.

---

## [5.0.232] - 2026-03-21

### Modifie - alignement visuel global (fond, logo IOT, footer) sur accueil/login
- **Resume** : `home.twig` et `login.twig` utilisent de nouveau les styles globaux (`realtime-styles.css`) afin d'aligner l'image de fond (`#page-bg`), le header/logo `IOT` et le footer avec les pages data (aquaponie, meteo, serre). Suppression d'une surcharge locale de `login-styles.css` sur `#header` qui decalait le logo par rapport au layout commun.

---

## [5.0.231] - 2026-03-21

### Modifie - homogeneisation navigation (desktop, mobile, dark mode, a11y)
- **Resume** : regroupement des surcharges dark mode header/`#nav` depuis `realtime-styles.css` vers `main.css` (tokens `theme-variables.css`). Bordures et zone theme du panneau mobile alignees sur les variables. Menu sandwich : `MutationObserver` pour synchroniser `aria-expanded` / `aria-label` sur toute fermeture (clic hors panneau, Échap, swipe, lien), focus sur le premier lien utile a l’ouverture ; `nav` mobile identifiable (`#navPanelNav`). `page-nav-toggles.js` cible aussi la liste deplacée dans le panneau (correctif mobile). Documentation : `README.md`.

---

## [5.0.230] - 2026-03-21

### Correctif - icone en double (analyses manuelles eleves, aquaponie)
- **Resume** : le bandeau d'information « Analyses manuelles realisees par les eleves » affichait deux icones (toque + groupe). Conservation de la seule icone du bandeau (`fa-graduation-cap`), suppression de `fa-users` dans le titre (`aquaponie.twig`, `aquaponie_alt.twig`).

---

## [5.0.229] - 2026-03-21

### Modifie - taille des titres pages donnees alignee sur l'accueil
- **Resume** : augmentation de la taille du titre hero des pages donnees (`.hero-data-title`) pour l'aligner visuellement avec la page d'accueil, tout en conservant une taille fluide responsive (`clamp`) et des retours a la ligne pour eviter tout debordement sur mobile.

---

## [5.0.228] - 2026-03-21

### Modifie - titre accueil remplace et passe en orange
- **Resume** : sur la page d'accueil, le titre principal `N3 IoT Datas` est remplace par `L'internet des objets a n3` et stylise en orange (`#FF6300`) pour correspondre a la charte visuelle demandee.

---

## [5.0.227] - 2026-03-21

### Modifie - orange titres et badges LIVE (reference supervision)
- **Resume** : harmonisation sur l’orange supervision (`#FF6300` / `--accent-secondary` en clair, `fb923c` en sombre) pour les titres hero des pages donnees, le badge LIVE flottant (`#live-badge` en ligne), les pastilles mode LIVE (`mode-badge-live`) et les titres de section de la page supervision. Les titres de section supervision en clair utilisent explicitement la meme teinte que `header.major` au lieu du vert `#008B74`.

---

## [5.0.226] - 2026-03-21

### Correctif - double filet sous la synthese des mesures (pages donnees)
- **Resume** : suppression du `<hr />` en fin de bloc hero (`_hero_data.twig`) qui doublonnait avec le filet de la premiere section (`.section-header`). Un seul separateur visuel reste sous la ligne de synthese.

---

## [5.0.225] - 2026-03-20

### Correctif - affichage Highcharts mobile (graphiques tasses / blancs avant timeline)
- **Resume** : adaptation responsive des graphiques Stock MSP1/N3PP avec hauteurs dynamiques selon la largeur ecran, reduction des espacements et du navigator/scrollbar en mobile, puis recalcul des tailles au `resize`/`orientationchange`. Ajout d'un filet CSS mobile pour limiter les `min-height` trop eleves des conteneurs, afin d'eviter les zones blanches excessives sous les courbes.

---

## [5.0.224] - 2026-03-20

### Correctif - debordements residuels dans "Filtrage des donnees" et "Etat du systeme" (mobile)
- **Resume** : durcissement final des blocs internes pour mobile avec `box-sizing: border-box`, retours a la ligne forces sur titres/valeurs/stats, et contraintes `max-width: 100%` sur controles live, selecteurs et boutons. Correction des cas ou certains elements depassaient encore visuellement de leur carte.

---

## [5.0.223] - 2026-03-20

### Correctif - titres des pages donnees adaptes au mobile
- **Resume** : ajustement du hero des pages donnees (`.hero-data-title`) avec une taille fluide (`clamp`) et des retours a la ligne controles pour eviter les titres trop gros/coupes sur mobile (cas observe sur aquaponie). Les icones du titre sont harmonisees et les textes de sous-titre/synthese sont proteges contre les debordements.

---

## [5.0.222] - 2026-03-20

### Correctif - bouton hamburger mobile uniforme (icone blanche, sans texte, centree)
- **Resume** : suppression du texte "Menu" dans `#navPanelToggle`, forçage de l'icone blanche en permanence (etat normal, `alt`, hover et mode sombre), et centrage vertical/horizontal strict de l'icone dans une zone tactile fixe 44x44 pour un rendu stable sur toutes les pages mobiles.

---

## [5.0.221] - 2026-03-20

### Correctif - suppression des debordements a droite sur mobile (filtrage + etat systeme)
- **Resume** : durcissement responsive des blocs `filter-health-row`, `filter-section` et `system-health-panel` pour eviter les depassements horizontaux sur mobile. Le bloc "Periode analysee" est passe en structure verticale avec retours a la ligne (`period-info-*`), les controles live sont contraints (`min-width: 0`, `max-width: 100%`), et les boutons d'action (`Afficher les mesures`, `Telecharger CSV`) passent en pile sur petits ecrans (pages donnees + aquaponie) pour supprimer les sorties d'ecran a droite.

---

## [5.0.220] - 2026-03-20

### Correctif - bouton menu mobile cliquable et style unifie
- **Resume** : correction de l'ouverture/fermeture du panneau mobile via `#navPanelToggle` (gestion explicite du toggle avec blocage de propagation) pour supprimer les cas de bouton sandwich non cliquable. Unification du style du bouton hamburger dans `main.css` (zone tactile >= 44px, focus, variantes dark/light) et suppression des surcharges concurrentes dans `realtime-styles.css` afin d'obtenir le meme rendu sur toutes les pages.

---

## [5.0.219] - 2026-03-20

### Correctif - coherence theme clair/sombre et fiabilisation menu mobile
- **Resume** : correction de la gestion de theme pour appliquer explicitement `data-theme=\"light\"` (au lieu de supprimer l'attribut), ce qui elimine les melanges clair/sombre quand la preference systeme est sombre. Ajustement des selecteurs light dans `realtime-styles.css` (`html:not([data-theme=\"dark\"])`) pour eviter des couleurs incoherentes. Renforcement de la cliquabilite du bouton menu mobile (`#navPanelToggle`) via `pointer-events` et `touch-action`, et compatibilite etendue de l'ecoute des changements de theme systeme (`addEventListener` / `addListener`).

---

## [5.0.218] - 2026-03-20

### Correctif - chargement conditionnel des assets sur pages d'entree
- **Resume** : ajout de drapeaux Twig dans `layout.twig` pour charger conditionnellement `realtime-styles.css`, `highcharts-theme.js` et les scripts d'amelioration (`page-nav-toggles`, `scroll-progress`, `scroll-reveal`, `back-to-top`). Activation du mode allege sur `/` et `/login` via `home.twig` et `login.twig`, sans impacter la nav principale.

---

## [5.0.217] - 2026-03-20

### Correctif - responsive mobile/laptop sur layouts, tableaux et timelapse
- **Resume** : suppression du blocage de zoom mobile dans `layout_base.twig`, correction du fond fixe (`100vw` vers `100%`) pour limiter les debordements horizontaux, ajout d'un overflow horizontal tactile sur les tableaux `modern-table` (dashboard), extraction des styles inline du template `gallery_timelapse.twig` vers des classes CSS dediees, et alignement des media queries modulees sur `736px` (home/gallery/common-data/realtime/control) pour reduire l'incoherence 736/768.

---

## [5.0.215] - 2026-03-20

### Correctif - application des priorites audit UI accueil/login/navigation
- **Resume** : correction des classes dupliquees sur les CTA de l'accueil, harmonisation des liens externes, ajout de focus visibles clavier sur la nav principale et le formulaire login, suppression de styles inline login, simplification de la route Aquaponie dans la nav, pause/reprise du polling live selon la visibilite onglet, et activation de la fermeture clavier (`Escape`) du panneau de navigation mobile.

---

## [5.0.216] - 2026-03-20

### Correctif - application priorites audit UI accueil login navigation
- **Résumé** : application priorites audit UI accueil login navigation.

---
## [5.0.214] - 2026-03-20

### Correctif - timelapse sans frames grises sur images manquantes
- **Resume** : les images introuvables (supprimees ou deplacees en corbeille) sont retirees de la sequence du timelapse au chargement, en lecture et en navigation manuelle. Les frames de fallback grises ne sont plus affichees.

---

## [5.0.211] - 2026-03-20

## [5.0.212] - 2026-03-20

### Corrige - authentification requise sur les uploads photo ESP32-CAM
- **Resume** : durcissement de `GalleryUploadController` avec verification obligatoire du header `X-Api-Key` contre `API_KEY` serveur, en mode fail-closed. Les endpoints `/msp1gallery/upload.php`, `/n3ppgallery/upload.php` et `/ffp3/ffp3gallery/upload.php` retournent desormais `401` si la cle est absente/invalide.

---

## [5.0.213] - 2026-03-20

### Correctif - Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos
- **Résumé** : Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos.

---
## [5.0.210] - 2026-03-20

### Modifie - Factorisation et unification du code serveur
- **Resume** : montee des methodes dupliquees `updateByGpio` et `getOutputByGpioAndBoard` dans `AbstractOutputRepository`. Ajout de `normalizeOutputState()` dans `AbstractOutputController`. Extraction de 3 partials Twig communs (`_filter_health_row.twig`, `_set_period_js.twig`, `_control_init_js.twig`) pour supprimer les doublons entre pages MSP1/N3PP. Creation de `GalleryConfig` centralisant slugs, labels, env keys et repertoires des galeries. Nettoyage des imports inutilises. Aucun changement d'URL, de contrat API, ni de rendu visuel.

---

## [5.0.209] - 2026-03-20

### Modifie - Audit complet du mode sombre
- **Resume** : migration CSS vers variables semantiques, dark mode complet pour main.css, module-description-styles.css, aquaponie.css, supervision, timelapse, control, home, common-data. Creation de chartjs-theme.js pour le theming Chart.js. Remplacement des couleurs en dur et background:white par des variables. Unification des variables locales avec les variables globales. Ajout fallback @media prefers-color-scheme pour navigateurs sans JS. Ajout prefers-reduced-motion pour l'accessibilite. Suppression des !important superflus.

---

## [5.0.208] - 2026-03-20

### Ajout - 4 especes chimiques supplementaires dans les parametres surveilles aquaponie
- **Resume** : ajout de l'oxygene dissous (O2), des phosphates (PO4), du potassium (K+) et du fer (Fe2+/Fe3+) dans la section « Parametres surveilles » des pages aquaponie (templates aquaponie.twig et aquaponie_alt.twig), avec styles CSS dedies (mode clair et sombre).

---

## [5.0.207] - 2026-03-20

### Modifie - maintenance du changelog et garde-fous automatises
- **Resume** : mise en place de la maintenance durable du changelog (script de verification UTF-8, detection des doublons de version, limite de taille, rotation vers archive), ajout d'un hook `pre-commit` versionne, commandes composer dediees et documentation du workflow.

---

## [5.0.206] - 2026-03-20

### Modifie - fermeture lightbox au clic sur l'image
- **Resume** : ajout de la fermeture de la lightbox quand l'utilisateur clique directement sur la photo agrandie, en complement de la fermeture via fond, bouton et touche Echap.

---

## [5.0.205] - 2026-03-20

### Ajout - corbeille photo admin avec tri automatique
- **Resume** : ajout d'une corbeille photo pour les galeries ESP32-CAM (msp1, n3pp, ffp3), accessible uniquement depuis la page supervision (admin). Les photos trop claires, trop sombres ou corrompues sont automatiquement classees en corbeille a l'upload (analyse de luminosite GD). L'interface admin permet la restauration (unitaire, par selection, ou totale) et la suppression definitive. Nouveau service GalleryTrashService, controleur GalleryTrashController, template gallery_trash.twig et routes sous /admin/gallery/{slug}/trash.

---

## [5.0.204] - 2026-03-20

### Modifie - micro-amelioration UX de la galerie classique
- **Resume** : ajout d'indices visuels de zoom (curseur zoom-in sur vignettes, zoom-out en lightbox) et d'une transition douce au survol pour clarifier l'interaction de clic sur les photos.

---

## [5.0.203] - 2026-03-20

### Corrige - photos cliquables dans les galeries classiques
- **Resume** : restauration du template `gallery.twig` et ajout d'un comportement lightbox (ouverture au clic, navigation precedent/suivant, fermeture via fond ou touche Echap) afin de permettre l'affichage agrandi des photos dans les galeries classiques.

---

## [5.0.202] - 2026-03-20

### Modifie - maintenance durable du changelog (rotation + garde-fous)
- **Resume** : mise en place d'une strategie "rolling window" pour le changelog, ajout d'un script de verification/rotation (UTF-8 strict, doublons de versions, taille max), ajout d'un hook `pre-commit` versionne pour bloquer les anomalies, et documentation du workflow de maintenance.

---

## [5.0.201] - 2026-03-20

### Modifie - harmonisation des hauts de page et titres (audit style global)
- **Resume** : correction de la hierarchie des titres principaux (`h1`) sur dashboard/tide-stats et pages description, suppression des styles inline des titres/headers, externalisation des styles des pages galerie/timelapse/description vers les fichiers CSS, ajout des styles manquants du hero galerie en mode clair, et nettoyage des classes CSS orphelines liees aux en-tetes.

