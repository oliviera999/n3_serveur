# Pilotage par le connecteur MCP — garde-fous

> Version serveur : **6.51.0**. Architecture générale : [`MCP.md`](MCP.md).

Le pilotage permet à Claude de **préparer** puis, après accord explicite de la personne,
d'**appliquer** un changement de consigne sur une sortie d'appareil. Il est implémenté et
testé, mais **désactivé par défaut** et, même activé, limité aux **environnements de test**.

## Conditions cumulatives

1. `MCP_CONTROL_ENABLED=true` dans `.env` (sinon les deux outils ne sont même pas listés).
2. Compte `n3_users` de rôle **operator** ou **admin** (relu en base à chaque requête).
3. Jeton portant le scope **`iot.control`** (demandé à la connexion ; un opérateur dont le
   jeton ne le porte pas reçoit un `403 insufficient_scope` qui déclenche la ré-autorisation).
4. Environnement dans `MCP_CONTROL_ALLOWED_ENVS` — défaut : `test, test3, s3test, n3pp_test,
   msp_test, energie_test`. **`prod` et `s3` ne sont jamais pilotables sans les y ajouter
   explicitement.**

## Déroulé en deux temps

1. **`iot_plan_output_change`** (`family`, `gpio`, `value`, `env`, `reason`) — n'écrit **rien**
   sur l'appareil : vérifie la liste blanche et les plages, lit l'état actuel, calcule
   *avant / après*, liste les **risques biologiques et matériels**, enregistre le plan
   (`mcp_control_plans`, validité **10 min**) et renvoie son `plan_id`.
2. **`iot_apply_plan`** (`plan_id`) — revérifie : même utilisateur, non expiré, **usage unique**
   (réclamation atomique), environnement toujours autorisé, politique, **état inchangé depuis
   le plan**, plafond de nourrissage. Écrit par le **même chemin que la page de contrôle**
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
| ffp3 | 108 / 109 | Nourrissage manuel | +1 repas | **plafond `MCP_FEED_DAILY_MAX` / 24 h (défaut 2)** |
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

## Activer pour un essai (environnement de test)

```ini
MCP_CONTROL_ENABLED=true
MCP_CONTROL_ALLOWED_ENVS=test        # tables ffp3Outputs2 : aquaponie de test uniquement
```

Puis se reconnecter depuis Claude (le scope `iot.control` n'est accordé qu'à la connexion).
Exemple : « Prépare l'allumage de la lumière de l'aquaponie de test » → plan → « oui » →
application. Vérifier dans `/aquaponie-control-test` et dans le journal `[control-audit]`.

> Étendre à la production (`prod`, `s3`) est une décision humaine : à ne faire qu'en
> connaissance de cause, idéalement avec un compte opérateur dédié.
