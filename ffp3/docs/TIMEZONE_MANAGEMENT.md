# Gestion des Fuseaux Horaires - FFP3 Datas

## 📍 Situation Géographique

Le projet FFP3 présente une particularité géographique importante :

- 🌍 **Projet physique (aquaponie, ESP32)** : Situé à **Casablanca, Maroc** (`Africa/Casablanca`)
- 🖥️ **Serveur web** : Hébergé à **Paris, France** (`Europe/Paris`)

Cette différence crée des implications pour la gestion des timestamps et l'affichage des données.

---

## ⚙️ Configuration Actuelle

### Timezone du Projet

Le projet utilise une configuration **hybride** :

```env
# .env
APP_TIMEZONE=Europe/Paris  # Stockage serveur
```

**Affichage** : `Africa/Casablanca` (heure locale réelle du projet)

### Architecture Timezone

**Backend (PHP)** :
- Configuration appliquée globalement dans `src/Config/Env.php`
- Timezone : `Europe/Paris`
- Tous les timestamps PHP et base de données utilisent ce fuseau

**Frontend (JavaScript)** :
- Configuration globale : `moment.tz.setDefault('Africa/Casablanca')`
- Highcharts configuré avec timezone `Africa/Casablanca`
- Tous les affichages utilisent l'heure de Casablanca

### Impact sur les Timestamps

- ✅ **Stockage PHP/BD** : Tous les timestamps applicatifs sont en **heure serveur** (`APP_TIMEZONE`, défaut `Europe/Paris`) : `Boards.last_request`, `ffp3Outputs*.requestTime`, tables d’alertes (`created_at`, etc.). Utilisation de `NOW()` en SQL ; la session MySQL doit être cohérente avec le timezone PHP (voir configuration hébergement). *Note : les anciennes valeurs de `Boards.last_request` (stockées en UTC avant unification) peuvent s'afficher avec un décalage jusqu'au prochain POST de la board.*
- ✅ **Affichage web** : Dates converties et affichées en heure de Casablanca (`Africa/Casablanca`)
- ✅ **Conversion automatique** : moment-timezone gère la conversion entre les deux fuseaux
- ℹ️ **Décalage** : 0h en hiver, -1h en été (Casablanca en retard sur Paris)

---

## 🕐 Différence Horaire

### Heure d'été (mars à octobre)

- **Paris** : UTC+2 (CEST - Central European Summer Time)
- **Casablanca** : UTC+1 (WEST - Western European Summer Time)
- **Différence** : **+1 heure** (Paris en avance)

### Heure d'hiver (octobre à mars)

- **Paris** : UTC+1 (CET - Central European Time)
- **Casablanca** : UTC+1 (WET avec DST - Western European Time)
- **Différence** : **0 heure** (même heure)

> ⚠️ **Note importante** : Le Maroc a des règles DST (Daylight Saving Time) spécifiques qui peuvent différer de l'Europe, notamment pendant le Ramadan où le DST peut être suspendu.

---

## 🎯 Implications Pratiques

### 1. Affichage Web

Les données affichées sur le dashboard web sont en **heure de Paris**.

**Exemple :**
- L'ESP32 à Casablanca envoie une mesure à **14h00 locale** (Casablanca)
- Le serveur l'enregistre et l'affiche comme **15h00** (Paris, en été)

### 2. ESP32 et Envoi des Données

Deux approches possibles pour l'ESP32 :

#### Option A : ESP32 en heure locale de Casablanca (recommandé)

```cpp
// ESP32 configuré sur Africa/Casablanca
configTime(0, 0, "pool.ntp.org");
setenv("TZ", "Africa/Casablanca", 1);
tzset();
```

✅ **Avantages** :
- Cohérent avec la réalité physique du système
- Les logs ESP32 sont en heure locale
- Debugging plus facile sur site

⚠️ **Attention** : Le serveur affichera les données avec +1h en été

#### Option B : ESP32 synchronisé sur Paris

```cpp
// ESP32 configuré sur Europe/Paris
configTime(0, 0, "pool.ntp.org");
setenv("TZ", "Europe/Paris", 1);
tzset();
```

✅ **Avantages** :
- Cohérence parfaite avec les timestamps affichés
- Pas de conversion nécessaire

❌ **Inconvénients** :
- Les logs locaux de l'ESP32 sont en heure de Paris (confusion sur site)

### 3. Graphiques et Visualisations

Les graphiques Highcharts utilisent `moment-timezone` pour afficher l'heure de Paris :

```javascript
moment.tz.setDefault('Europe/Paris');
```

Si vous souhaitez afficher l'heure de Casablanca dans les graphiques, modifiez dans `aquaponie.twig` :

```javascript
moment.tz.setDefault('Africa/Casablanca');
```

---

## 🔄 Changement de Timezone

### Pour passer à l'heure de Casablanca

Si vous souhaitez que tout le système utilise l'heure de Casablanca :

#### 1. Modifier `.env`

```env
APP_TIMEZONE=Africa/Casablanca
```

#### 2. Modifier les templates Twig

Dans `aquaponie.twig`, `dashboard.twig`, `tide_stats.twig` :

```javascript
// Anciennement
moment.tz.setDefault('Europe/Paris');

// Nouveau
moment.tz.setDefault('Africa/Casablanca');
```

#### 3. ⚠️ Migration des données existantes

**ATTENTION** : Les données déjà en base sont en heure de Paris. Vous avez deux options :

**Option A : Ne rien faire (recommandé)**
- Les anciennes données restent en heure de Paris
- Les nouvelles données seront en heure de Casablanca
- Discontinuité d'1h dans les graphiques historiques

**Option B : Convertir les timestamps (complexe)**
```sql
-- BACKUP D'ABORD !
-- Convertir tous les timestamps de Paris vers Casablanca (-1h en été)
-- NE PAS EXÉCUTER sans validation complète
```

---

## 🎓 Recommandations

### Pour l'Utilisateur Final

Si l'utilisateur principal est à **Casablanca** :
- ✅ **Recommandé** : Passer à `Africa/Casablanca`
- L'affichage sera cohérent avec l'heure locale physique
- Les logs et debugs seront plus intuitifs

### Pour le Développeur

Si le développeur est à **Paris** ou pour éviter la complexité :
- ✅ **Recommandé** : Garder `Europe/Paris`
- Configuration actuelle stable et testée
- Évite les problèmes de migration de données

### Approche Hybride (avancé)

Pour afficher les deux timezones :

```twig
{# Template Twig #}
<p>
    Heure serveur (Paris) : {{ reading_time }}
    <br>
    Heure locale (Casablanca) : 
    <span class="local-time" data-timestamp="{{ reading_time|date('U') }}"></span>
</p>

<script>
// Convertir en JS côté client
document.querySelectorAll('.local-time').forEach(el => {
    const timestamp = el.dataset.timestamp * 1000;
    const casablancaTime = moment(timestamp).tz('Africa/Casablanca').format('YYYY-MM-DD HH:mm:ss');
    el.textContent = casablancaTime;
});
</script>
```

---

## 🔄 Gestion de la Période d'Analyse

### Fenêtre Glissante (Nouveau - v4.7.0)

Le système implémente désormais une **fenêtre glissante** en mode live :

**Comportement** :
- Au chargement initial : Affiche la période demandée (ex: 6 dernières heures)
- En mode HISTORIQUE : La période reste fixe
- En mode LIVE (réception de nouvelles données) :
  - La période glisse automatiquement pour maintenir la durée fixe
  - Par défaut : fenêtre de 6 heures
  - L'heure de début s'ajuste automatiquement quand de nouvelles données arrivent

**Affichage** :
- Badge `HISTORIQUE` : Période fixe, pas de nouvelles données
- Badge `LIVE` : Fenêtre glissante active, nouvelles données en temps réel
- Compteur séparé : "Mesures chargées" (initial) vs "Lectures live reçues"

**Configuration** :
```javascript
// Dans aquaponie.twig
statsUpdater = new StatsUpdater({
    sensors: [...],
    slidingWindow: true,           // Activer fenêtre glissante
    windowDuration: 6 * 3600        // 6h en secondes
});
```

---

## 📝 Résumé

| Élément | Timezone/Configuration | Notes |
|---------|------------------------|-------|
| **Serveur PHP** | Europe/Paris | Stockage uniforme |
| **Base de données** | Europe/Paris | Timestamps stockés |
| **Graphiques Highcharts** | Africa/Casablanca | Affichage converti |
| **StatsUpdater JS** | Africa/Casablanca | Affichage converti |
| **ESP32** | Africa/Casablanca (recommandé) | Heure locale physique |
| **Affichage utilisateur** | Africa/Casablanca | Heure locale réelle |
| **Fenêtre d'analyse** | 6h glissante en mode live | Configurable |

---

## 🔗 Références

- [PHP Timezones](https://www.php.net/manual/en/timezones.php)
- [Moment Timezone](https://momentjs.com/timezone/)
- [Morocco DST Rules](https://www.timeanddate.com/time/zone/morocco)

---

**Dernière mise à jour** : 2025-10-13  
**Version** : 4.7.0

---

## 🆕 Modifications Récentes (v4.7.0)

### Changements Implémentés

1. **Unification du Timezone d'Affichage**
   - ✅ Configuration globale `moment.tz.setDefault('Africa/Casablanca')`
   - ✅ Highcharts configuré avec timezone `Africa/Casablanca`
   - ✅ Tous les affichages cohérents en heure de Casablanca

2. **Fenêtre Glissante en Mode Live**
   - ✅ Implémentation d'une fenêtre glissante de 6h par défaut
   - ✅ Badge LIVE/HISTORIQUE pour distinguer les modes
   - ✅ Compteurs séparés : "Mesures chargées" vs "Lectures live"
   - ✅ Ajustement automatique de la période en temps réel

3. **Filtres Rapides Améliorés**
   - ✅ Utilisation de moment-timezone au lieu de Date() natif
   - ✅ Dates calculées dans le timezone du serveur (Casablanca)
   - ✅ Plus de problèmes de décalage horaire

4. **Documentation et Clarté**
   - ✅ Indication du timezone dans les champs datetime-local
   - ✅ Commentaires clarifiés sur les conversions timestamps
   - ✅ Documentation complète de l'architecture timezone

---


