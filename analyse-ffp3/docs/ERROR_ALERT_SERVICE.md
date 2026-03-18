# Service d'Alertes Automatiques sur Erreurs Répétées

**Date:** 2026-01-13  
**Service:** `ErrorAlertService`  
**Statut:** ✅ Implémenté

---

## Vue d'Ensemble

Le service `ErrorAlertService` détecte automatiquement les erreurs qui se répètent de manière anormale et envoie des alertes par email pour éviter que les problèmes passent inaperçus.

---

## Fonctionnalités

### Détection Automatique

- **Analyse des erreurs:** Enregistre toutes les erreurs loggées
- **Normalisation:** Regroupe les erreurs similaires (ex: "Erreur insertion données: Connection refused" → "Erreur insertion données: Connection refused")
- **Comptage:** Compte les occurrences dans une fenêtre de temps (5 minutes par défaut)
- **Seuil:** Déclenche une alerte si ≥ 5 occurrences dans la fenêtre

### Alertes Automatiques

- **Email:** Envoie un email via `NotificationService` quand seuil atteint
- **Cooldown:** Évite le spam (1 heure entre alertes pour la même erreur)
- **Contexte:** Inclut le nombre d'occurrences et la fenêtre de temps

### Nettoyage Automatique

- **Rétention:** Conserve les erreurs pendant 24 heures
- **Nettoyage:** Supprime automatiquement les entrées anciennes (1 fois par heure)

---

## Configuration

### Paramètres (dans `ErrorAlertService.php`)

```php
private const ERROR_THRESHOLD = 5;              // Seuil d'occurrences
private const TIME_WINDOW_SECONDS = 300;        // Fenêtre de temps (5 min)
private const ALERT_COOLDOWN_SECONDS = 3600;     // Cooldown entre alertes (1h)
```

### Personnalisation

Modifier les constantes dans `ffp3/src/Service/ErrorAlertService.php` pour ajuster:
- **Seuil:** Nombre d'occurrences avant alerte
- **Fenêtre:** Période d'analyse
- **Cooldown:** Délai minimum entre alertes

---

## Utilisation

### Intégration Automatique

Le service est automatiquement intégré dans:
- `PostDataController`: Erreurs insertion données
- `HeartbeatController`: Erreurs insertion heartbeat
- `ErrorHandlerMiddleware`: Exceptions non gérées

### Utilisation Manuelle

```php
use App\Service\ErrorAlertService;

// Dans un contrôleur ou service
public function __construct(
    private ErrorAlertService $errorAlert
) {}

// Enregistrer une erreur
$this->errorAlert->recordError('Erreur insertion données', [
    'error' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
]);
```

### Statistiques

```php
// Obtenir les statistiques d'erreurs récentes
$stats = $errorAlert->getErrorStats(24); // 24 dernières heures

foreach ($stats as $stat) {
    echo $stat['normalized_message'] . ': ' . $stat['count'] . " fois\n";
}
```

---

## Base de Données

### Table `error_alerts`

Créée automatiquement au premier appel si elle n'existe pas.

**Structure:**
- `id`: Identifiant unique
- `message`: Message d'erreur complet
- `normalized_message`: Message normalisé (pour regroupement)
- `context`: Contexte JSON
- `created_at`: Date/heure de l'erreur
- `alerted_at`: Date/heure de la dernière alerte envoyée

**Index:**
- `idx_normalized`: Recherche rapide par message normalisé
- `idx_created`: Filtrage par date
- `idx_alerted`: Gestion cooldown

### Migration Manuelle

Si nécessaire, exécuter:
```sql
-- Voir: ffp3/migrations/CREATE_ERROR_ALERTS_TABLE.sql
```

---

## Exemple d'Alerte Email

**Sujet:** 🚨 Alerte: Erreur répétée détectée

**Corps:**
```
Le système a détecté une erreur qui se répète de manière anormale.

Erreur: Erreur insertion données: Connection refused
Occurrences: 7 fois dans les 5 dernières minutes
Seuil déclenchement: 5 occurrences

Veuillez vérifier les logs pour plus de détails.
Fichier de log: cronlog.txt
```

---

## Normalisation des Erreurs

Le service normalise les messages pour regrouper les erreurs similaires:

**Exemples:**
- `"Erreur insertion données: Connection refused"` → `"Erreur insertion données: Connection refused"`
- `"Erreur insertion données: Connection timed out"` → `"Erreur insertion données: Connection timeout"`
- `"SQLSTATE[23000]: Duplicate entry"` → `"Erreur insertion données: Duplicate entry"`

**Patterns détectés:**
- Connection refused
- Connection timeout
- SQL Error
- Unknown column
- Table not found
- Duplicate entry
- Access denied

---

## Logs

Le service logge ses actions:

```
[INFO] Alerte erreur répétée envoyée: message=Erreur insertion données: Connection refused, count=7
[INFO] Alerte erreur répétée en cooldown: message=..., cooldown_remaining=1800s
[INFO] Nettoyage erreurs anciennes: deleted=42
```

---

## Tests

### Test Manuel

1. **Simuler erreurs répétées:**
   ```php
   // Dans un contrôleur
   for ($i = 0; $i < 6; $i++) {
       $this->errorAlert->recordError('Test erreur répétée', ['iteration' => $i]);
       sleep(10); // Attendre 10s entre chaque
   }
   ```

2. **Vérifier alerte:**
   - Vérifier email reçu après 5 occurrences
   - Vérifier logs serveur
   - Vérifier table `error_alerts`

### Test Statistiques

```php
$stats = $errorAlert->getErrorStats(24);
var_dump($stats);
```

---

## Limitations

1. **Base de données requise:** Nécessite accès MySQL
2. **Table auto-créée:** Création automatique au premier appel (peut échouer si permissions insuffisantes)
3. **Cooldown global:** Cooldown par type d'erreur, pas par instance
4. **Pas de priorité:** Toutes les erreurs traitées de la même manière

---

## Améliorations Futures

1. **Priorités:** Niveaux de priorité (critique, warning, info)
2. **Filtrage:** Ignorer certaines erreurs connues
3. **Dashboard:** Interface web pour visualiser les erreurs
4. **Webhooks:** Envoi vers services externes (Slack, Discord, etc.)
5. **Métriques:** Export Prometheus/Grafana

---

**Fin de la documentation**
