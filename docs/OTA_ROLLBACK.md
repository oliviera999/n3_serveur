# Rollback OTA — procédure serveur

> **But :** pouvoir revenir à une version de firmware **connue comme saine** sans
> re-flash physique, si une publication OTA se révèle mauvaise. C'est le **prérequis
> non bloquant** à l'activation future de l'enforcement de signature OTA
> (`N3_OTA_REQUIRE_SIGNATURE`) — voir
> `n3_firmwires/docs/OTA_SIGNATURE_ENFORCEMENT_PLAN.md`.

## Deux niveaux de rollback (complémentaires)

1. **Firmware — auto-rollback ESP-IDF** (`shared/n3_common/n3_ota_rollback`, opt-in
   `N3_OTA_ROLLBACK_ENABLE`). Si la **nouvelle** image ne boote pas / échoue son
   auto-test, le bootloader revient automatiquement à l'image précédente. Inerte
   sans le flag + `CONFIG_BOOTLOADER_APP_ROLLBACK_ENABLE`. Ne protège **que** du cas
   « la nouvelle image ne démarre pas », pas du cas « elle démarre mais est mauvaise ».
2. **Serveur — repointage de la métadonnée** (ce document). Pour le cas « l'image
   boote et se valide, mais est fonctionnellement mauvaise » : on **re-sert** la
   version antérieure. C'est ce que fournit `bin/ota-rollback.php`.

## Outil : `bin/ota-rollback.php`

Sans dépendance à la base ; agit uniquement sur les fichiers de `ota/`, et
uniquement quand on l'invoque (non bloquant).

```bash
# Lister les cibles gérées + version actuellement servie
php bin/ota-rollback.php --list

# Capturer l'état servi courant (À FAIRE MAINTENANT pour chaque cible saine)
php bin/ota-rollback.php --target n3pp --snapshot            # label = version courante
php bin/ota-rollback.php --target cam  --snapshot --label cam-2.64

# Voir les snapshots disponibles
php bin/ota-rollback.php --target n3pp --snapshots

# Restaurer (rollback) — auto-sauvegarde l'état courant avant écrasement
php bin/ota-rollback.php --target n3pp --to 4.55 --dry-run   # simulation
php bin/ota-rollback.php --target n3pp --to 4.55             # exécution
```

**Cibles automatisées :** `n3pp`, `n3pp-test`, `msp`, `msp-test`, `cam`
(métadonnée + binaire(s) dans un sous-dossier propre `ota/<cible>/`).

Les snapshots sont stockés sous `ota/<cible>/history/<label>/` (metadata.json +
firmware.bin, y compris les sous-dossiers `msp1/n3pp/ffp3` pour `cam`). La
restauration est **atomique** (écriture tmp + `rename`) et **auto-sauvegarde**
l'état courant avant de l'écraser, donc un rollback est lui-même annulable.

## ffp5cs (esp32-wroom / esp32-s3) — rollback via Git

La cible ffp5cs a une topologie différente (métadonnée `ota/metadata.json` à la
racine + binaires dans des dossiers frères `esp32-wroom*` / `esp32-s3*`), donc
elle n'est **pas** automatisée par l'outil. Les binaires OTA étant **versionnés
dans Git**, le rollback se fait par Git :

```bash
# Historique de la métadonnée ffp5cs
git log --oneline -- ota/metadata.json ota/esp32-wroom/firmware.bin

# Restaurer un état antérieur connu (métadonnée + binaire)
git checkout <commit-sain> -- ota/metadata.json ota/esp32-wroom/firmware.bin
git commit -m "rollback OTA ffp5cs -> <version saine>"
```

> ⚠️ **Downgrade :** un device n'accepte une MAJ que si la version servie est
> **supérieure** à la sienne (garde-fou `guard_version` de `publish_ota.py`). Pour
> forcer un vrai retour arrière sur des devices déjà passés à la mauvaise version,
> republier le **binaire sain sous un numéro de version supérieur** (roll-forward),
> plutôt que de compter sur un downgrade. Le rollback serveur ci-dessus corrige
> surtout ce qui est **servi aux devices pas encore à jour**.

## Bonnes pratiques

- **Snapshoter maintenant** l'état sain courant de chaque cible : ça crée le point
  de retour avant toute future publication ou avant d'activer l'enforcement.
- Après chaque publication réussie et validée sur banc, `--snapshot` la nouvelle
  version (le script `publish_ota.py` archive aussi automatiquement l'ancienne).
- Toujours tester avec `--dry-run` avant un rollback en production.
