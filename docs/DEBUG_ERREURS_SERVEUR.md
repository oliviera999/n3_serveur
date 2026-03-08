# Debug erreurs serveur — processus et logs en production

Ce document décrit comment diagnostiquer les erreurs du serveur IoT n3 en production sans accès SSH, en s’appuyant sur les logs exposés en lecture (cronlog, error_log) et sur le script d’analyse.

## URLs des logs en production

| Log | URL | Description |
|-----|-----|-------------|
| Cronlog (Monolog) | https://iot.olution.info/public/cronlog.txt | Log applicatif (CRON, erreurs métier). |
| error_log (public) | https://iot.olution.info/public/error_log | Log PHP / erreurs web (public du projet). |
| Variante sous /ffp3 | https://iot.olution.info/ffp3/public/error_log | Même fichier si la racine du site est sous `/ffp3`. |
| Analyse des erreurs | https://iot.olution.info/ffp3/admin/analyze-errors (avec auth / token) | Résumé des error_log (même protection que /admin/clear-cache). |

Les fichiers `error_log` à la racine du projet (hors `public/`) ne sont pas accessibles directement par URL ; le script d’analyse les agrège s’il est exécuté sur le serveur.

## Processus de debug avec une référence d’erreur

Quand l’utilisateur voit un message du type « Une erreur serveur est survenue » avec une **référence** (ex. `Référence : bb3262da436c`) :

1. **Récupérer le contenu des logs**
   - Ouvrir le cronlog : https://iot.olution.info/public/cronlog.txt  
   - Ouvrir l’error_log : https://iot.olution.info/public/error_log (ou `/ffp3/public/error_log` selon la config).

2. **Rechercher la référence**
   - Dans le texte, chercher la chaîne `[<référence>]` (ex. `[bb3262da436c]`).
   - Les lignes correspondantes contiennent : méthode HTTP, URL, classe d’exception, message, fichier, ligne, et éventuellement la stack trace.

3. **Format des lignes dans error_log (erreurs 500)**
   - Ligne de résumé :  
     `[YYYY-MM-DD HH:MM:SS] [n3 500] [<id>] <METHOD> <URI> — <ExceptionClass>: <message> in <file>:<line>`
   - Ligne suivante (trace) :  
     `[YYYY-MM-DD HH:MM:SS] [n3 500] [<id>] Trace: <stack trace>`

4. **Identifier la cause**
   - Fichier/ligne : erreur de code, template manquant, etc.
   - Message : colonne BDD manquante, fichier introuvable, etc.
   - Proposer ou appliquer le correctif, puis redéployer si besoin.

## Autres motifs utiles dans les logs

- **PHP Fatal error** : erreur fatale PHP (syntaxe, classe manquante, etc.).
- **FFP3 404** ou **n3-iot 404** : requête vers une route inexistante (méthode + URI loguées).
- **\[ERROR\] Exception non gérée** / **Erreur insertion** : dans le cronlog, erreurs applicatives récentes.

## Script d’analyse des erreurs

Un script permet d’obtenir un **résumé** des erreurs (comptages par type, dernières lignes pertinentes) sans ouvrir manuellement les fichiers :

- **En production (navigateur)** :  
  `https://iot.olution.info/ffp3/admin/analyze-errors`  
  Protégé par la même authentification que les autres routes /admin/ (session ou token, voir CLEAR_CACHE_OPTIONS.md). Avec token : `?token=<ADMIN_TOKEN>`.

- **En local / SSH** :  
  `php tools/analyze_errors.php` (depuis le dossier ffp3) ou `php serveur/ffp3/tools/analyze_errors.php` depuis la racine du dépôt.

Le script lit `public/error_log` et, si accessible, `../error_log` (racine du projet), puis affiche un résumé (nombre d’erreurs par type, dernières lignes avec `[n3 500]`, Fatal, 404).

## Processus automatisé (agent / diagnostic)

Toute investigation d’erreur 500 ou d’exception serveur doit inclure :

1. La consultation du **cronlog** (lien ci‑dessus) pour retrouver la référence si elle est fournie, ou les dernières lignes `[ERROR]` / `Erreur insertion`.
2. La consultation des **error_log** (URLs ci‑dessus) pour les lignes `[n3 500]`, `PHP Fatal`, `FFP3 404`.
3. Si disponible, l’utilisation du **script d’analyse** pour un résumé rapide.

Voir aussi la règle Cursor `debug-serveur.mdc` et ce document pour le détail du processus et des formats.

## Précautions

- Les logs exposés en lecture peuvent contenir des chemins serveur et des stack traces. L’accès est réservé à un environnement de confiance (prod n3) ; ne pas ouvrir ces URLs sur des postes non sécurisés.
- Les fichiers `error_log` ne sont pas versionnés (`.gitignore`) ; seules les règles d’accès et la documentation sont dans le dépôt.
