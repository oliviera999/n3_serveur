# Pilotage par le connecteur MCP — garde-fous

> Version serveur : **6.53.0**. Architecture générale : [`MCP.md`](MCP.md).

Le pilotage permet à Claude de **préparer** puis, après accord explicite de la personne,
d'**appliquer** un changement de consigne sur une sortie d'appareil. Il est implémenté et
testé, mais **désactivé par défaut** et, même activé, limité aux **tables de test** sauf
`MCP_CONTROL_ALLOW_PRODUCTION`. Depuis 6.53.0, un **pilotage direct** (`iot_set_output`)
existe pour l'admin si `MCP_CONTROL_REQUIRE_PLAN=false`.

> ⚠️ **Correctif de sécurité (relecture de la PR #100).** Un environnement n'est « de test » que
> pour **sa** famille : en `n3pp_test`, `msp_test` ou `energie_test`, la table outputs FFP3
> résolue par `TableConfig` est **`ffp3Outputs` — l'aquaponie réelle** ; en `test`, `test3`,
> `s3test`, les tables N3PP / MSP1 restent celles de production. La première version
> autorisait des *environnements* et pouvait donc écrire en production depuis un
> « environnement de test ». Le pilotage autorise désormais des **cibles** `famille:env`, et
> chaque cible est jugée sur la **table réellement écrite**.

## Conditions cumulatives

1. `MCP_CONTROL_ENABLED=true` dans `.env` (sinon les deux outils ne sont même pas listés).
2. Compte `n3_users` de rôle **operator** ou **admin** (relu en base à chaque requête).
3. Jeton portant le scope **`iot.control`** (demandé à la connexion ; un opérateur dont le
   jeton ne le porte pas reçoit un `403 insufficient_scope` qui déclenche la ré-autorisation).
4. Cible `famille:env` listée dans **`MCP_CONTROL_ALLOWED_TARGETS`**. Défaut : seulement les
   couples dont la table est réellement de test (vérifié avec `TableConfig`, test
   `McpToolsTest::testControlMatrixNeverTouchesProduction`, 3 familles × 8 environnements) :

   | Cible | Table écrite |
   |---|---|
   | `ffp3:test` | `ffp3Outputs2` |
   | `ffp3:test3` | `ffp3Outputs3` |
   | `ffp3:s3test` | `ffp3OutputsS3Test` |
   | `n3pp:n3pp_test` | `n3ppOutputsTest` |
   | `msp1:msp_test` | `msp1OutputsTest` |

5. La table réellement ciblée (`ControlTarget`, résolue par `TableConfig`) **n'est pas une table
   de production** (`ffp3Outputs`, `ffp3OutputsS3`, `n3ppOutputs`, `msp1Outputs`). Une cible
   listée qui vise la production est **refusée** (`production_target_refused`) au plan **et**
   à l'application, sauf `MCP_CONTROL_ALLOW_PRODUCTION=true` (voir plus bas).

## Déroulé en deux temps

1. **`iot_plan_output_change`** (`family`, `gpio`, `value`, `env`, `reason`) — n'écrit **rien**
   sur l'appareil : vérifie la liste blanche et les plages, lit l'état actuel, calcule
   *avant / après*, liste les **risques biologiques et matériels**, enregistre le plan
   (`mcp_control_plans`, validité **10 min**) et renvoie son `plan_id`.
2. **`iot_apply_plan`** (`plan_id`) — revérifie : même utilisateur, non expiré, **usage unique**
   (réclamation atomique), **cible toujours autorisée et toujours non-production** (la
   configuration a pu changer), même table qu'au plan, politique, **état inchangé depuis le
   plan** — relu sous **verrou de ligne** (`SELECT … FOR UPDATE` dans une transaction, jusqu'à
   l'écriture) — et plafond de nourrissage. Écrit par le **même chemin que la page de contrôle**
   (`OutputService` / repositories outputs, source `web` — donc protégée de l'écrasement par
   le POST firmware pendant la fenêtre de priorité) et journalise via `ControlAuditLogger`
   (`[control-audit] who=mcp:<utilisateur> module=mcp:<famille> …`, avant/après, plan_id).

Les descriptions d'outils demandent au modèle de montrer le plan et d'obtenir l'accord
explicite avant l'étape 2 ; l'annotation MCP `destructiveHint: true` de `iot_apply_plan`
fait demander confirmation par les clients Claude.

## Liste blanche (`src/Mcp/Control/ControlPolicy.php`)

| Famille | GPIO | Sortie | Valeurs admises | Garde-fou |
|---|---|---|---|---|
| ffp3 | 2 | Chauffage eau | 0 / 1 | — |
| ffp3 | 15 | Lumière | 0 / 1 | — |
| ffp3 | 16 | Pompe aquarium | **1 uniquement** | **Arrêt interdit** (voir ci-dessous) |
| ffp3 | 18 | Pompe réserve | 0 / 1 | sécurités firmware conservées |
| ffp3 | 117 | Forçage pompe aquarium | 0 (auto) / 1 (forcer ON) | **2 (forcer OFF) interdit** |
| ffp3 | 104 | Consigne chauffage | **18–28 °C** | avertissement si écart > 2 °C |
| ffp3 | 105 / 106 / 107 | Heures de nourrissage | 5–11 / 11–15 / 15–21 | — |
| ffp3 | 108 / 109 | Nourrissage manuel | +1 repas | **plafond `MCP_FEED_DAILY_MAX` / 24 h (défaut 2), compté par TABLE CIBLE** (deux environnements qui écrivent dans le même bac partagent le compteur ; GPIO 108 et 109 cumulés) |
| ffp3 | 116 | Durée de veille | 60–3600 s | — |
| n3pp | 102 | Seuil sol sec | 0–4095 | — |
| n3pp | 104 | Heure d'arrosage | 0–23 | — |
| n3pp | 105 | Durée d'arrosage | **1–20 s** | — |
| n3pp | 107 | Durée de veille | 60–86400 s | — |
| msp1 | 104 / 105 | Angles servo | 40–145° / 1–179° | butées mécaniques |
| msp1 | 107 | Durée de veille | 60–86400 s | — |
| msp1 | 111 | Tracker automatique | 0 / 1 | — |

**Exclus définitivement** (aucun GPIO, aucun outil) : OTA, redémarrage (`resetMode` 110),
adresses e-mail (100/101), politique de notification, suppression de photos, administration
des utilisateurs, `WakeUp`, seuils de niveau d'eau, angles servo de nourrissage.

### Pourquoi l'arrêt de la pompe aquarium est interdit

Le seul mécanisme de rétablissement borné existant est le flag CRON
`pump_restart_scheduled.flag` (redémarrage 5 min après une coupure « sécurité marée »). Il
appartient à la sécurité marée du CRON, et un forçage GPIO 117 actif l'annulerait. Plutôt
qu'une coupure dont le rétablissement n'est pas garanti (arrêt de l'oxygénation et de la
filtration → risque de mortalité), MCP ne peut que **démarrer** la pompe ou repasser le
forçage en **auto**.

## Activer pour un essai (table de test uniquement)

```ini
MCP_CONTROL_ENABLED=true
MCP_CONTROL_ALLOWED_TARGETS=ffp3:test        # écrit UNIQUEMENT dans ffp3Outputs2
# MCP_CONTROL_ALLOW_PRODUCTION reste absent / false
```

Avec cette configuration, aucune combinaison d'arguments ne peut écrire dans une table de
production : toute autre cible est refusée (`target_not_allowed`), et une cible de production
le serait même listée (`production_target_refused`).

Puis se reconnecter depuis Claude (le scope `iot.control` n'est accordé qu'à la connexion).
Exemple : « Prépare l'allumage de la lumière de l'aquaponie de test » → plan → « oui » →
application. Vérifier dans `/aquaponie-control-test` et dans le journal `[control-audit]`.

## ⛔ Piloter la production : `MCP_CONTROL_ALLOW_PRODUCTION`

> **AVERTISSEMENT — installation réelle, êtres vivants.** Ce drapeau permet à un modèle d'IA,
> sur demande d'un opérateur, de modifier la consigne des pompes, du chauffage, de la lumière et
> du nourrissage de l'aquaponie, de la serre et de la station **réelles**. Une erreur de
> consigne peut tuer des poissons ou des plantes. **Laisser à `false`** sauf décision humaine
> explicite, pour une durée limitée, avec un compte opérateur dédié.

Les deux conditions sont nécessaires :

```ini
MCP_CONTROL_ALLOWED_TARGETS=ffp3:prod        # cible explicitement listée…
MCP_CONTROL_ALLOW_PRODUCTION=true            # …ET drapeau explicite
```

Le drapeau **ne lève aucun garde-fou** biologique à lui seul : l'arrêt de la pompe aquarium, le
« forcer OFF », les valeurs hors plage et le dépassement du plafond de nourrissage restent
refusés sauf `override_bio_safeguards` + confirmation par code (ou
`MCP_CONTROL_BIO_SAFEGUARDS=false`). Les plans visant la production portent `is_production: true`,
la table ciblée et un avertissement.

## Pilotage direct (`iot_set_output`)

Conditions : `MCP_CONTROL_ENABLED=true`, `MCP_CONTROL_REQUIRE_PLAN=false`, compte **admin**,
scope `iot.control`. Même validation (`ControlPolicy::evaluate`), verrou de ligne et
`ControlAuditLogger` que `iot_apply_plan`. Renvoie `before` / `after`.

### Garde-fous biologiques réglables

| Variable | Défaut | Effet |
|---|---|---|
| `MCP_CONTROL_BIO_SAFEGUARDS` | `true` | Couper ignore les règles bio (limites matérielles restent) |
| `MCP_CONTROL_HEATING_MIN` / `MAX` | 18 / 28 | Plage consigne GPIO 104 |
| `MCP_FEED_DAILY_MAX` | 2 | Nourrissages MCP / 24 h / bac |
| `MCP_CONTROL_PUMP_STOP_MAX_MINUTES` | 0 | Arrêt pompe avec rétablissement auto (0 = interdit) |

Levée au cas par cas : `override_bio_safeguards` + `override_reason` (10–200 car.) → catégorie
`bio_override` → confirmation par code.

### Arrêt pompe et rétablissement

- Avec `restore_after_minutes` (1…`PUMP_STOP_MAX_MINUTES`) : écriture GPIO 117 = 2, ligne dans
  `mcp_scheduled_restores` ; `McpRestoreCommand` (chaque minute) remet 117 = 0 **puis** 16 = 1
  (le POST firmware épingle 16 à 0 tant que le forçage OFF est actif). L'échéance n'est marquée
  faite que si les deux écritures ont abouti. Un nouvel arrêt, ou un passage en maintenance,
  annule le minuteur encore en attente : un délai plus court ne peut pas rallumer la pompe.
- **Maintenance** (`maintenance=true`) : pas de rétablissement ; exige override + catégorie
  `pump_maintenance` + code.

## Confirmation par code

Voir [`MCP.md` § 12](MCP.md). Catégories sensibles aussi : reset, OTA, photos, SQL DDL /
écritures sans WHERE / outputs de production / admin comptes SQL.
