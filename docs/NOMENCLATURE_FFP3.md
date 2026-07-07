# Nomenclature « ffp3 » / « ffp5cs » / « ffp5 »

> Dépôt **n3_serveur** (plateforme PHP « FFP3 Datas »). Document jumeau côté firmware :
> `n3_firmwires/docs/NOMENCLATURE_FFP3.md`. Les deux doivent rester cohérents.

Le radical **`ffp3`** est fortement surchargé. Ce document fixe le vocabulaire canonique côté
serveur, la correspondance avec le firmware, et liste les chantiers volontairement différés.

## 1. Vocabulaire canonique

| Terme | Ce que ça désigne | Niveau |
|-------|-------------------|--------|
| **`ffp3`** | Le **système supervisé** de l'aquaponie **côté serveur** : tables `ffp3Data*`/`ffp3Outputs*`/`ffp3Heartbeat*`, contrôleurs `App\Controller\Ffp3\`, routes `/post-data*`, cible OTA. C'est le nom stable et **de production**. | serveur / données |
| **`ffp5cs`** | Le **firmware** qui alimente ce système (dépôt n3_firmwires, dossier `ffp5cs/`, ESP32 WROOM/S3). N'apparaît dans aucun identifiant de code serveur — seulement en commentaire, doc et données de test. | firmware / matériel |
| **`ffp5`** | Nom de **canal OTA** du firmware ffp5cs (`ffp5-wroom`), servi malgré tout sous le chemin `/ffp3/ota/`. | déploiement OTA |

**Règle d'or : on clarifie, on ne renomme pas le socle.** Les tables `ffp3*` portent des données
de production ; on documente l'équivalence firmware↔serveur plutôt que d'aligner les noms.

## 2. Comment le firmware ffp5cs est identifié côté serveur

**Pas par son nom.** La famille de tables est choisie par la **route** empruntée (via
`EnvironmentMiddleware` → `TableConfig::setEnvironment()`), jamais par un champ de la requête.

| Route POST (± préfixe `/ffp3/`) | Env (`TableConfig`) | Table Data | Board | Nature |
|---|---|---|---|---|
| `/post-data` | `prod` | `ffp3Data` | 1 | **production** WROOM |
| `/post-data-test` | `test` | `ffp3Data2` | 1 | test WROOM |
| `/post-data3-test` | `test3` | `ffp3Data3` | 4 | test |
| `/post-data3` | `s3` | `ffp3DataS3` | 5 | **production** ESP32-S3 |
| `/post-data-s3-test` | `s3test` | `ffp3DataS3Test` | 6 | test S3 |

- ⚠️ **`s3` est de la PRODUCTION** (matériel ESP32-S3) : `TableConfig::isTest()` → `false`. Le
  suffixe `S3` renvoie à la **carte**, pas à un environnement de staging ni à AWS S3.
- Le serveur accepte les routes **avec et sans** le préfixe `/ffp3/` (le firmware l'a retiré en
  v13.87 : les GET `/ffp3/*` renvoyaient un 301 Apache non suivi par l'ESP32).

## 3. Le champ POST `sensor`

- Le firmware envoie `sensor="ffp3"` (identité **système**), depuis firmware **v15.09**. Avant, il
  envoyait le type de carte (`esp32-wroom`/`esp32-s3`) ; les tests serveur utilisaient `ffp5cs` —
  trois conventions divergentes, désormais unifiées sur `ffp3`.
- Côté serveur, `sensor` est **journalisé et stocké** (`PostDataController`, tronqué à 30 car.)
  mais **jamais validé ni utilisé pour router** : l'environnement/table vient de la route. Un
  `sensor` erroné n'a aucun effet fonctionnel aujourd'hui (cf. chantier différé §5).

## 4. Les autres sens de « ffp3 » (à ne pas confondre)

1. **Galerie caméra** : le firmware `uploadphotosserver -e ffp3` poste vers `/ffp3gallery/`
   (photos, `board=5`) — sous-système **distinct** des tables capteurs `ffp3Data*`.
2. **Dépôt serveur PHP** : le firmware embarque ce dépôt (n3_serveur) comme sous-module
   `ffp5cs/ffp3` → `github.com/oliviera999/ffp3.git`.
3. **Contrat d'auth HMAC générique** : côté firmware, `shared/n3_data` nomme « FFP3 » sa version
   moderne du protocole HMAC, réutilisée par `msp`/`n3pp` — sans lien avec le système aquaponie.

## 5. Chantiers différés (non traités volontairement)

| Chantier | Idée | Statut / raison |
|---|---|---|
| **Validation `sensor ↔ env`** | Logger un **warning** (jamais un rejet) dans `PostDataController` si `sensor` reçu ≠ identité attendue pour l'env de la route, afin de détecter un firmware mal configuré qui écrirait dans la mauvaise table `ffp3*`. | **Différé, recommandé en *log-only*.** Ne jamais rejeter (casserait la prod / firmwares hétérogènes). À faire après stabilisation de `sensor="ffp3"`. |
| **Aligner le canal OTA `ffp5` sur `ffp3`** | Radical unique. | **Documenté seulement.** Touche le pipeline OTA firmware → risque de coupure de mise à jour. |
| **Renommer le dossier firmware `ffp5cs/` → `ffp3/`** | Aligner firmware↔serveur. | **Rejeté.** Casse sous-module, manifest, CI, scripts. |
| **Renommer les tables `ffp3Data*` → `ffp5*`** | Aligner serveur↔firmware. | **Rejeté.** Migration de données de prod (100k+ lignes), réécriture `TableConfig`/repos/dashboards, risque de perte de données. |
| **Renommer le « contrat HMAC FFP3 »** | Il n'est pas spécifique à ffp3. | **Rejeté.** Surface trop large (tous firmwares + `SignatureValidator`) pour un gain cosmétique. |
