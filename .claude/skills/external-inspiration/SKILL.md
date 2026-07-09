---
name: external-inspiration
description: Draw inspiration from well-known GitHub repositories or proven libraries (Slim/Symfony patterns, PSR, reference PHP projects on auth/HMAC, security, observability…) while writing FFP3 Datas code — and cite the source properly. Use when adopting an external pattern/approach/snippet, evaluating how a respected project solves a problem, or adding a Composer dependency, so the borrowing is credited, license-safe, and adapted to the repo's conventions.
---

# S'inspirer de bonnes pratiques externes (avec citation)

Tu es **libre de t'inspirer d'excellentes pratiques** décrites dans des dépôts GitHub connus et
accessibles ou des bibliothèques éprouvées — c'est encouragé pour la qualité et la robustesse du
code. La contrepartie est **toujours** de créditer et de rester propre.

> Règle de référence : section « Inspiration — bonnes pratiques externes » de `CLAUDE.md`
> (et son miroir dans `.cursorrules`).

## Quand utiliser ce skill

- Tu reprends une **approche, un pattern ou un extrait** d'un projet externe (ex. middleware Slim,
  validation de signature HMAC, rate limiting, CSRF, logging Monolog, structuration Repository…).
- Tu regardes **comment un projet reconnu** (Slim, Symfony, une lib PHP populaire) résout un problème
  avant d'écrire ta version.
- Tu envisages d'**ajouter une dépendance Composer** plutôt que réimplémenter.

## Procédure (obligatoire)

1. **Identifier la source** : nom du projet / de la bibliothèque, URL, et version/commit si pertinent.
2. **Vérifier la licence** : ne jamais copier-coller du code sous licence incompatible. Adapter /
   réécrire, mentionner licence + origine. **En cas de doute sur la compatibilité, demander avant
   d'intégrer.**
3. **Adapter, ne pas plaquer** : rester cohérent avec les conventions du dépôt — Slim/Twig/PDO,
   `TableConfig` (jamais de table en dur), PSR-4, `declare(strict_types=1)`, `.php-cs-fixer.php`,
   `phpstan.neon` — plutôt que dupliquer un pattern externe tel quel.
4. **Citer** :
   - dans le **message de commit / la description de PR** (ce dont on s'est inspiré + lien) ;
   - **en docblock / commentaire** juste au-dessus du passage concerné si l'emprunt est localisé,
     avec la licence si du code a été repris.

## Exemple de citation en code

```php
// Inspiré de <projet> (<url>, vX.Y, licence MIT) : fenêtre de validité de signature.
// Adapté à SignatureValidator (HMAC-SHA256, SIG_VALID_WINDOW).
```

## Rappels

- ✅ Préférer une **dépendance Composer** propre à un copier-coller quand la lib existe et est maintenue
  (et lancer `composer audit`).
- ✅ L'inspiration externe ne dispense **jamais** des règles du dépôt (bump `VERSION` + `CHANGELOG.md`,
  qa-gate vert, validation de signature sur tout POST d'API).
