# Rotation de la cle API serveur

> Cette doc explique comment et quand effectuer la rotation de la cle `API_KEY` du serveur unifie n3 IoT.
> Reference : `.cursor/rules/securite-et-secrets.mdc`.

## Quand faire la rotation

- **Exposition averee** : la cle a ete commitee en clair dans un fichier versionne (ce qui a ete le cas avant `v5.1.0`, valeur `fdGTMoptd5CD2ert3`). Une rotation a ete realisee dans le cadre de l'audit serveur (`CHANGELOG.md` v5.1.0).
- **Depart d'un mainteneur** ayant eu acces aux secrets de production.
- **Fuite suspectee** (logs publics, screenshot publique, etc.).
- **Politique** : rotation preventive recommandee tous les 6 mois.

## Procedure de rotation

### 1. Generer une nouvelle cle aleatoire forte

```bash
# Linux/macOS
openssl rand -base64 32

# Windows (PowerShell)
[Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))

# Alternatif (PHP)
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

### 2. Coordonner avec les firmwares

La nouvelle cle doit etre deployee **simultanement** :

- **Serveur** : mise a jour de `API_KEY` dans `serveur/.env` (production : `iot.olution.info`).
- **Firmwares ESP32** :
  - FFP5CS : `firmwires/ffp5cs/include/secrets_config.h` (`Secrets::API_KEY` et, si HMAC actif, `Secrets::API_SIG_SECRET` + `SECRETS_INCLUDE_API_SIG_SECRET`) — non versionne. WiFi/SMTP restent dans `include/secrets.h`.
  - N3PP / MSP1 / ESP32-CAM : fichier partage `firmwires/credentials.h` (copier `credentials.h.example`) — macros `API_KEY` et optionnellement `API_SIG_SECRET`.
  - Poissonglouton : `firmwires/poissonglouton/include/secrets.h` (`PGL_API_KEY`, distinct de `API_KEY` sauf fallback volontaire).

### 3. Plan de bascule (firmwares deployes sur le terrain)

Comme tous les firmwares ne peuvent pas etre reflashes en meme temps, deux strategies :

**A. Bascule rapide (recommande si fuite averee)**

1. Reflasher (OTA si possible) tous les appareils n3-* avec la nouvelle cle.
2. Mettre a jour `API_KEY` en production.
3. Tolerer un blackout court (les firmwares non flashes seront refuses par `401 Cle API invalide`).

**B. Transition douce via HMAC (recommande hors urgence)**

1. Activer le HMAC sur les firmwares (`timestamp` + `signature`, cf. `SignatureValidator`).
2. Une fois tous les firmwares migres : retirer la `API_KEY` legacy, ne garder que HMAC.

### 4. Verifications post-rotation

- Smoke test local Docker (`tools/local-docker.ps1 -Action smoke -AuthMode both`).
- Verifier les logs production (`https://iot.olution.info/public/cronlog.txt`) : absence de `rejet auth api_key code=401` repetes.
- Verifier `Boards.timeLastRequest` pour chaque board (doit s'actualiser apres rotation).

### 5. Purger l'historique git si la cle a ete commitee

Si la cle a effectivement ete versionnee dans un commit public, **rotation seule ne suffit pas** : il faut purger l'historique git.

Outils recommandes :

```bash
# git filter-repo (recommande)
pip install git-filter-repo
git filter-repo --replace-text expressions.txt

# expressions.txt :
fdGTMoptd5CD2ert3==>REDACTED
```

Apres purge : `git push --force` sur toutes les branches publiques. Coordonner avec les autres mainteneurs (re-clonage requis).

## Verification d'absence de cle dans le depot

```bash
# Depuis IOT_n3/
git grep -l "fdGTMoptd5CD2ert3"            # ne doit rien renvoyer
git grep -E "API_KEY\s*=\s*[A-Za-z0-9]{8,}" -- serveur/  # doit n'avoir que .env (non versionne)
```

## References

- `.cursor/rules/securite-et-secrets.mdc` (secrets hors code source)
- `serveur/docs/AUTHENTICATION.md` (modes d'auth serveur)
- `serveur/docs/ENDPOINTS_ESP32_SERVEUR.md` (contrat firmware-serveur)
