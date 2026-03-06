# 🔄 Synchronisation Bidirectionnelle Interface Web ↔ ESP32

**Version**: 11.43  
**Date**: 2025-01-15  
**Projet**: FFP3 Aquaponie IoT

---

## 🎯 Vue d'ensemble

Le système FFP3 utilise une synchronisation bidirectionnelle entre l'interface web et l'ESP32 pour gérer les états des actionneurs (pompes, chauffage, lumière). Ce document explique le fonctionnement et les limitations de cette synchronisation.

---

## 🔄 Flux de Synchronisation

### Architecture Générale

```
Interface Web (control.twig)
        ↕ (polling 10s)
   Base de Données (ffp3Outputs/ffp3Outputs2)
        ↕ (POST/GET 2-3min)
     ESP32 (capteurs + actionneurs)
```

### Cycle de Communication ESP32

1. **Lecture capteurs** (ESP32)
2. **POST données** → `/post-data` (toutes les 2-3 min)
3. **Synchronisation BDD** → `syncStatesFromSensorData()`
4. **GET états** → `/api/outputs/state`
5. **Application hardware** (ESP32)
6. **Attente 2-3 min** → Retour à l'étape 1

---

## ⚡ Problèmes Identifiés et Solutions

### Problème 1: Conflit de Synchronisation Bidirectionnelle

**Symptôme**: Les changements faits sur l'interface web sont écrasés par l'ESP32.

**Cause**: L'ESP32 envoie ses états actuels via `syncStatesFromSensorData()` qui écrase systématiquement la base de données.

**Solution Implémentée**: **Protection par fenêtre de priorité**
- L'ESP32 récupère les états toutes les **6 secondes** (firmware : `REMOTE_FETCH_INTERVAL_MS`)
- Les changements faits depuis l'interface web ne sont **pas écrasés** par le POST ESP pendant une fenêtre de priorité : **10 s** (actionneurs/config) ou **20 s** (GPIO 108/109 nourrissage)
- Colonnes `lastModifiedBy` et `requestTime` : le serveur n'applique l'UPDATE du POST que si `lastModifiedBy != 'web'` ou si `requestTime` est antérieur à cette fenêtre

```sql
-- Logique dans syncStatesFromSensorData() / batchUpdateStatesSingleQuery()
-- L'UPDATE ne s'applique que si la ligne n'a pas été modifiée récemment par le web :
UPDATE ffp3Outputs2 
SET state = :state, requestTime = NOW(), lastModifiedBy = 'esp32'
WHERE gpio = :gpio AND name IS NOT NULL AND name != ''
  AND (lastModifiedBy != 'web' OR requestTime IS NULL OR requestTime < NOW() - INTERVAL :priority SECOND)
```

### Problème 2: Logique Inversée GPIO 18 Inconsistante

**Symptôme**: Pompe réserve affichée comme "Activée" alors qu'elle est éteinte côté ESP32.

**Cause**: Incohérence entre `getOutputsState()` (qui inverse) et `syncStatesFromSensorData()` (qui n'inverse pas).

**Solution Implémentée**: Cohérence maintenue dans les deux fonctions pour GPIO 18.

### Problème 3: Interface Web Désynchronisée

**Symptôme**: L'interface affiche des états qui ne correspondent pas à la réalité ESP32.

**Cause**: Le polling JavaScript (10s) récupère des états qui sont écrasés par l'ESP32 (2-3min).

**Solution Implémentée**: **Indicateurs Visuels de Synchronisation**
- Badges de statut en temps réel
- Informations de dernière synchronisation ESP32
- Notifications visuelles des conflits

---

## 🎨 Indicateurs Visuels

### Badges de Statut

| Badge | Couleur | Signification |
|-------|---------|---------------|
| 🟢 **SYNC** | Vert | État synchronisé entre web et ESP32 |
| 🟡 **EN ATTENTE ESP32** | Jaune | Changement web en attente de sync ESP32 |
| 🔵 **ESP32 SYNC** | Bleu | Synchronisation en cours par l'ESP32 |
| 🔴 **ERREUR** | Rouge | Erreur de communication ou conflit |

### Informations de Synchronisation

- **Dernière sync ESP32**: Timestamp de la dernière communication
- **Délai de synchronisation**: 2-3 minutes (incompressible)
- **Protection changements web**: 10 s (actionneurs/config), 20 s (nourrissage 108/109) — pendant cette fenêtre, le POST ESP n'écrase pas les valeurs écrites par l'interface

---

## ⏱️ Délais et Limitations

### Délais de Synchronisation

1. **Délai de synchronisation ESP32**: 6 secondes (poll GET outputs/state)
   - L'ESP32 récupère les états toutes les 6 secondes (`REMOTE_FETCH_INTERVAL_MS`)
   - Vos changements web sont appliqués au prochain GET par l'ESP32

2. **Protection contre écrasement par le POST**:
   - Les écritures web (`lastModifiedBy = 'web'`, `requestTime = NOW()`) sont protégées pendant 10 s (ou 20 s pour nourrissage) : le POST ESP ne met pas à jour ces lignes tant que la fenêtre n'est pas expirée
   - Comportement simple et prévisible

### Limitations Techniques

1. **Remplissage manuel autonome**: L'ESP32 peut démarrer la pompe réserve de manière autonome (logique de sécurité)
2. **Race conditions**: Possibles si modifications simultanées web + ESP32
3. **Dépendance réseau**: Synchronisation dépendante de la connectivité ESP32

---

## 🔧 Configuration et Maintenance

### Variables d'Environnement

```env
# .env
ENV=test                    # Environnement (test/prod)
API_KEY=your_api_key        # Clé API ESP32
API_SIG_SECRET=your_secret  # Secret pour signature HMAC
```

### Tables de Base de Données

**PRODUCTION**:
- `ffp3Outputs` - États GPIO production
- `ffp3Data` - Données capteurs production

**TEST**:
- `ffp3Outputs2` - États GPIO test
- `ffp3Data2` - Données capteurs test

### Colonnes Ajoutées (v11.43)

```sql
ALTER TABLE ffp3Outputs ADD COLUMN lastModifiedBy ENUM('web', 'esp32') NULL;
ALTER TABLE ffp3Outputs2 ADD COLUMN lastModifiedBy ENUM('web', 'esp32') NULL;
```

---

## 🧪 Tests et Validation

### Scénarios de Test

1. **Changement web → Protection**
   - ✅ Changer état sur interface web
   - ✅ Vérifier `lastModifiedBy='web'` en BDD
   - ✅ ESP32 POST dans les 10 s → Vérifier que l'état n'est PAS écrasé

2. **Expiration protection → Écrasement**
   - ✅ Attendre > 10 s (ou > 20 s pour GPIO 108/109) après changement web
   - ✅ ESP32 POST → Vérifier que l'état est maintenant écrasé

3. **GPIO 18 cohérence**
   - ✅ Vérifier cohérence entre affichage, BDD et ESP32
   - ✅ Tester logique inversée (state=0 = pompe ON)

4. **Polling JavaScript**
   - ✅ Vérifier détection des changements ESP32
   - ✅ Vérifier mise à jour des badges de statut

### Commandes de Test

```bash
# Test endpoint GET outputs
curl https://iot.olution.info/ffp3/api/outputs-test/state

# Test endpoint POST données (simuler ESP32)
curl -X POST https://iot.olution.info/ffp3/post-data-test \
  -d "api_key=YOUR_KEY&sensor=TEST&etatPompeAqua=1&etatHeat=0"

# Vérifier BDD
mysql -e "SELECT gpio, state, lastModifiedBy, requestTime FROM ffp3Outputs2 ORDER BY gpio;"
```

---

## 🚨 Dépannage

### Problèmes Courants

#### 1. Changements web ignorés
**Symptôme**: L'interface affiche "EN ATTENTE ESP32" indéfiniment.

**Causes possibles**:
- ESP32 déconnecté/éteint
- Erreur de communication réseau
- Problème de clé API

**Solutions**:
```bash
# Vérifier dernière communication ESP32
mysql -e "SELECT MAX(reading_time) FROM ffp3Data2;"

# Tester connectivité ESP32
curl https://iot.olution.info/ffp3/api/outputs-test/state
```

#### 2. États incohérents
**Symptôme**: L'affichage ne correspond pas à l'état réel ESP32.

**Causes possibles**:
- Délai de synchronisation (normal < 3 min)
- Problème de logique inversée GPIO 18
- Cache navigateur

**Solutions**:
- Attendre 3 minutes
- Rafraîchir la page (Ctrl+F5)
- Vérifier logs serveur

#### 3. Badges de statut incorrects
**Symptôme**: Badges restent en "EN ATTENTE" ou "ERREUR".

**Solutions**:
- Vérifier console JavaScript (F12)
- Redémarrer le polling automatique
- Vérifier logs `control-sync.js`

---

## 📈 Métriques et Monitoring

### Logs à Surveiller

```bash
# Logs de synchronisation
tail -f /path/to/ffp3/error_log | grep "GPIO.*protégé"

# Logs de modifications web
tail -f /path/to/ffp3/error_log | grep "Output ID.*mis à jour par l'interface web"

# Logs de communication ESP32
tail -f /path/to/ffp3/cronlog.txt | grep "Données capteurs insérées"
```

### Métriques Importantes

- **Fréquence communication ESP32**: Devrait être 2-3 min
- **Taux de protection GPIO**: GPIO protégés vs total
- **Erreurs de synchronisation**: Conflits détectés
- **Temps de réponse interface**: < 1 seconde

---

## 🔮 Améliorations Futures

### Court Terme
- [ ] Configuration de la fenêtre de protection (10 s / 20 s → configurable via .env)
- [ ] Notifications push pour conflits de synchronisation
- [ ] Historique des changements d'état

### Moyen Terme
- [ ] Synchronisation temps réel via WebSocket
- [ ] Mode "maintenance" pour désactiver l'ESP32
- [ ] API de force synchronisation manuelle

### Long Terme
- [ ] Architecture événementielle (message queue)
- [ ] Synchronisation multi-ESP32
- [ ] Mode hors-ligne avec synchronisation différée

---

## 📚 Références

- **ESP32 Guide**: `ESP32_GUIDE.md`
- **Environnements**: `ENVIRONNEMENT_TEST.md`
- **Migration v11.43**: `migrations/ADD_LASTMODIFIEDBY_COLUMN.sql`
- **Code principal**: `src/Repository/OutputRepository.php`
- **Interface**: `templates/control.twig`
- **JavaScript**: `public/assets/js/control-sync.js`

---

**Dernière mise à jour**: 2025-01-15  
**Version du document**: 1.0  
**Auteur**: Système FFP3 IoT
