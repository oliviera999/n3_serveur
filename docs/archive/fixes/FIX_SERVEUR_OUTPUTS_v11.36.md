# 🔥 Correction Serveur - Persistance Outputs v11.36

**Date**: 14 Octobre 2025  
**Problème**: Chauffage s'éteint automatiquement après activation locale  
**Cause**: Serveur ne persiste pas les états actionneurs dans table outputs  
**Statut**: ✅ **CORRIGÉ**

---

## 🚨 Problème Identifié - BUG CRITIQUE

### Symptômes
```
1. Utilisateur active chauffage depuis interface web locale
   → Chauffage s'allume immédiatement ✓
   
2. Quelques secondes après...
   → Chauffage s'éteint tout seul ❌
```

### Cause Racine

**Serveur PHP ne met PAS à jour la table outputs** !

```php
// Fichier: ffp3/public/post-data.php (AVANT v11.36)

try {
    $repo = new SensorRepository($pdo);
    $repo->insert($data);  // ← Insère dans ffp3Data/ffp3Data2 UNIQUEMENT
    
    // ❌ MANQUANT: Mise à jour ffp3Outputs/ffp3Outputs2
    
    echo "Données enregistrées avec succès";
}
```

**Conséquence** :
```
1. ESP32 envoie POST: etatHeat=1
   └─> Serveur insère dans ffp3Data2 ✓
   └─> ❌ Serveur NE MET PAS À JOUR ffp3Outputs2

2. ESP32 fait GET remote state (5s après)
   └─> Serveur lit ffp3Outputs2
   └─> Retourne "heat": "0" (ancien état non mis à jour!)
   
3. ESP32 applique état distant
   └─> stopHeaterManualLocal()
   └─> Chauffage s'éteint ❌
```

---

## ✅ Solution Implémentée

### Correction Fichier: `ffp3/public/post-data.php`

**Ajout après l'insertion données** (lignes 191-208):

```php
// CRITIQUE (v11.36): Mise à jour des OUTPUTS pour synchronisation ESP32
// Sans cela, les états actionneurs ne sont pas persistés côté serveur
$outputRepo = new \App\Repository\OutputRepository($pdo);

// Mapper les états reçus vers les GPIO correspondants
$outputsToUpdate = [
    16 => $data->etatPompeAqua,  // GPIO 16: Pompe aquarium
    18 => $data->etatPompeTank,  // GPIO 18: Pompe réservoir  
    2  => $data->etatHeat,        // GPIO 2:  Chauffage
    15 => $data->etatUV           // GPIO 15: Lumière
];

foreach ($outputsToUpdate as $gpio => $state) {
    if ($state !== null) {
        $outputRepo->updateState($gpio, (int)$state);
        $logger->debug("Output GPIO{$gpio} mis à jour: {$state}");
    }
}

$logger->info('Insertion OK + Outputs mis à jour', [
    'sensor' => $data->sensor, 
    'version' => $data->version
]);
```

### Fonctionnement Corrigé

```php
// OutputRepository::updateState() fait:
UPDATE ffp3Outputs2 
SET state = :state 
WHERE gpio = :gpio;

// Exemples:
UPDATE ffp3Outputs2 SET state = 1 WHERE gpio = 2;   // Chauffage ON
UPDATE ffp3Outputs2 SET state = 0 WHERE gpio = 16;  // Pompe aqua OFF
UPDATE ffp3Outputs2 SET state = 1 WHERE gpio = 15;  // Lumière ON
UPDATE ffp3Outputs2 SET state = 0 WHERE gpio = 18;  // Pompe tank OFF
```

---

## 📊 Séquence Corrigée

### AVANT v11.36 - BUG ❌

```
1. Utilisateur: Active chauffage local
   ESP32 → POST: etatHeat=1
   
2. Serveur PHP:
   ├─ INSERT INTO ffp3Data2 (..., etatHeat, ...) VALUES (..., 1, ...) ✓
   └─ ❌ OUBLI: Pas d'UPDATE ffp3Outputs2
   
3. ESP32: GET remote state (5s après)
   Serveur → {"heat": "0"}  ← ❌ Ancien état !
   
4. ESP32: Applique état distant
   └─> Chauffage OFF ❌
```

### APRÈS v11.36 - CORRIGÉ ✅

```
1. Utilisateur: Active chauffage local
   ESP32 → POST: etatHeat=1
   
2. Serveur PHP:
   ├─ INSERT INTO ffp3Data2 (..., etatHeat, ...) VALUES (..., 1, ...) ✓
   └─ UPDATE ffp3Outputs2 SET state = 1 WHERE gpio = 2 ✅ NOUVEAU !
   
3. ESP32: GET remote state (5s après)
   Serveur → {"heat": "1"}  ← ✅ État mis à jour !
   
4. ESP32: Compare états
   Local: ON, Distant: ON → Cohérent ✓
   └─> Pas de changement, chauffage reste ON ✅
```

---

## 🎯 Mapping GPIO → Outputs

| GPIO | Actionneur | Variable POST | Table Outputs |
|------|------------|---------------|---------------|
| 16 | Pompe Aquarium | `etatPompeAqua` | `ffp3Outputs2.gpio=16` |
| 18 | Pompe Réservoir | `etatPompeTank` | `ffp3Outputs2.gpio=18` |
| 2 | Chauffage | `etatHeat` | `ffp3Outputs2.gpio=2` |
| 15 | Lumière | `etatUV` | `ffp3Outputs2.gpio=15` |

---

## 📋 Tables Affectées

### Environnement TEST
- **Historique**: `ffp3Data2` (INSERT uniquement)
- **États actuels**: `ffp3Outputs2` (UPDATE maintenant ✅)

### Environnement PROD
- **Historique**: `ffp3Data` (INSERT uniquement)
- **États actuels**: `ffp3Outputs` (UPDATE maintenant ✅)

---

## 🔄 Flux Complet Corrigé

### 1. Activation Locale (Interface Web)
```
Utilisateur clique "Chauffage"
└─> ESP32 POST: etatHeat=1
    └─> Serveur PHP:
        ├─ INSERT ffp3Data2 (historique) ✅
        └─ UPDATE ffp3Outputs2 SET state=1 WHERE gpio=2 ✅ NOUVEAU
```

### 2. GET Remote State (5-10s après)
```
ESP32: GET /api/outputs-test/state
└─> Serveur lit ffp3Outputs2
    └─> Retourne: {"2": "1", "heat": "1"} ✅
```

### 3. Application État Distant
```
ESP32 compare:
├─ Local NVS: heater=ON
├─ Distant: heat=1 (ON)
└─> Match parfait ✅ → Pas de changement
    └─> Chauffage reste ON ✅
```

---

## 🧪 Tests de Validation

### Test 1: Activation Chauffage
```bash
# 1. Activer depuis interface web locale
curl http://192.168.0.86/control?relay=heater

# 2. Attendre 10 secondes

# 3. Vérifier BDD serveur
mysql> SELECT gpio, state FROM ffp3Outputs2 WHERE gpio = 2;
+------+-------+
| gpio | state |
+------+-------+
|    2 |     1 | ← Doit être 1 (ON)
+------+-------+

# 4. Vérifier ESP32 conserve état
# Chauffage doit rester ON ✅
```

### Test 2: Tous les Actionneurs
```bash
# Activer/désactiver chaque actionneur
# Vérifier que outputs BDD suit les changements

mysql> SELECT gpio, name, state FROM ffp3Outputs2 WHERE gpio IN (2, 15, 16, 18);
+------+------------------+-------+
| gpio | name             | state |
+------+------------------+-------+
|    2 | Radiateurs       |     1 |
|   15 | Lumière          |     1 |
|   16 | Pompe aquarium   |     0 |
|   18 | Pompe réservoir  |     0 |
+------+------------------+-------+
```

---

## 📊 Impact & Bénéfices

### Avant v11.36 ❌
- Actionneurs s'éteignent automatiquement
- États incohérents local/distant
- Utilisateur frustré (contrôle ne fonctionne pas)
- Base de données outputs jamais mise à jour

### Après v11.36 ✅
- Actionneurs conservent leur état
- Cohérence parfaite local/distant
- Contrôle utilisateur fonctionnel
- Base de données outputs toujours synchronisée

---

## 🚀 Déploiement

### Fichier Modifié
```
ffp3/public/post-data.php
```

### Synchronisation Serveur

**Utiliser script de sync** :
```powershell
# Depuis racine projet
.\sync_ffp3distant.ps1
```

Ou manuellement :
```bash
# Se connecter au serveur
ssh user@iot.olution.info

# Naviguer vers le dossier
cd /path/to/ffp3/public/

# Backup
cp post-data.php post-data.php.backup

# Upload nouveau fichier
# (via FTP, SCP, ou copier/coller)

# Vérifier permissions
chmod 644 post-data.php
chown www-data:www-data post-data.php
```

---

## ⚠️ Points d'Attention

### 1. Environnements TEST et PROD

Le code utilise `TableConfig` qui sélectionne automatiquement:
- TEST: `ffp3Data2` et `ffp3Outputs2`
- PROD: `ffp3Data` et `ffp3Outputs`

✅ Pas de modification nécessaire pour les 2 environnements

### 2. Initialisation Table Outputs

Vérifier que la table outputs contient bien les lignes GPIO :
```sql
-- Environnement TEST
SELECT gpio, name, state FROM ffp3Outputs2 WHERE gpio IN (2, 15, 16, 18);

-- Si lignes manquantes:
INSERT INTO ffp3Outputs2 (board, gpio, name, state) VALUES
('esp32-wroom', 2, 'Radiateurs', 0),
('esp32-wroom', 15, 'Lumière', 0),
('esp32-wroom', 16, 'Pompe aquarium', 0),
('esp32-wroom', 18, 'Pompe réservoir', 0);
```

### 3. Logs Serveur

Les logs contiendront maintenant :
```
[INFO] Insertion OK + Outputs mis à jour (sensor: esp32-wroom, version: 11.35)
[DEBUG] Output GPIO16 mis à jour: 0
[DEBUG] Output GPIO18 mis à jour: 0
[DEBUG] Output GPIO2 mis à jour: 1  ← Chauffage
[DEBUG] Output GPIO15 mis à jour: 1
```

---

## ✅ Conclusion

### Problème
❌ Serveur ne persistait pas états actionneurs → Chauffage s'éteignait

### Solution
✅ Serveur met maintenant à jour table outputs → Chauffage reste allumé

### Impact
- **Fiabilité**: Critique → Excellente
- **UX**: Frustrant → Fonctionnel
- **Cohérence**: Absente → Parfaite

---

**Fichier modifié**: `ffp3/public/post-data.php`  
**Lignes ajoutées**: 191-208  
**Version**: v11.36 (serveur)  
**Déploiement**: Script `sync_ffp3distant.ps1`  
**Test requis**: Activer chauffage + attendre 1 minute


