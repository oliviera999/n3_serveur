# Unification de la gestion du fuseau horaire

## Problème résolu

Il y avait un décalage horaire entre les graphiques Highcharts et les données affichées dans le reste de la page. Ce décalage était dû à une gestion incohérente du fuseau horaire entre les différents composants de l'application.

## Changements effectués

### 1. Configuration centralisée du timezone

**Fichier : `src/Config/Env.php`**

Ajout d'une méthode `configureTimezone()` qui est automatiquement appelée lors du chargement des variables d'environnement. Cette méthode configure le fuseau horaire PHP pour toute l'application à `Europe/Paris` (ou la valeur définie dans `APP_TIMEZONE`).

### 2. Variable d'environnement

**Fichiers : `.env` et `env.dist`**

Ajout de la variable `APP_TIMEZONE=Europe/Paris` pour permettre une configuration centralisée du fuseau horaire.

### 3. Mise à jour des contrôleurs

**Fichiers modifiés :**
- `src/Controller/AquaponieController.php`
- `src/Controller/TideStatsController.php`
- `src/Controller/DashboardController.php`

Suppression des appels redondants à `date_default_timezone_set()` dans les constructeurs, car le timezone est maintenant configuré centralement via `Env::load()`.

### 4. Configuration Highcharts

**Fichier : `templates/aquaponie.twig`**

- Ajout de moment.js et moment-timezone (bibliothèques nécessaires pour la gestion des timezones dans Highcharts)
- Configuration globale de Highcharts pour utiliser le fuseau horaire `Europe/Paris`

```javascript
Highcharts.setOptions({
    time: {
        timezone: 'Europe/Paris'
    }
});
```

## Résultat

Maintenant, **tous les éléments de l'application utilisent le même fuseau horaire** :

1. ✅ Les dates affichées dans les templates Twig (via le filtre `|date()`)
2. ✅ Les timestamps dans les graphiques Highcharts
3. ✅ Les calculs de dates dans les contrôleurs PHP
4. ✅ Les formulaires de sélection de dates

Il n'y a plus de décalage horaire entre les graphiques et le reste de la page.

## Note pour le déploiement

Si vous déployez sur un autre serveur, assurez-vous de mettre à jour le fichier `.env` avec la valeur `APP_TIMEZONE=Europe/Paris`. Cette variable est maintenant nécessaire pour assurer la cohérence des dates et heures dans toute l'application.

## Modification ultérieure du timezone

Si vous souhaitez changer de fuseau horaire à l'avenir, il suffit de modifier la valeur de `APP_TIMEZONE` dans le fichier `.env`. Tous les composants de l'application utiliseront automatiquement le nouveau fuseau horaire.

## Fichiers legacy

### `ffp3-data.php`

Ce fichier a été mis à jour pour utiliser la configuration centralisée du timezone via `\App\Config\Env::load()`. La fonction `adjust_to_casablanca()` a été conservée pour compatibilité mais ne fait plus d'ajustement (elle retourne simplement la date sans modification).

### `ffp3-data2.php` et `ffp3-config2.php`

Ces fichiers legacy n'ont pas été mis à jour car ils n'utilisent pas l'autoloader Composer. Si vous utilisez encore ces fichiers, vous devriez :
- Soit les migrer vers les contrôleurs modernes
- Soit ajouter manuellement `date_default_timezone_set('Europe/Paris');` au début de ces fichiers

## Recommandation

Pour bénéficier pleinement de cette unification du timezone, il est recommandé d'utiliser les contrôleurs modernes :
- `/aquaponie` (via `AquaponieController`)
- `/dashboard` (via `DashboardController`)  
- `/tide-stats` (via `TideStatsController`)

Ces contrôleurs utilisent automatiquement la configuration centralisée du timezone.

