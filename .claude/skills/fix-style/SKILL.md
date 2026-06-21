---
name: fix-style
description: Auto-fix PHP code style in the n3_serveur project using php-cs-fixer (composer cs:fix), following the project's .php-cs-fixer.php config. Use when composer cs:check fails, when CI reports a "Tests & qualité" / cs:check failure, or when asked to "fix the style/formatting", "format the code", or "make the linter pass".
---

# Correction automatique du style (php-cs-fixer)

Le projet impose php-cs-fixer via la config `.php-cs-fixer.php`. La CI lance `composer cs:check`
(mode dry-run) ; en cas d'échec (code 8), applique la correction automatique.

## Procédure

```bash
composer cs:fix      # applique les corrections (php-cs-fixer fix)
composer cs:check    # confirme : doit afficher « Found 0 of N files » (code 0)
```

Si l'exécution en root est bloquée (« no plugin should be loaded »), préfixe par
`COMPOSER_ALLOW_SUPERUSER=1`.

## Bonnes pratiques

- Lance `cs:fix` puis **relis le diff** : ne committe que les changements de style attendus,
  sans mélanger avec des modifications fonctionnelles non liées.
- N'édite **pas** la config `.php-cs-fixer.php` pour contourner une règle ; corrige le code.
- Cas le plus fréquent ici : ligne vide superflue en fin de fichier (`single_blank_line_at_eof`).
- Après correction, pense au skill `bump-version` si le changement est significatif, puis `qa-gate`.
