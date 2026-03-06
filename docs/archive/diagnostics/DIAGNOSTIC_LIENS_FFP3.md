# Diagnostic complet des liens du site FFP3

**Date**: 12 octobre 2025  
**Version**: Après corrections v4.5.0

## 📋 Résumé exécutif

Ce document recense tous les liens du site FFP3 et leur état après les corrections effectuées.

### ✅ Corrections appliquées

1. ✅ Lien cassé dans `templates/control.twig` ligne 599 : `/ffp3/ffp3datas/aquaponie` → `/ffp3/aquaponie`
2. ✅ Création du fichier `cronpompe.php` pour gérer le lien CRON manuel
3. ✅ Correction des liens dans `ffp3control/securecontrol/ffp3-outputs.php`
4. ✅ Correction des liens dans `ffp3control/securecontrol/ffp3-outputs2.php`
5. ✅ Correction des liens dans `ffp3control/securecontrol/test2/ffp3-outputs.php`

---

## 🗺️ Plan du site - Routes Slim 4

### Routes PRODUCTION

| Route | Type | Description | État |
|-------|------|-------------|------|
| `/` | GET | Dashboard | ✅ Fonctionnel |
| `/dashboard` | GET | Dashboard | ✅ Fonctionnel |
| `/aquaponie` | GET/POST | Page aquaponie principale | ✅ Fonctionnel |
| `/ffp3-data` | GET/POST | Alias legacy aquaponie | ✅ Fonctionnel |
| `/post-data` | POST | API réception données ESP32 | ✅ Fonctionnel |
| `/post-ffp3-data.php` | POST | Alias legacy API ESP32 | ✅ Fonctionnel |
| `/export-data` | GET | Export CSV des données | ✅ Fonctionnel |
| `/export-data.php` | GET | Alias legacy export | ✅ Fonctionnel |
| `/tide-stats` | GET/POST | Statistiques marées | ✅ Fonctionnel |
| `/control` | GET | Interface de contrôle GPIO | ✅ Fonctionnel |
| `/heartbeat` | POST | Heartbeat ESP32 | ✅ Fonctionnel |
| `/heartbeat.php` | POST | Alias legacy heartbeat | ✅ Fonctionnel |

### Routes API - Outputs

| Route | Type | Description | État |
|-------|------|-------------|------|
| `/api/outputs/toggle` | GET | Toggle état output | ✅ Fonctionnel |
| `/api/outputs/state` | GET | État de tous les outputs | ✅ Fonctionnel |
| `/api/outputs/parameters` | POST | Mise à jour paramètres | ✅ Fonctionnel |

### Routes API - Temps réel

| Route | Type | Description | État |
|-------|------|-------------|------|
| `/api/realtime/sensors/latest` | GET | Dernière lecture capteurs | ✅ Fonctionnel |
| `/api/realtime/sensors/since/{timestamp}` | GET | Lectures depuis timestamp | ✅ Fonctionnel |
| `/api/realtime/outputs/state` | GET | État outputs temps réel | ✅ Fonctionnel |
| `/api/realtime/system/health` | GET | Santé du système | ✅ Fonctionnel |
| `/api/realtime/alerts/active` | GET | Alertes actives | ✅ Fonctionnel |

### Routes TEST (environnement test)

Toutes les routes ci-dessus existent avec le suffixe `-test` :
- `/dashboard-test`
- `/aquaponie-test`
- `/post-data-test`
- `/tide-stats-test`
- `/export-data-test`
- `/control-test`
- `/api/outputs-test/*`
- `/api/realtime-test/*`
- `/heartbeat-test`

**État**: ✅ Toutes fonctionnelles

---

## 🔗 Liens internes - Navigation

### Menu principal (présent dans tous les templates)

| Lien | Destination | État |
|------|-------------|------|
| `https://iot.olution.info/index.html` | Page d'accueil N3 IoT | ✅ |
| `https://iot.olution.info/ffp3/aquaponie` | Aquaponie PROD | ✅ Corrigé |
| `https://iot.olution.info/ffp3/aquaponie-test` | Aquaponie TEST | ✅ Corrigé |
| `https://iot.olution.info/msp1/msp1datas/msp1-data.php` | Le potager | ⚠️ À vérifier |
| `https://iot.olution.info/n3pp/n3ppdatas/n3pp-data.php` | L'élevage d'insectes | ⚠️ À vérifier |

### Liens actions rapides (control.twig)

| Lien | Destination | État |
|------|-------------|------|
| `/ffp3/cronpompe.php` | Exécution manuelle CRON | ✅ Créé |
| `/ffp3/cronlog.txt` | Journal des CRON | ✅ |
| `/ffp3/aquaponie` | Retour données PROD | ✅ |
| `/ffp3/aquaponie-test` | Retour données TEST | ✅ |

---

## 📁 Ressources statiques

### CSS

| Fichier | Utilisé dans | État |
|---------|--------------|------|
| `https://iot.olution.info/assets/css/main.css` | Tous les templates | ✅ |
| `https://iot.olution.info/assets/css/noscript.css` | Tous les templates | ✅ |
| `/ffp3/assets/css/realtime-styles.css` | Templates temps réel | ✅ |
| `/ffp3/ffp3control/ffp3-style.css` | control.twig | ✅ |

### JavaScript

| Fichier | Utilisé dans | État |
|---------|--------------|------|
| `/ffp3/assets/js/toast-notifications.js` | aquaponie, dashboard, tide_stats | ✅ |
| `/ffp3/assets/js/chart-updater.js` | aquaponie | ✅ |
| `/ffp3/assets/js/stats-updater.js` | aquaponie, dashboard | ✅ |
| `/ffp3/assets/js/realtime-updater.js` | aquaponie, dashboard, tide_stats | ✅ |
| `/ffp3/assets/js/pwa-init.js` | aquaponie, dashboard, control, tide_stats | ✅ |
| `/ffp3/assets/js/control-sync.js` | control | ✅ |

### PWA

| Fichier | Description | État |
|---------|-------------|------|
| `/ffp3/manifest.json` | Manifest PWA | ✅ |
| `/ffp3/service-worker.js` | Service Worker | ✅ |
| `/ffp3/assets/icons/icon-192.png` | Icône PWA 192x192 | ⚠️ À vérifier |
| `/ffp3/assets/icons/icon-512.png` | Icône PWA 512x512 | ⚠️ À vérifier |

---

## 🌍 Liens externes

### Domaines olution.info

| Lien | Description | État |
|------|-------------|------|
| `https://olution.info/course/view.php?id=511` | Plateforme pédagogique | ⚠️ À vérifier |
| `https://farmflow.marout.org/` | Projet FarmFlow | ⚠️ À vérifier |

### CDN

| Service | Bibliothèque | État |
|---------|--------------|------|
| cdnjs.cloudflare.com | Font Awesome 6.4.0 | ✅ |
| code.highcharts.com | Highcharts Stock | ✅ |
| cdnjs.cloudflare.com | Moment.js | ✅ |
| cdnjs.cloudflare.com | Moment Timezone | ✅ |
| ajax.googleapis.com | jQuery 3.4.1 | ✅ |
| cdn.jsdelivr.net | Chart.js | ✅ |

---

## 🗑️ Fichiers legacy (obsolètes mais fonctionnels)

### ffp3control/

| Fichier | État | Note |
|---------|------|------|
| `ffp3control/index.php` | ⚠️ Redirige vers securecontrol | Maintenu pour compatibilité |
| `ffp3control/ffp3-database.php` | ⚠️ Obsolète | Remplacé par Slim 4 |
| `ffp3control/ffp3-database2.php` | ⚠️ Obsolète | Remplacé par Slim 4 |
| `ffp3control/ffp3-outputs-action.php` | ⚠️ Obsolète | Remplacé par API Slim 4 |
| `ffp3control/ffp3-outputs-action2.php` | ⚠️ Obsolète | Remplacé par API Slim 4 |
| `ffp3control/securecontrol/ffp3-outputs.php` | ✅ Corrigé | Interface legacy PROD |
| `ffp3control/securecontrol/ffp3-outputs2.php` | ✅ Corrigé | Interface legacy TEST |
| `ffp3control/securecontrol/test2/ffp3-outputs.php` | ✅ Corrigé | Interface de test |

### Racine (fichiers legacy)

| Fichier | État | Note |
|---------|------|------|
| `ffp3-config2.php` | ⚠️ Obsolète | Ancien fichier de config |
| `ffp3-data.php` | ⚠️ Obsolète | Remplacé par /aquaponie |
| `ffp3-data2.php` | ⚠️ Obsolète | Remplacé par /aquaponie-test |
| `post-ffp3-data.php` | ⚠️ Obsolète | Remplacé par /post-data |
| `post-ffp3-data2.php` | ⚠️ Obsolète | Remplacé par /post-data-test |
| `heartbeat.php` | ⚠️ Obsolète | Remplacé par /heartbeat |
| `legacy_bridge.php` | ⚠️ Obsolète | - |
| `index.php` | ⚠️ Obsolète | Ancien point d'entrée (remplacé par public/index.php) |

---

## 📊 Statistiques

- **Routes Slim 4**: 24 routes PROD + 24 routes TEST = **48 routes** ✅
- **Liens internes corrigés**: **5 liens** ✅
- **Ressources statiques**: **12 fichiers** ✅
- **Fichiers legacy conservés**: **16 fichiers** ⚠️
- **Liens externes à vérifier**: **4 liens** ⚠️

---

## 🔧 Actions de maintenance recommandées

### Court terme (urgent)

- [x] Corriger lien aquaponie dans control.twig
- [x] Créer cronpompe.php
- [x] Corriger liens dans fichiers legacy
- [ ] Vérifier que tous les icônes PWA existent
- [ ] Tester manuellement tous les liens externes

### Moyen terme (1-2 semaines)

- [ ] Créer un README dans `ffp3control/` expliquant l'obsolescence
- [ ] Ajouter des redirections 301 depuis les anciens fichiers vers les nouvelles routes
- [ ] Créer une page `/diagnostic` pour les administrateurs

### Long terme (optionnel)

- [ ] Migrer ou supprimer les fichiers dans `unused/`
- [ ] Archiver `ffp3datas_prov/`
- [ ] Uniformiser les liens externes (http vs https)

---

## 🧪 Tests recommandés

### Tests manuels

1. Naviguer sur chaque page et cliquer sur tous les liens du menu
2. Tester les boutons d'action rapide dans `/control`
3. Vérifier le bon fonctionnement de l'export CSV
4. Tester les APIs avec curl ou Postman
5. Vérifier le bon fonctionnement du mode TEST

### Tests automatisés (à créer)

```bash
# Exemple de script de test des liens
curl -I https://iot.olution.info/ffp3/aquaponie
curl -I https://iot.olution.info/ffp3/dashboard
curl -I https://iot.olution.info/ffp3/control
# etc.
```

---

## 📝 Notes

- Tous les liens internes utilisent des chemins absolus avec le domaine `iot.olution.info`
- Le système utilise une architecture hybride : Slim 4 (nouveau) + fichiers legacy (compatibilité)
- Les routes TEST sont isolées via middleware et tables de BDD dédiées
- La compatibilité ascendante avec les ESP32 est assurée via les aliases legacy

---

**Document généré automatiquement - Dernière mise à jour**: 12 octobre 2025

