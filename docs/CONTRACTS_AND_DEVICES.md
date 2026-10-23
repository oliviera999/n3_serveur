# Contrats firmware ↔ serveur & catalogue d'appareils

**Depuis le serveur 6.42.0** (Phase 1 A + D).

## Contrats (`contracts/*.json`)

Fichiers machine-readable, source de vérité déclarative pour chaque famille :

| Fichier | Famille | Firmware |
|---------|---------|----------|
| `contracts/ffp3.json` | Aquaponie | ffp5cs |
| `contracts/msp1.json` | Potager | msp |
| `contracts/n3pp.json` | Élevage | n3pp |
| `contracts/energie.json` | Banc INA226 | energie |
| `contracts/pgl.json` | Poissonglouton | poissonglouton |

Chaque contrat décrit : `sensor`, `endpoints`, `auth`, `fields[]`, `gpio`, `pages`, `tables`, `serverSource`.

### Vérificateur d'alignement

- Service : `App\Service\Contract\ContractAlignmentService`
- Compare les champs du JSON aux `toFloat` / `toInt` / `sanitize` (ou `$params['…']` pour PGL) du PostDataController, et les GPIO aux `*GpioMap`.
- API admin (rôle admin) : `GET /admin/api/contract-alignment` et `GET /admin/api/contract-alignment/{id}`
- UI : section **Cohérence firmware ↔ serveur** sur `/supervision`

Statuts : `ok` (aligné), `warn` (champs code absents du contrat), `error` (champs/GPIO du contrat absents du code ou divergents).

Les contrats vivent dans le submodule **n3_serveur** (`serveur/contracts/`) pour être disponibles en prod après `git pull`.

## Catalogue d'appareils (`iotDevices`)

Table globale d'**instances** de familles déjà connues (pas de nouvelle famille).

- Migration : `migrations/2026_10_iot_devices.sql`
- Init Docker : `docker/mysql/init/93-iot-devices.sql`
- Repository : `IotDeviceRepository` (auto-création + seed des appareils historiques)
- API admin :
  - `GET /admin/api/devices`
  - `POST /admin/api/devices` (wizard Supervision, CSRF)
  - `POST /admin/api/devices/{id}`
  - `POST /admin/api/devices/{id}/delete`

À la création : upsert catalogue, optionnellement `navPages` + ligne `Boards` si board numérique.

Les cartes **Live** de Supervision sont générées depuis les appareils ayant `live_module_id` + `live_health_url`.

### Hors scope Phase 1

- Génération d'une **nouvelle famille** (tables / controllers) — Phase 2 (C)
- Multi-board complet des pages `/meteo`, `/serre` — A+ ultérieur
- Moteur générique `CREATE TABLE` depuis le web — non retenu
