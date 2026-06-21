---
name: qa-gate
description: Run the full local quality gate for the FFP3 Datas PHP project, matching CI exactly — php-cs-fixer style check, PHPStan static analysis, the PHPUnit unit suite, and the Composer security audit. Use BEFORE committing or opening a PR, or whenever asked to "check CI", "verify the build", "make sure it's green", or after non-trivial PHP changes.
---

# QA Gate — vérification pré-commit (équivalent CI)

Reproduit localement ce que `.github/workflows/ci.yml` exécute. Lance les étapes **dans cet ordre**
et **n'arrête pas au premier échec** : collecte tous les problèmes puis fais un rapport groupé.

## Étapes

```bash
composer cs:check     # 1. Style (php-cs-fixer --dry-run --diff)
composer analyse      # 2. PHPStan niveau 6
composer test:unit    # 3. Suite unitaire PHPUnit
composer audit        # 4. Vulnérabilités des dépendances
```

`composer test:integration` (suite d'intégration) nécessite une base **MySQL** : ne la lance que si
une base est configurée, sinon signale-le simplement.

## Interprétation

- **cs:check** échoue (code 8) → propose `composer cs:fix` (voir le skill `fix-style`) puis relance.
- **analyse** échoue → corrige le code de préférence à l'ajout dans `phpstan-baseline.neon`.
  Les erreurs `alwaysTrue` / `alwaysFalse` signalent souvent une condition redondante ou du code mort.
- **audit** : les CVE doivent être corrigées (`composer update <paquet>`). Les paquets seulement
  **abandonnés** (ex. `web-token/jwt-*`) ne font pas échouer la commande — les mentionner sans bloquer.
- **test:unit** : tout doit passer.

## Notes d'environnement

- Si php-cs-fixer refuse de tourner en root (« no plugin should be loaded »), préfixe par
  `COMPOSER_ALLOW_SUPERUSER=1`.
- Ne considère la tâche comme finie que si **les 4 étapes** sont vertes (intégration mise à part).
