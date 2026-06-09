# 📚 Index de la documentation — Serveur unifié n³ IoT

**Version du projet** : voir [`VERSION`](../VERSION) (actuellement **5.1.15**)
**Dernière mise à jour de l’index** : 9 juin 2026

Backend PHP (Slim 4) pour [iot.olution.info](https://iot.olution.info) : collecte des
données (msp1, n3pp, ffp3, poissonglouton), contrôle des sorties, galeries photo.

> Point d’entrée : `public/index.php`. Vue d’ensemble et démarrage : [`../README.md`](../README.md).

---

## 🔌 API & endpoints

| Fichier | Description |
|---------|-------------|
| [ENDPOINTS_ESP32_SERVEUR.md](ENDPOINTS_ESP32_SERVEUR.md) | Contrat complet des endpoints ESP32 ↔ serveur (post-data, heartbeat, outputs, realtime, pgl). |
| [API_MSP1_N3PP.md](API_MSP1_N3PP.md) | API des modules MSP1 (météo) et N3PP (serre). |
| [API_REALTIME_MSP_N3PP.md](API_REALTIME_MSP_N3PP.md) | API temps réel (LIVE) MSP1 / N3PP. |
| [API_REALTIME_OUTPUTS_CONTRAT.md](API_REALTIME_OUTPUTS_CONTRAT.md) | Contrat de l’API d’état des sorties (`/api/outputs/state`). |
| [OTA_N3PP_MSP.md](OTA_N3PP_MSP.md) | Mises à jour OTA des firmwares N3PP / MSP. |

## 🔐 Sécurité & authentification

| Fichier | Description |
|---------|-------------|
| [AUTHENTICATION.md](AUTHENTICATION.md) | Modes d’authentification (session, jeton, HMAC, API key). |
| [SECURITE_ROTATION_API_KEY.md](SECURITE_ROTATION_API_KEY.md) | Procédure de rotation de la clé API. |

## ⚙️ Fonctionnement & services

| Fichier | Description |
|---------|-------------|
| [SYNCHRONISATION_BIDIRECTIONNELLE.md](SYNCHRONISATION_BIDIRECTIONNELLE.md) | Synchronisation bidirectionnelle des sorties (serveur ↔ ESP32). |
| [ETAT_SYNCHRONISATION_SERVEUR.md](ETAT_SYNCHRONISATION_SERVEUR.md) | État de la synchronisation côté serveur. |
| [LIVE_MODE_IMPLEMENTATION.md](LIVE_MODE_IMPLEMENTATION.md) | Implémentation du mode LIVE (polling temps réel). |
| [TIMEZONE_MANAGEMENT.md](TIMEZONE_MANAGEMENT.md) | Gestion des fuseaux horaires (Europe/Paris). |
| [ERROR_ALERT_SERVICE.md](ERROR_ALERT_SERVICE.md) | Service d’alerte sur erreurs (`ErrorAlertService`). |
| [DEBUG_ERREURS_SERVEUR.md](DEBUG_ERREURS_SERVEUR.md) | Diagnostic d’erreurs serveur à partir d’une référence (HTTP 500). |
| [CLEAR_CACHE_OPTIONS.md](CLEAR_CACHE_OPTIONS.md) | Options de vidage du cache. |
| [SERVEUR_DISTANT_GUIDE.md](SERVEUR_DISTANT_GUIDE.md) | Guide du serveur distant. |
| [CONFIG_SERVEUR_FFP3_URLS.md](CONFIG_SERVEUR_FFP3_URLS.md) | Routage Apache/Nginx des URLs `/ffp3/*` vers `public/index.php`. |

## 🎨 UI / Frontend

| Fichier | Description |
|---------|-------------|
| [DARK_MODE.md](DARK_MODE.md) | Implémentation du mode sombre. |
| [CHECKLIST_QA_UI_ENTREES.md](CHECKLIST_QA_UI_ENTREES.md) | Checklist QA réutilisable pour les pages d’entrée. |
| [AUDIT_UI_ACCUEIL_LOGIN_NAV.md](AUDIT_UI_ACCUEIL_LOGIN_NAV.md) | Audit UI accueil / login / navigation. |
| [AUDIT_UI_MOBILE_LAPTOP_GLOBAL_2026-03.md](AUDIT_UI_MOBILE_LAPTOP_GLOBAL_2026-03.md) | Audit UI mobile / laptop (mars 2026). |
| [AUDIT_GRAPHIQUES_HIGHCHARTS.md](AUDIT_GRAPHIQUES_HIGHCHARTS.md) | Audit des graphiques Highcharts. |
| [AUDIT_PAGE_CONTROL_DISTANT.md](AUDIT_PAGE_CONTROL_DISTANT.md) | Audit de la page de contrôle distant. |
| [AUDIT_COHERENCE_AQUAPONIE_CONTROL_2026-06.md](AUDIT_COHERENCE_AQUAPONIE_CONTROL_2026-06.md) | Audit de cohérence aquaponie / contrôle (juin 2026). |

## 🛠️ Analyses & rapports (instantanés)

| Fichier | Description |
|---------|-------------|
| [AUDIT_SIMPLIFICATION_BACKEND.md](AUDIT_SIMPLIFICATION_BACKEND.md) | Audit de simplification du backend. |

## 🚀 Déploiement (`deployment/`)

| Fichier | Description |
|---------|-------------|
| [deployment/DEPLOYMENT_GUIDE.md](deployment/DEPLOYMENT_GUIDE.md) | Guide complet de déploiement serveur. |
| [deployment/CRON.md](deployment/CRON.md) | Tâches CRON (déploiement git + applicatif FFP3). |
| [deployment/CACHE_MANAGEMENT.md](deployment/CACHE_MANAGEMENT.md) | Gestion du cache au déploiement. |
| [deployment/INSTALL_HOOKS.md](deployment/INSTALL_HOOKS.md) | Installation des hooks git (`post-merge`, `pre-commit`). |
| [deployment/QUE_FAIRE_COTE_SERVEUR.md](deployment/QUE_FAIRE_COTE_SERVEUR.md) | Actions à effectuer côté serveur. |

## 📝 Changelog (`changelog/`)

| Fichier | Description |
|---------|-------------|
| [changelog/README.md](changelog/README.md) | Politique de maintenance du changelog (rolling window). |
| [changelog/archive/](changelog/archive/) | Historique archivé des entrées de changelog. |

Le changelog courant (fenêtre récente) est à la racine : [`../CHANGELOG.md`](../CHANGELOG.md).

---

## 📂 Archives historiques (`archive/`)

Instantanés conservés pour référence. **Non maintenus** — ne reflètent pas
nécessairement l’état actuel du code.

- `archive/migrations/` — migrations historiques (Slim 4, timezone, homogénéisation v4.4.0).
- `archive/diagnostics/` — diagnostics ponctuels (audit projet, ESP32, tables test).
- `archive/fixes/` — correctifs versionnés (HTTP 500, endpoints, outputs v11.36).
- `archive/implementations/` — guides d’implémentation par version (realtime/PWA v4, contrôle, GPIO).
- `archive/cleanup/`, `archive/corrections/`, `archive/deployment/` — divers historiques.

---

## 🔄 Maintenance de cet index

Lors de l’ajout d’une documentation :

1. Placer le fichier dans `docs/` (vivant) ou `docs/archive/<catégorie>/` (instantané historique).
2. Ajouter une entrée dans la section appropriée ci-dessus.
3. Mettre à jour la date de l’index.

Un document est **archivé** quand il est spécifique à une version passée, qu’il
décrit un correctif/diagnostic ponctuel résolu, ou qu’il a été consolidé ailleurs.

---

**© olution — Serveur unifié n³ IoT**
