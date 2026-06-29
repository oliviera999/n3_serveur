# API Temps réel MSP1 et N3PP

Les pages données MSP1 (station météo) et N3PP (serre) utilisent le même script client `realtime-updater.js` que la page aquaponie (FFP3). Les endpoints suivants exposent le même contrat JSON que l’API FFP3 pour le mode temps réel.

## MSP1 (station météo)

| Méthode | URL | Description |
|--------|-----|-------------|
| GET | `/msp1/api/realtime/sensors/latest` | Dernière lecture capteurs |
| GET | `/msp1/api/realtime/sensors/since/{timestamp}` | Nouvelles lectures depuis un timestamp Unix |
| GET | `/msp1/api/realtime/system/health` | Santé système (online, last_reading, readings_today, etc.) |
| GET | `/msp1/api/realtime/alerts/active` | Alertes actives (placeholder, retourne `[]`) |
| GET | `/msp1/api/outputs/state` | État des sorties (board par défaut 2) |

**Contrôleur** : `App\Controller\Msp\MspRealtimeApiController`  
**Données** : tables `msp1Data` / `msp1DataTest` (via `TableConfig`).

## N3PP (serre)

| Méthode | URL | Description |
|--------|-----|-------------|
| GET | `/n3pp/api/realtime/sensors/latest` | Dernière lecture capteurs |
| GET | `/n3pp/api/realtime/sensors/since/{timestamp}` | Nouvelles lectures depuis un timestamp Unix |
| GET | `/n3pp/api/realtime/system/health` | Santé système |
| GET | `/n3pp/api/realtime/alerts/active` | Alertes actives (placeholder, retourne `[]`) |
| GET | `/n3pp/api/outputs/state` | État des sorties (board par défaut 3) |

**Contrôleur** : `App\Controller\N3pp\N3ppRealtimeApiController`  
**Données** : tables `n3ppData` / `n3ppDataTest` (via `TableConfig`).

## Format des réponses (aligné FFP3)

- **sensors/latest** : `{ "timestamp": number, "reading_time": string|null, "sensors": { ... } }`
- **sensors/since** : `{ "count": number, "readings": [ { "timestamp", "reading_time", "sensors" }, ... ] }`
- **system/health** : `{ "online", "last_reading", "last_reading_ts", "last_reading_ago_seconds", "uptime_percentage", "readings_today", "average_latency_seconds", "device_ip" }`
  - `last_reading` : chaîne SQL en heure serveur (`APP_TIMEZONE`).
  - `last_reading_ts` : epoch Unix (secondes) de la dernière lecture ; le front l'affiche en heure de Casablanca via `Intl`. `null` si aucune lecture.
- **outputs/state** : `{ "timestamp", "outputs": [ { "id", "gpio", "name", "state", "board" }, ... ] }`

Implémentation : v5.0.58 (contrôleurs créés, repositories `countReadingsToday` ajouté).
