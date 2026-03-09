# Contrat commun des APIs temps réel et contrôle (realtime / outputs)

**Date** : 2026-03  
**Périmètre** : APIs consommées par le front (realtime-updater.js, control-sync.js) pour les pages aquaponie (FFP3), potager (MSP1), élevage (N3PP).

---

## 1. API temps réel (sensors + health)

Les trois domaines (FFP3, MSP1, N3PP) exposent le **même contrat** pour que le même script `realtime-updater.js` fonctionne sur toutes les pages données.

### 1.1 Préfixes par domaine

| Domaine | Préfixe (prod) | Exemple base |
|--------|-----------------|--------------|
| FFP3 (aquaponie) | `/api/realtime` (+ variantes `-test`, `3-test`, `3` selon environnement) | `/api/realtime` |
| MSP1 (potager) | `/msp1/api/realtime` | `/msp1/api/realtime` |
| N3PP (élevage) | `/n3pp/api/realtime` | `/n3pp/api/realtime` |

### 1.2 Routes communes

| Méthode | Chemin relatif au préfixe | Description |
|--------|---------------------------|-------------|
| GET | `sensors/latest` | Dernières lectures capteurs (une seule) |
| GET | `sensors/since/{timestamp}` | Nouvelles lectures depuis un timestamp Unix |
| GET | `system/health` | Santé système (online, last_reading, etc.) |
| GET | `outputs/state` | État des sorties GPIO (pour contrôle) |

**FFP3 uniquement** : `GET alerts/active` — liste des alertes actives (placeholder, retourne `[]`).

### 1.3 Format des réponses

#### GET `sensors/latest`

```json
{
  "timestamp": 1234567890,
  "reading_time": "2026-03-09 12:00:00",
  "sensors": {
    "TempAir": 22.5,
    "Humidite": 65,
    ...
  }
}
```

- `reading_time` peut être `null` si aucune donnée.
- `sensors` : objet clé/valeur (noms de capteurs dépendent du domaine : FFP3, MSP1, N3PP ont des champs différents).

#### GET `sensors/since/{timestamp}`

```json
{
  "count": 2,
  "readings": [
    {
      "timestamp": 1234567890,
      "reading_time": "2026-03-09 12:00:00",
      "sensors": { ... }
    }
  ]
}
```

#### GET `system/health`

```json
{
  "online": true,
  "last_reading": "2026-03-09 12:00:00",
  "last_reading_ago_seconds": 45,
  "uptime_percentage": 98.5,
  "readings_today": 240,
  "average_latency_seconds": 3.5,
  "device_ip": "192.168.1.10"
}
```

- `device_ip` : souvent `null` pour MSP1/N3PP.
- `uptime_percentage` : calculé pour FFP3 ; fixe à 100 pour MSP1/N3PP.

#### GET `outputs/state`

```json
{
  "timestamp": 1234567890,
  "outputs": [
    {
      "id": 1,
      "gpio": 16,
      "name": "Pompe",
      "state": 1,
      "board": 2
    }
  ]
}
```

- `state` : entier (0/1) pour GPIO booléens, ou chaîne pour paramètres (email, seuils, etc.).
- `board` : numéro de board (FFP3 peut avoir plusieurs boards ; MSP1 = 2, N3PP = 3).

#### GET `alerts/active` (FFP3 uniquement)

```json
{
  "timestamp": 1234567890,
  "count": 0,
  "alerts": []
}
```

---

## 2. API contrôle des sorties (toggle / state)

### 2.1 Lecture de l’état

- **FFP3** : `GET /api/outputs/state` (ou `/api/outputs-test/state`, etc. selon environnement).
- **MSP1** : `GET /msp1/api/outputs/state`.
- **N3PP** : `GET /n3pp/api/outputs/state`.

Format identique à la section 1.3 (outputs/state).

### 2.2 Commande (toggle)

| Domaine | Route | Body / paramètres | Remarque |
|---------|--------|-------------------|----------|
| FFP3 | `GET/POST /api/outputs/toggle` (+ variantes env) | `id`, `state` (0\|1) | Par identifiant output |
| MSP1 | `POST /msp1/api/outputs/toggle` | `name`, `state` (0\|1), `board` (opt.) | Par nom de sortie |
| N3PP | `POST /n3pp/api/outputs/toggle` | `gpio`, `state` (0\|1), `board` (opt.) | Par GPIO |

Réponse commune en cas de succès :

```json
{
  "success": true,
  "state": 1
}
```

(+ champ `name` pour MSP1, `gpio` pour N3PP, `id` pour FFP3).

### 2.3 Routes legacy (toujours supportées)

- **MSP1** : `GET/POST /msp1/msp1control/msp1-outputs-action.php` (action `outputs_state`, `set` avec `name`/`state`).
- **N3PP** : `GET/POST /n3pp/n3ppcontrol/n3pp-outputs-action.php` (action `outputs_state`, `set` avec `gpio`/`state`).

Les firmwares peuvent continuer à utiliser ces URLs ; le front peut utiliser les routes REST pour un usage unifié.

---

## 3. Variantes d’environnement (FFP3 uniquement)

Pour l’aquaponie, quatre environnements existent : prod, test, test3, s3. Les préfixes sont :

| Environnement | Realtime | Outputs |
|---------------|----------|---------|
| prod | `/api/realtime` | `/api/outputs` |
| test | `/api/realtime-test` | `/api/outputs-test` |
| test3 | `/api/realtime3-test` | `/api/outputs3-test` |
| s3 | `/api/realtime3` | `/api/outputs3` |

Les contrôleurs passent `realtime_api_base` et `outputs_api_base` aux templates (via `RealtimeUrlHelper`) pour éviter de dupliquer l’expression dans chaque vue.

---

## 4. Implémentation côté serveur

- **Contrat unifié** : interface `App\Service\Realtime\RealtimeDataProviderInterface` (getLatestReadings, getReadingsSince, getSystemHealth, getOutputsState, getActiveAlerts).
- **FFP3** : `RealtimeDataService` implémente l’interface ; `RealtimeApiController` étend `AbstractRealtimeApiController`.
- **MSP1** : `MspRealtimeDataProvider` + `MspRealtimeApiController`.
- **N3PP** : `N3ppRealtimeDataProvider` + `N3ppRealtimeApiController`.

Les contrôleurs délèguent au provider injecté ; le format JSON est aligné sur ce document.
