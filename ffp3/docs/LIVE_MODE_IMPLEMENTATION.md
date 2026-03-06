# Mode Live - Implémentation complétée

Date: 12 octobre 2025  
Version: 4.5.0  
Statut: ✅ Implémenté, en attente de tests

---

## 📋 Résumé de l'implémentation

Le mode live permet aux utilisateurs de voir les graphiques et statistiques se mettre à jour automatiquement sans recharger la page, transformant la page d'aquaponie en un vrai dashboard temps réel.

## 🎯 Fonctionnalités implémentées

### 1. Modules JavaScript créés

#### **`chart-updater.js`** (324 lignes)
- Gère la mise à jour dynamique des graphiques Highcharts
- Ajout de nouveaux points sans recharger la page
- Auto-scroll pour suivre les dernières données
- Limitation du nombre de points en mémoire (10 000 par défaut)
- Batch updates pour optimiser les performances

**Méthodes principales:**
```javascript
- init() // Initialise les références aux graphiques
- addNewReading(timestamp, sensors) // Ajoute une lecture
- addNewReadings(readings) // Ajoute plusieurs lectures
- enableAutoScroll(enabled) // Active/désactive l'auto-scroll
- setMaxPoints(maxPoints) // Configure la limite de points
```

#### **`stats-updater.js`** (291 lignes)
- Met à jour les cartes de statistiques en temps réel
- Gère les valeurs affichées et les barres de progression
- Calcule les statistiques incrémentales (min, max, moyenne)
- Animations flash pour indiquer les changements

**Méthodes principales:**
```javascript
- init() // Initialise les références aux éléments DOM
- updateAllStats(sensors) // Met à jour toutes les statistiques
- updateStat(sensorName, value) // Met à jour une statistique
- updateStatCard(sensorName, value) // Met à jour l'affichage
```

### 2. Modifications apportées

#### **`realtime-updater.js`**
- Utilise maintenant `/api/realtime/sensors/since/{timestamp}` pour polling incrémental
- Intégration automatique avec `chartUpdater` et `statsUpdater`
- Gestion intelligente du premier poll vs polls suivants
- Optimisation : récupère uniquement les nouvelles données

#### **`realtime-styles.css`** (+213 lignes)
- Animations pour les valeurs mises à jour (flashValue)
- Styles pour le panneau de contrôles live
- Toggle switches personnalisés
- Responsive design pour mobile

#### **`aquaponie.twig`**
- Panneau de contrôles live (lignes 1690-1737)
- Initialisation des modules (lignes 1746-1899)
- Chargement des nouveaux scripts
- Event listeners pour les contrôles

#### **`dashboard.twig`**
- Intégration du `stats-updater`
- Mise à jour automatique des cartes de statistiques

## 🎛️ Panneau de contrôles

Le panneau de contrôles (en bas à gauche) permet à l'utilisateur de :

1. **Mode Live ON/OFF** : Active/désactive la mise à jour automatique
2. **Auto-scroll** : Active/désactive le suivi automatique des dernières données
3. **Intervalle** : Sélecteur d'intervalle (5s, 10s, 15s, 30s, 60s)
4. **Compteur** : Affiche le nombre de nouvelles données reçues
5. **Rafraîchir** : Bouton pour forcer une mise à jour immédiate

Toutes les préférences sont sauvegardées dans `localStorage` :
- `liveMode.enabled`
- `liveMode.autoScroll`
- `liveMode.interval`
- `liveMode.maxPoints`

## 📊 Badge LIVE

Le badge (en haut à droite) indique l'état de la synchronisation :

- **INITIALISATION...** (gris) : Démarrage
- **LIVE** (vert, pulse) : Synchronisation active
- **CONNEXION...** (orange) : Tentative de connexion
- **ERREUR** (rouge) : Échec de connexion après 5 tentatives
- **PAUSE** (gris) : Onglet en arrière-plan

## 🔧 Configuration par défaut

| Paramètre | Valeur | Description |
|-----------|--------|-------------|
| Intervalle | 15 secondes | Fréquence de polling |
| Auto-scroll | Activé | Suivi automatique des dernières données |
| Max points | 10 000 | Limite de points par série (~21 jours) |
| Mode live | Activé | État initial du mode live |

## 🧪 Tests à effectuer

### Tests fonctionnels

1. **✅ Graphiques se mettent à jour automatiquement**
   - Ouvrir la page d'aquaponie
   - Attendre 15 secondes (ou intervalle configuré)
   - Vérifier que de nouveaux points apparaissent sur les graphiques

2. **✅ Cartes de statistiques se mettent à jour**
   - Observer les valeurs des cartes (niveaux d'eau, températures)
   - Vérifier qu'elles changent avec les nouvelles données
   - Vérifier l'animation flash lors de la mise à jour

3. **✅ Auto-scroll fonctionne**
   - Activer l'auto-scroll
   - Vérifier que les graphiques scrollent pour afficher les dernières données
   - Désactiver l'auto-scroll
   - Vérifier que le graphique ne bouge plus

4. **✅ Limite de points**
   - Laisser la page ouverte longtemps (ou simuler avec beaucoup de données)
   - Vérifier que le nombre de points ne dépasse pas la limite
   - Vérifier que les points les plus anciens sont supprimés

5. **✅ Badge LIVE**
   - Vérifier que le badge passe de INITIALISATION à LIVE
   - Couper la connexion réseau → badge ERREUR
   - Rétablir la connexion → badge LIVE

6. **✅ Panneau de contrôles**
   - Désactiver le mode live → polling s'arrête
   - Activer le mode live → polling reprend
   - Changer l'intervalle → vérifier que le polling change de fréquence
   - Cliquer sur "Rafraîchir" → mise à jour immédiate

7. **✅ Préférences sauvegardées**
   - Changer les paramètres (auto-scroll, intervalle)
   - Rafraîchir la page (F5)
   - Vérifier que les paramètres sont restaurés

8. **✅ Onglet en arrière-plan**
   - Mettre l'onglet en arrière-plan
   - Badge passe à PAUSE
   - Revenir sur l'onglet
   - Badge reprend à LIVE + mise à jour immédiate

### Tests d'environnement

9. **✅ Environnement TEST**
   - Accéder à `/aquaponie-test`
   - Vérifier que l'API `/ffp3/api/realtime-test` est utilisée
   - Vérifier que les données de `ffp3Data2` sont affichées

10. **✅ Environnement PROD**
    - Accéder à `/aquaponie`
    - Vérifier que l'API `/ffp3/api/realtime` est utilisée
    - Vérifier que les données de `ffp3Data` sont affichées

### Tests de performance

11. **✅ Batch updates**
    - Simuler beaucoup de nouvelles données (>10 lectures)
    - Vérifier qu'il n'y a pas de scintillement
    - Vérifier que les animations sont désactivées

12. **✅ Mémoire**
    - Ouvrir les DevTools (F12) → Performance Monitor
    - Laisser la page ouverte 1 heure
    - Vérifier que la mémoire ne croît pas de façon incontrôlée

### Tests mobile

13. **✅ Responsive**
    - Ouvrir sur mobile/tablette
    - Vérifier que le panneau de contrôles s'adapte
    - Vérifier que les graphiques restent lisibles

## 🐛 Débogage

### Console du navigateur

Ouvrir les DevTools (F12) → Console pour voir les logs :

```
[ChartUpdater] Initialized with 2 chart(s)
[StatsUpdater] Initialized with 7 sensor element(s)
[RealtimeUpdater] Starting polling...
[RealtimeUpdater] 1 new reading(s) received!
[Aquaponie] New sensor data received: [...]
[ChartUpdater] Batch update completed: 7 point(s) added
```

### Commandes de débogage

Dans la console du navigateur :

```javascript
// Obtenir les statistiques des graphiques
chartUpdater.getStats()

// Obtenir les statistiques des capteurs
statsUpdater.getStats()
statsUpdater.logStats()

// Forcer un poll manuel
realtimeUpdater.poll()

// Changer l'intervalle
realtimeUpdater.setInterval(5000) // 5 secondes
```

## 📝 Checklist de déploiement

Avant de déployer en production :

- [x] Code implémenté et testé localement
- [ ] Tests effectués en environnement TEST
- [ ] Vérification sur plusieurs navigateurs (Chrome, Firefox, Safari)
- [ ] Vérification sur mobile
- [ ] Performance vérifiée (pas de fuite mémoire)
- [ ] Version incrémentée (4.5.0)
- [ ] CHANGELOG mis à jour
- [ ] Documentation créée
- [ ] Commit Git avec message clair
- [ ] Push vers origin/main

## 🚀 Déploiement

1. **Tester en environnement TEST**
   ```bash
   # Accéder à
   https://iot.olution.info/ffp3/aquaponie-test
   ```

2. **Vérifier les logs serveur**
   ```bash
   tail -f /path/to/logs/error.log
   ```

3. **Déployer en PROD**
   - Le code est déjà partagé entre PROD et TEST
   - Les routes sont automatiquement adaptées
   - Accéder à : `https://iot.olution.info/ffp3/aquaponie`

## 🔍 Vérifications post-déploiement

1. Ouvrir la page d'aquaponie
2. Vérifier que le panneau de contrôles s'affiche
3. Vérifier que le badge LIVE passe à "LIVE" (vert)
4. Attendre 15 secondes et vérifier qu'une mise à jour se produit
5. Vérifier dans la console qu'il n'y a pas d'erreurs JavaScript
6. Vérifier que le compteur de nouvelles données s'incrémente

## 📞 Support

En cas de problème :

1. Vérifier la console du navigateur pour les erreurs JavaScript
2. Vérifier les logs serveur pour les erreurs API
3. Vérifier que l'API `/api/realtime/sensors/since/{timestamp}` fonctionne :
   ```bash
   curl https://iot.olution.info/ffp3/api/realtime/sensors/latest
   ```
4. Désactiver temporairement le mode live via le panneau de contrôles

## 📚 Fichiers de référence

- **Modules** : `public/assets/js/chart-updater.js`, `public/assets/js/stats-updater.js`
- **Styles** : `public/assets/css/realtime-styles.css`
- **Templates** : `templates/aquaponie.twig`, `templates/dashboard.twig`
- **API** : `src/Controller/RealtimeApiController.php`, `src/Service/RealtimeDataService.php`

---

**Implémenté avec ❤️ pour le projet FFP3 - Système d'aquaponie olution**

