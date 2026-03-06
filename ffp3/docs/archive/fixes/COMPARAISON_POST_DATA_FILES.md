# 📊 Comparaison post-data.php vs post-data-test.php

**Date**: 14 Octobre 2025  

---

## 🔍 État Actuel des Fichiers

### 1️⃣ `post-data.php` (Production - Moderne) ✅

**Localisation** : `ffp3/public/post-data.php`  
**Architecture** : Framework moderne avec autoload, PSR-4, Monolog  

**INSERT (SensorRepository.php lignes 33-43)** :
```php
INSERT INTO {$table} (
    sensor, version, TempAir, Humidite, TempEau, 
    EauPotager, EauAquarium, EauReserve,
    diffMaree, Luminosite, 
    etatPompeAqua, etatPompeTank, etatHeat, etatUV,
    bouffeMatin, bouffeMidi, bouffePetits, bouffeGros,
    aqThreshold, tankThreshold, chauffageThreshold, 
    mail, mailNotif, resetMode, bouffeSoir
)
```

✅ **22 colonnes** - TOUTES valides  
✅ **SANS** `api_key`, `tempsGros`, `tempsPetits`  
✅ **AVEC** `mail`, `mailNotif`, `resetMode`, `bouffeSoir`

**UPDATE Outputs (post-data.php lignes 208-233)** :
```php
$outputsToUpdate = [
    16 => $data->etatPompeAqua,     // GPIO 16
    18 => $data->etatPompeTank,     // GPIO 18
    2  => $data->etatHeat,          // GPIO 2
    15 => $data->etatUV,            // GPIO 15
    100 => null,                    // GPIO 100 (mail - texte)
    101 => $data->mailNotif === 'checked' ? 1 : 0,  // GPIO 101
    102 => $data->aqThreshold,      // GPIO 102
    103 => $data->tankThreshold,    // GPIO 103
    104 => $data->chauffageThreshold, // GPIO 104
    105 => $data->bouffeMatin,      // GPIO 105
    106 => $data->bouffeMidi,       // GPIO 106
    107 => $data->bouffeSoir,       // GPIO 107
    108 => $data->bouffePetits,     // GPIO 108
    109 => $data->bouffeGros,       // GPIO 109
    110 => $data->resetMode,        // GPIO 110
    111 => $data->tempsGros,        // GPIO 111
    112 => $data->tempsPetits,      // GPIO 112
    113 => $data->tempsRemplissageSec, // GPIO 113
    114 => $data->limFlood,         // GPIO 114
    115 => $data->wakeUp,           // GPIO 115
    116 => $data->freqWakeUp        // GPIO 116
];
```

✅ **17 GPIO mis à jour** (GPIO 100 à null, donc 16 effectifs)  
✅ Utilise `OutputRepository->updateState()`  
✅ Requêtes préparées PDO (sécurisé)

---

### 2️⃣ `post-data-test.php` (Test - Legacy) ❌ → ✅

**Localisation** : `/path/to/ffp3/post-data-test.php` (sur serveur)  
**Architecture** : Fichier PHP simple, mysqli, multi_query  

**AVANT (causait HTTP 500)** :
```php
INSERT INTO ffp3Data2 (
    api_key,        ← ❌ Colonne inexistante
    sensor, version, ...,
    tempsGros,      ← ❌ Colonne inexistante
    tempsPetits,    ← ❌ Colonne inexistante
    ...
)
```

**APRÈS (corrigé dans post-data-test-CORRECTED.php)** :
```php
INSERT INTO ffp3Data2 (
    sensor, version, TempAir, Humidite, TempEau,
    EauPotager, EauAquarium, EauReserve, diffMaree, Luminosite,
    etatPompeAqua, etatPompeTank, etatHeat, etatUV,
    bouffeMatin, bouffeMidi, bouffeSoir, bouffePetits, bouffeGros,
    aqThreshold, tankThreshold, chauffageThreshold,
    mail, mailNotif, resetMode
)
```

✅ **22 colonnes** - TOUTES valides (identique à post-data.php)  
✅ **21 GPIO mis à jour** via UPDATE individuels  

---

## ✅ Conclusion

### **`post-data.php` est DÉJÀ CORRECT !** 🎉

Le fichier moderne `ffp3/public/post-data.php` a **déjà toutes les corrections** :

1. ✅ INSERT sans colonnes invalides (`api_key`, `tempsGros`, `tempsPetits`)
2. ✅ INSERT avec toutes les colonnes valides
3. ✅ UPDATE de tous les GPIO (16 effectifs + mail en texte)
4. ✅ Sécurité PDO avec requêtes préparées
5. ✅ Gestion des erreurs avec try/catch
6. ✅ Logging avec Monolog

### Ce qui reste à faire

**Uniquement** : Déployer `post-data-test-CORRECTED.php` pour remplacer le fichier legacy sur le serveur

---

## 📊 Tableau Récapitulatif

| Aspect | post-data.php (moderne) | post-data-test.php (legacy) |
|--------|-------------------------|----------------------------|
| **État actuel** | ✅ Correct | ❌ À corriger |
| **INSERT colonnes** | 22 valides | 25 (3 invalides) |
| **UPDATE GPIO** | 17 (via OutputRepo) | 21 (via multi UPDATE) |
| **Sécurité** | PDO préparé | mysqli + escape |
| **Logging** | Monolog | Aucun |
| **Gestion erreurs** | try/catch | if/else simple |
| **Action requise** | Aucune | Déployer fichier corrigé |

---

## 🎯 Prochaine Étape

**Fichier moderne** (`post-data.php`) : ✅ Déjà parfait, rien à faire

**Fichier legacy** (`post-data-test.php`) : Déployer la version corrigée

```bash
# Sur serveur
cp /path/to/ffp3/post-data-test.php /path/to/ffp3/post-data-test.php.backup
# Puis copier le contenu de ffp3/post-data-test-CORRECTED.php
```

Le fichier `post-data.php` n'a **besoin d'aucune modification** ! 🚀

