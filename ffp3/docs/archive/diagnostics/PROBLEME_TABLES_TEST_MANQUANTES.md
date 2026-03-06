# 🔴 PROBLÈME DÉTECTÉ : Tables TEST manquantes

**Date**: 2025-10-12  
**Priorité**: 🟡 MOYENNE (n'affecte pas PROD)  
**Status**: ⚠️ À CORRIGER

---

## 🐛 SYMPTÔME

Lors du flash de `wroom-test` v11.06, l'ESP32 envoie des données à `/post-data-test` mais reçoit une **erreur 500** :

```
[HTTP] → http://iot.olution.info/ffp3/post-data-test (463 bytes)
[HTTP] ← code 500, 104 bytes
[HTTP] response: Données enregistrées avec succèsUne erreur serveur est survenue...
```

**Message contradictoire** :
- ✅ "Données enregistrées avec succès"
- ❌ "Une erreur serveur est survenue"

---

## 🔍 CAUSE RACINE

### Architecture des tables

Le backend PHP utilise **TableConfig** pour basculer entre tables PROD et TEST :

```php
// src/Config/TableConfig.php
public static function getDataTable(): string {
    return self::isTest() ? 'ffp3Data2' : 'ffp3Data';  // ← ffp3Data2 pour TEST
}

public static function getOutputsTable(): string {
    return self::isTest() ? 'ffp3Outputs2' : 'ffp3Outputs';  // ← ffp3Outputs2 pour TEST
}
```

### Tables attendues

| Environnement | Table capteurs | Table outputs | Table heartbeat |
|---------------|---------------|---------------|-----------------|
| **PROD** | `ffp3Data` | `ffp3Outputs` | `ffp3Heartbeat` |
| **TEST** | `ffp3Data2` | `ffp3Outputs2` | `ffp3Heartbeat2` |

### Diagnostic

**Les tables TEST n'existent probablement PAS** sur le serveur MySQL :
- ❌ `ffp3Data2` - manquante
- ❌ `ffp3Outputs2` - manquante  
- ❌ `ffp3Heartbeat2` - manquante (heartbeat renvoie 404)

**Conséquence** :
- Le contrôleur PHP reçoit les données
- Tente d'insérer dans `ffp3Data2`
- **Erreur SQL** (table inexistante)
- Erreur 500 retournée à l'ESP32

---

## ✅ SOLUTION

### Option 1 : Créer les tables manuellement (RECOMMANDÉ)

Un script SQL a été créé : **`CREATE_TEST_TABLES.sql`**

**Exécution via SSH** :
```bash
ssh oliviera@toaster
cd /home4/oliviera/iot.olution.info/ffp3
mysql -u [DB_USER] -p [DB_NAME] < CREATE_TEST_TABLES.sql
```

**Exécution via phpMyAdmin** :
1. Se connecter à phpMyAdmin
2. Sélectionner la base de données FFP3
3. Onglet "SQL"
4. Copier/coller le contenu de `CREATE_TEST_TABLES.sql`
5. Cliquer "Exécuter"

### Option 2 : Requêtes SQL manuelles

```sql
-- Créer les tables TEST en dupliquant PROD
CREATE TABLE `ffp3Data2` LIKE `ffp3Data`;
CREATE TABLE `ffp3Outputs2` LIKE `ffp3Outputs`;
CREATE TABLE `ffp3Heartbeat2` LIKE `ffp3Heartbeat`;

-- Initialiser ffp3Outputs2 avec la config de base
INSERT INTO `ffp3Outputs2` (gpio, name, state, description)
SELECT gpio, name, 0 AS state, CONCAT(description, ' (TEST)') AS description
FROM `ffp3Outputs`;

-- Vérification
SELECT TABLE_NAME, TABLE_ROWS 
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('ffp3Data', 'ffp3Data2', 'ffp3Outputs', 'ffp3Outputs2');
```

---

## 📊 VÉRIFICATION POST-CRÉATION

### 1. Vérifier les tables

```sql
SHOW TABLES LIKE 'ffp3%';
```

**Attendu** :
```
ffp3Data
ffp3Data2        ← Doit apparaître
ffp3Heartbeat
ffp3Heartbeat2   ← Doit apparaître
ffp3Outputs
ffp3Outputs2     ← Doit apparaître
```

### 2. Vérifier la structure

```sql
DESCRIBE ffp3Data2;
```

**Attendu** : Même structure que `ffp3Data`

### 3. Tester l'insertion TEST

```bash
# Depuis un ESP32 wroom-test ou via curl
curl -X POST "https://iot.olution.info/ffp3/post-data-test" \
  -d "api_key=fdGTMoptd5CD2ert3" \
  -d "sensor=test" \
  -d "version=11.06" \
  -d "TempAir=25.0" \
  -d "Humidite=60.0" \
  # ... autres paramètres
```

**Attendu** :
- ✅ HTTP 200
- ✅ Réponse : "Données enregistrées avec succès"
- ✅ Donnée insérée dans `ffp3Data2` (pas `ffp3Data`)

### 4. Vérifier les logs PHP

```bash
ssh oliviera@toaster
cd /home4/oliviera/iot.olution.info/ffp3
tail -n 50 var/logs/post-data.log
```

**Rechercher** :
- ✅ `[INFO] Insertion OK` avec sensor=esp32-wroom, version=11.06
- ❌ Aucune erreur SQL sur ffp3Data2

---

## 📋 CHECKLIST DE RÉSOLUTION

- [ ] Se connecter au serveur MySQL
- [ ] Exécuter `CREATE_TEST_TABLES.sql`
- [ ] Vérifier que les 3 tables sont créées
- [ ] Tester insertion depuis ESP32 wroom-test
- [ ] Vérifier que l'erreur 500 a disparu
- [ ] Confirmer que les données arrivent dans ffp3Data2
- [ ] Documenter la résolution

---

## 🎯 IMPACT

### Sur l'ESP32
- ⚠️ Les données TEST ne sont **pas enregistrées**
- ⚠️ Erreur 500 mais **ESP32 reste stable** (ignore l'erreur)
- ✅ Tous les autres systèmes fonctionnent (capteurs, mails, etc.)

### Sur PROD
- ✅ **Aucun impact** - PROD utilise ffp3Data (existe)
- ✅ wroom-prod v11.06 fonctionne correctement

---

## 💡 POURQUOI LES TABLES MANQUENT ?

**Hypothèses** :
1. Migration TEST/PROD faite **en code seulement** (pas en DB)
2. Tables créées manuellement pour PROD mais **oubliées pour TEST**
3. Script de création jamais exécuté sur le serveur

**Indices** :
- Documentation mentionne les tables TEST
- Code PHP supporte les tables TEST
- **Mais** aucun script SQL de création trouvé dans le repo

---

## 🚀 APRÈS LA CORRECTION

Une fois les tables créées, l'ESP32 wroom-test devrait :
- ✅ Recevoir HTTP 200 (au lieu de 500)
- ✅ Enregistrer les données dans ffp3Data2
- ✅ Permettre de tester sans polluer les données PROD
- ✅ Interface web `/aquaponie-test` affichera les bonnes données

---

**Status** : ⚠️ **TABLES À CRÉER** (script fourni : `CREATE_TEST_TABLES.sql`)

**Impact** : 🟡 MOYEN - Bloque les tests mais PROD non affecté

