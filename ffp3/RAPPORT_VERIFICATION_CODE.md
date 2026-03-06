# Rapport de Vérification du Code - FFP3

**Date**: 2025-01-27  
**Objectif**: Vérification de la cohérence du code et détection de bugs potentiels

## ✅ Points Positifs

1. **Architecture propre** : Séparation claire entre contrôleurs, services, repositories
2. **Gestion des erreurs** : Middleware d'erreur centralisé avec ErrorAlertService
3. **Sécurité** : Validation des noms de tables, utilisation de prepared statements
4. **Pas d'erreurs de linting** : Code conforme aux standards PHP
5. **Dépendances** : Container DI bien configuré

## ⚠️ Problèmes Identifiés

### 1. **BUG CRITIQUE : Cache partagé entre environnements (OutputCacheService)**

**Fichier**: `src/Service/OutputCacheService.php`

**Problème**: Le cache est statique et partagé entre toutes les requêtes, mais il ne tient pas compte de l'environnement (PROD vs TEST). Si une requête PROD et une requête TEST arrivent simultanément, elles pourraient partager le même cache, ce qui retournerait des données incorrectes.

**Lignes concernées**: 25-26, 35-45

```php
// Problème actuel
private static ?array $cache = null;
private static ?int $cacheTimestamp = null;

public function getOutputsState(\PDO $pdo, array $gpioList): array
{
    // Le cache ne vérifie pas l'environnement !
    if (self::$cache !== null && ...) {
        return self::$cache; // ⚠️ Peut retourner des données PROD pour une requête TEST
    }
    // ...
    $table = TableConfig::getOutputsTable(); // Dépend de l'environnement
}
```

**Impact**: 
- Données incorrectes retournées à l'ESP32
- Risque de confusion entre environnements PROD et TEST

**Solution recommandée**:
```php
private static array $cache = []; // Cache par environnement
private static array $cacheTimestamp = [];

public function getOutputsState(\PDO $pdo, array $gpioList): array
{
    $env = TableConfig::getEnvironment();
    $now = time();
    
    // Vérifier cache pour l'environnement spécifique
    if (isset(self::$cache[$env]) && 
        isset(self::$cacheTimestamp[$env]) &&
        ($now - self::$cacheTimestamp[$env]) < self::CACHE_TTL_SECONDS) {
        return self::$cache[$env];
    }
    
    // ... requête BDD ...
    
    // Mettre à jour cache pour l'environnement spécifique
    self::$cache[$env] = $result;
    self::$cacheTimestamp[$env] = $now;
    
    return $result;
}

public function invalidateCache(): void
{
    $env = TableConfig::getEnvironment();
    unset(self::$cache[$env]);
    unset(self::$cacheTimestamp[$env]);
}
```

---

### 2. **BUG POTENTIEL : strtolower() sur des entiers (OutputRepository)**

**Fichier**: `src/Repository/OutputRepository.php`

**Problème**: `strtolower()` est appelé sur `$state` qui peut être un entier, ce qui génère un warning PHP 8+.

**Lignes concernées**: 76, 116, 175, 239

```php
// Problème actuel
$state = $result['state'];
if (is_string($state)) {
    $normalizedState = match (strtolower($state)) { // ⚠️ $state peut être int
        // ...
    };
}
```

**Impact**: 
- Warnings PHP si `$state` est un entier
- Comportement imprévisible

**Solution recommandée**:
```php
$state = $result['state'];
if (is_string($state)) {
    $normalizedState = match (strtolower(trim($state))) {
        'checked', 'true', 'on', '1', 'yes' => 1,
        'unchecked', 'false', 'off', '0', 'no' => 0,
        default => is_numeric($state) ? (int)$state : 0
    };
    $result['state'] = $normalizedState;
} else {
    $result['state'] = (int)$state; // Déjà un entier
}
```

---

### 3. **INCOHÉRENCE : Namespace complet au lieu de use statement**

**Fichier**: `src/Controller/PostDataController.php`

**Problème**: Utilisation de namespace complet `\App\Repository\BoardRepository` au lieu d'un `use` statement en haut du fichier.

**Ligne concernée**: 154

```php
// Incohérent
$boardRepo = new \App\Repository\BoardRepository($pdo);

// Devrait être
use App\Repository\BoardRepository;
// ...
$boardRepo = new BoardRepository($pdo);
```

**Impact**: 
- Code moins lisible
- Incohérence avec le reste du projet

**Solution**: Ajouter `use App\Repository\BoardRepository;` en haut du fichier.

---

### 4. **REDONDANCE : setEnvironment() appelé deux fois**

**Fichier**: `src/Controller/OutputController.php`

**Problème**: Les méthodes `toggleOutput()` et `toggleOutputTest()` appellent `TableConfig::setEnvironment()` alors que le middleware `EnvironmentMiddleware` devrait déjà avoir défini l'environnement.

**Lignes concernées**: 120, 126

```php
public function toggleOutput(Request $request, Response $response): Response
{
    \App\Config\TableConfig::setEnvironment('prod'); // ⚠️ Redondant si middleware déjà appliqué
    return $this->handleToggle($request, $response);
}
```

**Impact**: 
- Code redondant
- Confusion sur qui définit l'environnement

**Note**: Cependant, ces méthodes sont appelées via des routes qui n'ont peut-être pas le middleware. Vérifier dans `public/index.php` si ces routes ont le middleware.

**Vérification nécessaire**: Dans `public/index.php`, les routes `/api/outputs/toggle` et `/api/outputs/toggle-test` n'ont pas de middleware `EnvironmentMiddleware`, donc l'appel est nécessaire. Mais il serait mieux d'ajouter le middleware à ces routes.

---

### 5. **POTENTIEL BUG : Timezone dans BoardRepository**

**Fichier**: `src/Repository/BoardRepository.php`

**Problème**: Utilisation de `UTC_TIMESTAMP()` pour `last_request` mais conversion vers timezone locale dans `formatTimestamp()`. Il y a une incohérence potentielle.

**Ligne concernée**: 102

```php
// Utilise UTC
$sql = "UPDATE Boards SET last_request = UTC_TIMESTAMP() WHERE board = :board";

// Mais convertit vers timezone locale
$utc = new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));
$tz = new \DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'Europe/Paris');
return $utc->setTimezone($tz)->format('d/m/Y H:i:s');
```

**Impact**: 
- Potentielle confusion si d'autres parties du code utilisent `NOW()` au lieu de `UTC_TIMESTAMP()`
- Cohérence à vérifier dans tout le projet

**Recommandation**: Vérifier que toutes les insertions de timestamps utilisent soit `UTC_TIMESTAMP()` soit `NOW()` de manière cohérente.

---

### 6. **POTENTIEL BUG : Conversion de type dans OutputRepository::syncStatesFromSensorData**

**Fichier**: `src/Repository/OutputRepository.php`

**Problème**: Conversion systématique en string (ligne 304) mais certains GPIOs devraient être des entiers selon la logique de normalisation ailleurs.

**Ligne concernée**: 304

```php
// Conversion en string pour tous les GPIOs
$stateValue = (string)$value;
```

**Impact**: 
- Incohérence avec la normalisation effectuée dans `findAll()` et autres méthodes
- Les GPIOs booléens devraient être des entiers (0/1), pas des strings

**Recommandation**: Appliquer la même logique de normalisation que dans `findAll()`:
```php
// Pour les GPIOs booléens (< 100 ou dans [101, 108, 109, 110, 115])
if ($gpio < 100 || in_array($gpio, [101, 108, 109, 110, 115], true)) {
    $stateValue = (string)((int)$value); // Normaliser en entier puis string
} else {
    $stateValue = (string)$value; // Conserver comme string pour email, configs
}
```

---

### 7. **AMÉLIORATION : Gestion d'erreur dans ErrorAlertService**

**Fichier**: `src/Service/ErrorAlertService.php`

**Problème**: Si la création de la table échoue, le service continue silencieusement sans enregistrer les erreurs.

**Lignes concernées**: 301-322

```php
try {
    $pdo->exec($sql);
    $this->logger->info("Table error_alerts créée avec succès");
} catch (\PDOException $e) {
    // ... log mais continue
    // Le service continuera à fonctionner mais les erreurs ne seront pas enregistrées
}
```

**Impact**: 
- Erreurs non enregistrées si la table n'existe pas
- Pas d'alerte pour l'administrateur

**Recommandation**: Ajouter un flag pour désactiver le service si la table ne peut pas être créée, ou au moins logger une alerte critique.

---

## 📋 Résumé des Actions Recommandées

### Priorité HAUTE (Bugs critiques)
1. ✅ **Corriger le cache partagé dans OutputCacheService** - Séparer par environnement
2. ✅ **Corriger strtolower() sur entiers dans OutputRepository** - Vérifier le type avant

### Priorité MOYENNE (Bugs potentiels)
3. ✅ **Uniformiser la conversion de types dans syncStatesFromSensorData** - Appliquer la même logique de normalisation
4. ✅ **Vérifier la cohérence des timestamps** - S'assurer que UTC_TIMESTAMP() est utilisé partout ou NOW()

### Priorité BASSE (Améliorations)
5. ✅ **Ajouter use statement dans PostDataController** - Améliorer la lisibilité
6. ✅ **Améliorer la gestion d'erreur dans ErrorAlertService** - Alerter si la table ne peut pas être créée
7. ✅ **Réviser l'utilisation de setEnvironment()** - Vérifier si le middleware est toujours appliqué

---

## ✅ Vérifications Effectuées

- [x] Pas d'erreurs de linting
- [x] Tous les contrôleurs utilisent l'injection de dépendances
- [x] Tous les repositories utilisent des prepared statements
- [x] Validation des noms de tables (whitelist)
- [x] Gestion d'erreurs centralisée
- [x] Configuration d'environnement cohérente
- [x] Timezone configuré centralement

---

## 🔍 Points à Surveiller

1. **Concurrence**: Le cache statique pourrait poser problème en cas de forte charge avec mélange PROD/TEST
2. **Types de données**: Incohérences entre string et int pour les états GPIO
3. **Timestamps**: Vérifier que tous les timestamps sont gérés de manière cohérente (UTC vs local)

---

**Conclusion**: Le code est globalement bien structuré et sécurisé. Les problèmes identifiés sont principalement des améliorations de robustesse et de cohérence, avec un bug critique concernant le cache partagé entre environnements.
