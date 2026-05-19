# Generation des icones PWA (outillage local)

Ces scripts sont **uniquement** des outils locaux d'aide a la generation des icones
de l'application web (manifest.json). Ils ne doivent **JAMAIS** etre places dans
`public/` : tout script PHP exposable via HTTP est un risque de RCE/DoS.

## Contenu

- `generate-icons.php` — generateur PHP/GD des icones PNG (8 tailles) avec degrade.
- `generate_icons.py` — variante Python/Pillow.

## Usage

Depuis la racine de `serveur/` (ne pas executer dans `public/`) :

```powershell
# PHP (necessite l'extension GD)
php tools/icons/generate-icons.php

# Python (necessite Pillow : pip install Pillow)
python tools/icons/generate_icons.py
```

Les fichiers `icon-*.png` doivent etre copies vers `public/assets/icons/` apres generation.

## Pourquoi ne pas les laisser dans public/

- Slim 4 / nginx avec PHP-FPM peuvent executer les `.php` accessibles directement
  si la regle de reecriture n'attrape pas la requete (cas mutualise sans .htaccess).
- Un attaquant qui trouverait `generate-icons.php` pourrait declencher une ecriture
  arbitraire sur disque (fichier `icon-<n>.png` ecrase apres generation).
- Le script `generate_icons.py` revele la stack technique inutilement.

Voir aussi : `.cursor/rules/securite-et-secrets.mdc` et `serveur/docs/SECURITE_ROTATION_API_KEY.md`.

## References

- README icones complet : [../../public/assets/icons/README.md](../../public/assets/icons/README.md)
