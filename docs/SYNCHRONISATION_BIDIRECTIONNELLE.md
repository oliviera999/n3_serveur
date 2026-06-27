# 🔄 Synchronisation Bidirectionnelle Interface Web ↔ ESP32

**Version**: 11.43  
**Date**: 2025-01-15  
**Dernière révision**: 2026-06-14 (badges `#control-sync-badge` alignés sur `control-sync.js`)  
**Projet**: FFP3 Aquaponie IoT

---

## 🎯 Vue d'ensemble

Le système FFP3 utilise une synchronisation bidirectionnelle entre l'interface web et l'ESP32 pour gérer les états des actionneurs (pompes, chauffage, lumière). Ce document explique le fonctionnement et les limitations de cette synchronisation.

---

## 🔄 Flux de Synchronisation

### Architecture Générale

```
Interface Web (control.twig)
        ↕ (polling 6–10 s, GET state ?fresh=1)
   Base de Données (ffp3Outputs/ffp3Outputs2)
        ↕ (POST capteurs ~2–3 min + GET state ~6 s firmware)
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
- Les changements faits depuis l'interface web ne sont **pas écrasés** par le POST ESP pendant une fenêtre de priorité : **10 s** pour les actionneurs/config synchronisés
- Les compteurs de nourrissage GPIO 108/109 ne sont plus écrits par le POST ESP : ils sont détenus par le serveur (voir « compteur monotone »)
- Colonnes `lastModifiedBy` et `requestTime` : le serveur n'applique l'UPDATE du POST que si `lastModifiedBy != 'web'` ou si `requestTime` est antérieur à cette fenêtre

```sql
-- Logique dans syncStatesFromSensorData() / batchUpdateStatesSingleQuery()
-- L'UPDATE ne s'applique que si la ligne n'a pas été modifiée récemment par le web :
UPDATE ffp3Outputs2 
SET state = :state, requestTime = NOW(), lastModifiedBy = 'esp32'
WHERE gpio = :gpio AND name IS NOT NULL AND name != ''
  AND (lastModifiedBy != 'web' OR requestTime IS NULL OR requestTime < NOW() - INTERVAL :priority SECOND)
```

### Problème 2: GPIO 18 (pompe réserve) — sémantique UI / firmware / legacy

**Contrat actuel (page contrôle + GET state + firmware `gpio_parser.cpp`)** : `state = 1` → pompe **ON**, `state = 0` → pompe **OFF**. Aucune inversion dans `getOutputsState()` ni dans `control.twig` (`is_inverted = false`).

**Exception legacy** : [`PumpService`](../src/Service/PumpService.php) (scripts/cron historiques) utilise une logique relais active-low : `runPompeTank()` écrit `0`, `stopPompeTank()` écrit `1`. Ne pas confondre avec la page `/aquaponie-control` ni le poll ESP32.

**Sync POST** : `syncStatesFromSensorData()` recopie `etatPompeTank` tel quel (même sémantique 0/1 que le firmware POST).

### Problème 3: Interface Web Désynchronisée

**Symptôme**: L'interface affiche des états qui ne correspondent pas à la réalité ESP32.

**Cause**: Le polling JavaScript (10s) récupère des états qui sont écrasés par l'ESP32 (2-3min).

**Solution Implémentée**: **Indicateurs Visuels de Synchronisation**
- Badge global `#control-sync-badge` reflétant l'état du polling JS (`SYNC` quand le polling réussit, `RECONNEXION...` / `ERREUR` / `HORS LIGNE` / `PAUSE` sinon — voir tableau dans « Indicateurs Visuels »)
- Animation flash + mise à jour des switches sur les cartes dont l'état change côté serveur
- Notifications toast des changements détectés et des erreurs de synchronisation

---

## 🎨 Indicateurs Visuels

### Badge global `#control-sync-badge`

Un unique badge global `#control-sync-badge` (placé en haut de page par `control.twig` / `_control_base.twig`) reflète l'état du **polling** JavaScript du fichier `public/assets/js/control-sync.js`. Il ne reflète **pas** un état « sync / en attente » par GPIO : c'est uniquement l'état de la boucle de polling `GET .../state` côté navigateur.

Le badge est piloté exclusivement par la méthode `updateBadgeStatus(status)` : elle retire toutes les classes (`connecting`, `online`, `offline`, `error`, `warning`, `paused`), applique la classe correspondant au `status`, puis écrit le libellé issu de la table `texts`. Le markup initial est `<div id="control-sync-badge" class="connecting">CONNEXION...</div>`.

#### État badge ↔ condition JS ↔ classe CSS ↔ libellé

| `status` (arg) | Classe CSS appliquée | Libellé (`textContent`) | Déclenchement (`control-sync.js`) |
|----------------|----------------------|-------------------------|-----------------------------------|
| `connecting` | `connecting` | `CONNEXION...` | `start()`, début de `poll()` tant que le badge n'a pas la classe `online`, reprise après visibilité |
| `online` | `online` | `SYNC` | `poll()` réussi (réponse `GET .../state` HTTP OK), `retryCount` remis à 0 |
| `warning` | `warning` | `RECONNEXION...` | échec de `poll()`, tentative de reconnexion avec backoff exponentiel (1 s × 2^n, plafonné à 30 s) tant que `retryCount < maxRetries` |
| `error` | `error` | `ERREUR` | `maxRetries` (5) atteint → arrêt du polling (`stop()`) + toast d'erreur |
| `offline` | `offline` | `HORS LIGNE` | `stop()` (arrêt manuel ou après `error`) |
| `paused` | `paused` | `PAUSE` | onglet masqué (`visibilitychange` → `document.hidden`) ; le polling reprend (`connecting` puis `online`) au retour |

> ℹ️ **Badge global vs nourrissage manuel** : `#control-sync-badge` reflète uniquement le polling HTTP (`SYNC`, `RECONNEXION…`, etc.). Depuis v5.10.0, chaque carte **Nourrissage** (GPIO 108/109) affiche un libellé `[data-feed-status]` : `Prêt` → `En attente ESP32…` → `Exécuté` ou `Timeout — réessayer`, plus une **réf. commande** (`feed_cmd_id`) dans l'info-bulle pour le diagnostic (logs `[control-audit]`). Depuis v5.10.1 : **panneau live** avec chronomètre, timeline des étapes (reset, impulsion, lecture ESP32, trace capteur, acquittement) et **polling accéléré** (~2 s) pendant le cycle.

#### Retour visuel par actionneur

Le feedback « changement distant » par GPIO ne passe pas par un badge texte mais par une **animation flash** : lorsqu'un état change côté serveur (détecté au polling), la carte `.action-card` correspondante reçoit la classe `state-changed` pendant 1 s, le switch est mis à jour (`checked`), l'attribut `data-state` et le libellé `Activé`/`Désactivé` sont ajustés. Un toast d'information « Changement détecté: GPIO … » est aussi affiché si `window.toastManager` est présent.

### Informations de Synchronisation

- **Dernière sync ESP32**: Timestamp de la dernière communication
- **Délai de synchronisation**: 2-3 minutes (incompressible)
- **Protection changements web**: 10 s pour les actionneurs/config synchronisés — pendant cette fenêtre, le POST ESP n'écrase pas les valeurs écrites par l'interface. Les compteurs nourrissage 108/109 sont exclus de cette logique car le POST ESP ne les écrit plus.

---

## 🐟 Nourrissage manuel (GPIO 108 / 109) — contrat FFP3 vs MSP/N3PP

### Sémantique par famille (même numéro GPIO, usages différents)

| Famille | GPIO 108 | GPIO 109 | Rôle |
|---------|----------|----------|------|
| **FFP3** (aquaponie) | `bouffePetits` | `bouffeGros` | Commande **impulsionnelle** : nourrir petits / gros poissons (servos) |
| **MSP1 / N3PP** | `notifMode` | `notifCategories` | Paramètres **server-only** de politique de notifications (pas de servo) |

Ne pas extrapoler le comportement MSP/N3PP aux cartes nourrissage FFP3 : les numéros GPIO sont réutilisés avec une sémantique distincte.

### Pattern FFP3 : compteur monotone (serveur 6.0.0 / firmware ffp5cs 15.0)

> ⚠️ **Changement de contrat (BREAKING)** vs v5.10.x. L'ancien schéma « niveau + front 0→1 »
> (séquence reset/trigger, acquittement firmware, fenêtre 20 s) est **supprimé** : trop de
> pièces mobiles, flags bloqués à `1`, commandes perdues. Remplacé par un compteur simple.

| Pattern | Où | Comportement GET `outputs/state` |
|---------|-----|----------------------------------|
| **Pulse à la lecture** | `AbstractOutputRepository` (MSP/N3PP) | Si GPIO one-shot vaut `1`, le JSON renvoie `1` puis la BDD repasse à `0` **dans la même requête** (consommation immédiate). |
| **Compteur monotone** | FFP3 (`OutputCacheService`) | Le GET renvoie un **entier croissant** pour 108/109 (= nombre total de repas demandés). Le serveur ne le remet **jamais** à zéro. |

**Principe** : web n'écrit que le compteur (incrément), le firmware ne fait que le lire. Le
firmware ffp5cs mémorise son propre **compteur exécuté** en NVS (`feedExecP`/`feedExecG` + flag
`feedSeed`) et rattrape l'écart : un repas par poll, **plafonné à 5** (`MAX_FEED_CATCHUP`,
sécurité des poissons). Au premier poll après un flash neuf, il **adopte** la valeur courante
sans nourrir (évite des repas parasites). Aucune écriture firmware sur 108/109 ⇒ **aucune course
bidirectionnelle**, robustesse aux reboots et aux polls manqués.

**Pourquoi un compteur** : un entier croissant + un compteur exécuté persistant est idempotent et
sans état partagé. Plus besoin de fabriquer un front, d'acquitter, ni de protéger une fenêtre
d'écriture : cliquer N fois = nourrir N fois (le firmware rattrape), sans jamais « bloquer ».

### Interface web (v6.0.0+)

- Bouton **« Nourrir »** (impulsion unique) sur `/aquaponie-control*`.
- Endpoint `POST /api/outputs*/trigger-feed`, corps `{ id, gpio }` (plus de `step`) : fait
  `state = state + 1` seulement si la ligne `id` correspond au GPIO demandé ; réponse `{ success, gpio, counter, feed_cmd_id }`. Le `feed_cmd_id`
  (16 car. hex) est journalisé dans `[control-audit] action=trigger_manual_feed`.
- Suivi UI : toast `Repas demandé (#N)` + affichage du compteur. Pas d'attente d'acquittement,
  pas de timeout, pas de polling accéléré (le firmware rattrape de lui-même).
- Rétrocompat : un client encore en cache peut envoyer `step:"reset"` → traité en **no-op**
  (le compteur monotone n'est jamais remis à zéro), donc pas de double comptage.

Voir aussi `docs/ENDPOINTS_ESP32_SERVEUR.md` (section nourrissage) et `docs/SERVEUR_DISTANT_GUIDE.md` (flux firmware).

---

## ⏱️ Délais et Limitations

### Délais de Synchronisation

1. **Délai de synchronisation ESP32**: 6 secondes (poll GET outputs/state)
   - L'ESP32 récupère les états toutes les 6 secondes (`REMOTE_FETCH_INTERVAL_MS`)
   - Vos changements web sont appliqués au prochain GET par l'ESP32

2. **Protection contre écrasement par le POST**:
   - Les écritures web (`lastModifiedBy = 'web'`, `requestTime = NOW()`) sont protégées pendant 10 s : le POST ESP ne met pas à jour ces lignes tant que la fenêtre n'est pas expirée
   - Le nourrissage (108/109) n'a **plus** de fenêtre dédiée : ce sont des compteurs détenus par le serveur, jamais écrits par le POST firmware (cf. compteur monotone ci-dessus)
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
   - ✅ Attendre > 10 s après changement web
   - ✅ ESP32 POST → Vérifier que l'état est maintenant écrasé

3. **GPIO 18 cohérence**
   - ✅ Vérifier cohérence entre affichage, BDD et ESP32 (1 = ON sur page contrôle et GET state)
   - ⚠️ Ne pas appliquer la logique inversée de `PumpService` aux tests de la page web

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
**Symptôme**: Un changement web n'est jamais répercuté côté matériel (le badge global peut afficher `RECONNEXION...` ou `ERREUR` si le polling échoue, mais reste `SYNC` tant que le serveur répond — il ne traduit pas l'état de l'ESP32).

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
- Décalage normal entre poll UI (6–10 s) et cycle POST capteurs (2–3 min) pour les témoins « dernier état Data »
- Cache navigateur

**Solutions**:
- Attendre 3 minutes
- Rafraîchir la page (Ctrl+F5)
- Vérifier logs serveur

#### 3. Badge `#control-sync-badge` bloqué
**Symptôme**: Le badge global reste en `RECONNEXION...`, `ERREUR` ou `HORS LIGNE` (le polling JS échoue ou est arrêté). Après 5 échecs consécutifs (`maxRetries`), `control-sync.js` passe en `ERREUR` puis `stop()` (badge `HORS LIGNE`).

**Solutions**:
- Vérifier console JavaScript (F12) et l'accès à `GET .../state`
- Relancer le polling : `window.controlSync.start()` (ou rafraîchir la page)
- Vérifier logs `control-sync.js` (préfixe `[ControlSync]`)

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

**Dernière mise à jour**: 2026-06-24  
**Version du document**: 1.2  
**Auteur**: Système FFP3 IoT
