# ADR — Double stockage configuration (FFP3 vs MSP/N3PP)

**Date** : 2026-07-05  
**Statut** : accepté

## Contexte

FFP3 stocke la configuration à la fois dans les **lignes de mesures** (`ffp3Data*`, colonnes `mail`, seuils…) et dans la table **outputs** (`ffp3Outputs*`, GPIO 100–125). MSP/N3PP utilisent principalement **outputs** + POST périodique pour l'état capteurs.

## Décision

| Champ / notion | Canonique FFP3 | Canonique MSP/N3PP |
|----------------|----------------|---------------------|
| Email alerte | GPIO 100 outputs + colonne POST | GPIO 100 outputs |
| Notifications firmware | GPIO 101 (`mailNotif` mode) | GPIO 101 |
| Politique notif UI | GPIO 124/125 (FFP3) ou 108/109 (MSP/N3PP) server-only | GPIO 108/109 server-only |
| État pompe / actionneurs | GPIO 2/15/16/18 outputs + sync POST | N3PP GPIO 12/13 ; MSP sans pompe |
| Dernière mesure capteurs | Table `*Data` | Table `*Data` |

**Pas de migration** vers le modèle FFP3 double-stockage pour MSP/N3PP en 2026 — le POST firmware reste la source des séries capteurs ; les outputs restent la source des commandes GET.

## Conséquences

- `N3ppPostDataController` synchronise `etatPompe` POST → GPIO 12 outputs (P1-05).
- Les graphiques N3PP filtrent le bruit qualitatif côté requête (`qualityFilterSql`), sans DELETE BDD.
- Alias `.php` dépréciés ; URLs Slim canoniques documentées dans `ENDPOINTS_ESP32_SERVEUR.md`.
