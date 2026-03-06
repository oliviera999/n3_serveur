# 🚀 Démarrage Rapide - FFP3 Aquaponie v4.0.0

## ✅ Ce qui a été implémenté

### Phase 2 : Temps Réel
- ✅ API REST pour polling des données
- ✅ Système de polling JavaScript (15s)
- ✅ Badge LIVE avec indicateur de connexion
- ✅ Dashboard système en temps réel
- ✅ Notifications toast (info, success, warning, error)

### Phase 4 : PWA & Mobile
- ✅ Progressive Web App (manifest.json)
- ✅ Service Worker avec cache
- ✅ CSS Mobile-First responsive
- ✅ Mobile Gestures (swipe, pull-to-refresh)
- ✅ Bottom navigation bar
- ✅ Mode offline basique

---

## 🎯 Actions immédiates (10 minutes)

### 1. Installer les dépendances

```bash
cd ffp3datas
composer update
```

**Résultat attendu** : Installation de `minishlink/web-push` et `bacon/bacon-qr-code`

### 2. Générer les icônes PWA

**Option rapide** (ImageMagick) :
```bash
cd public/assets/icons

# Créer icônes placeholder vertes avec texte "FFP3"
for size in 72 96 128 144 152 192 384 512; do
  convert -size ${size}x${size} xc:#008B74 \
    -font Arial -pointsize $((size/4)) -fill white -gravity center \
    -annotate +0+0 "FFP3" icon-${size}.png
done
```

**Option recommandée** (outil en ligne) :
1. Aller sur https://realfavicongenerator.net/
2. Upload un logo carré (minimum 512x512px)
3. Télécharger le package généré
4. Extraire les fichiers `icon-*.png` dans `public/assets/icons/`

### 3. Tester l'application

```bash
# Démarrer serveur local
php -S localhost:8080 -t public
```

Ouvrir dans le navigateur : http://localhost:8080

**Vérifications** :
- [ ] Badge "LIVE" s'affiche en haut à droite
- [ ] Après 15s, badge passe à "LIVE" (vert)
- [ ] Section "État du système" affiche les métriques
- [ ] Console DevTools montre logs `[RealtimeUpdater]`
- [ ] Aucune erreur JavaScript

---

## 📱 Test sur mobile

### Android (Chrome)

1. Accéder à l'URL depuis Chrome mobile
2. Menu (⋮) → "Installer l'application"
3. Vérifier que l'icône apparaît sur l'écran d'accueil
4. Lancer l'app → doit s'ouvrir en mode standalone
5. Tester gestures :
   - Swipe left → Page suivante
   - Swipe right → Page précédente
   - Pull down → Actualisation

### iOS (Safari)

1. Accéder à l'URL depuis Safari
2. Bouton Partager → "Sur l'écran d'accueil"
3. L'app apparaît comme icône
4. Tester navigation bottom bar

---

## 🔍 Diagnostic rapide

### Problème : Badge reste "INITIALISATION..."

**Solution** :
1. F12 → Console
2. Chercher erreurs en rouge
3. Vérifier que `/api/realtime/sensors/latest` répond :
   ```
   http://localhost:8080/api/realtime/sensors/latest
   ```
4. Si erreur 404 → Vérifier routes dans `public/index.php`

### Problème : Toast notifications ne s'affichent pas

**Solution** :
1. Console → `typeof toastManager`
2. Si `undefined` → vérifier chargement de `toast-notifications.js`
3. Tester manuellement :
   ```javascript
   toastManager.showInfo('Test');
   ```

### Problème : Service Worker non enregistré

**Solution** :
1. DevTools → Application → Service Workers
2. Si erreur → vérifier chemin `service-worker.js` accessible
3. En prod : HTTPS requis (localhost OK en dev)
4. Forcer réenregistrement :
   ```javascript
   navigator.serviceWorker.getRegistrations()
     .then(r => r.forEach(reg => reg.unregister()));
   ```

---

## 🎨 Personnalisation rapide

### Changer l'intervalle de polling

**Fichier** : `.env`
```env
REALTIME_POLLING_INTERVAL=20  # 20 secondes au lieu de 15
```

**Ou directement dans le template** :
```javascript
initRealtimeUpdater({
    pollInterval: 20000  // 20 secondes
});
```

### Changer les couleurs PWA

**Fichier** : `public/manifest.json`
```json
{
  "theme_color": "#FF5733",      // Votre couleur
  "background_color": "#FF5733"
}
```

**Fichier** : `public/assets/css/mobile-optimized.css`
```css
:root {
    --primary-color: #FF5733;  /* Votre couleur */
}
```

### Désactiver le polling

**Dans le template** :
```javascript
initRealtimeUpdater({
    enabled: false  // Désactive complètement
});
```

---

## 📊 Données de test

### Simuler nouvelles données

Si vous voulez tester sans attendre l'ESP32 :

```bash
# Insérer une donnée de test
mysql -u oliviera_iot -p oliviera_iot

INSERT INTO ffp3Data (
  TempAir, Humidite, TempEau, EauPotager, EauAquarium, EauReserve,
  Luminosite, reading_time
) VALUES (
  22.5, 65.0, 24.0, 45.0, 32.0, 78.0, 850, NOW()
);
```

Le polling détectera la nouvelle donnée dans les 15 prochaines secondes.

---

## 🚀 Déploiement en production

### Checklist avant mise en production

- [ ] `composer install --no-dev` (pas de PHPUnit)
- [ ] Icônes PWA générées (8 fichiers)
- [ ] `.env` configuré (variables PWA optionnelles)
- [ ] Service worker accessible en HTTPS
- [ ] Tester sur Chrome, Firefox, Safari
- [ ] Tester mode offline
- [ ] Vérifier performances (DevTools → Lighthouse)

### Commandes de déploiement

```bash
# 1. Pull dernières modifications
git pull origin main

# 2. Installer dépendances production
composer install --no-dev --optimize-autoloader

# 3. Vider cache Twig si activé
rm -rf var/cache/twig/*

# 4. Vérifier permissions
chmod -R 755 public/assets
chmod 644 public/manifest.json
chmod 644 public/service-worker.js

# 5. Tester
curl https://votre-domaine.com/ffp3/ffp3datas/api/realtime/system/health
```

---

## 📚 Documentation complète

- **Implémentation détaillée** : `IMPLEMENTATION_REALTIME_PWA.md`
- **Changelog** : `CHANGELOG.md` (version 4.0.0)
- **Icônes PWA** : `public/assets/icons/README.md`
- **Architecture générale** : `README.md`
- **Règles du projet** : Voir `.cursorrules`

---

## 🎯 Prochaines étapes (optionnel)

### Fonctionnalités à implémenter

1. **Notifications Push** (2-3h)
   - Créer table `push_subscriptions`
   - Implémenter `PushNotificationService`
   - Générer clés VAPID
   - Tester sur mobile

2. **QR Codes** (1-2h)
   - Implémenter `QrCodeService`
   - Créer routes `/qr/*`
   - Template de gestion

3. **Synchronisation GPIO temps réel** (1h)
   - Créer `control-sync.js`
   - Polling outputs toutes les 10s
   - Update switches automatique

4. **Tests unitaires** (3-4h)
   - `RealtimeDataServiceTest`
   - `RealtimeApiControllerTest`
   - Tests JavaScript avec Jest

---

## ✨ Profitez de votre nouvelle app !

Vous avez maintenant :
- ✅ Mise à jour automatique toutes les 15s
- ✅ Badge LIVE avec statut connexion
- ✅ Dashboard système temps réel
- ✅ Toast notifications élégantes
- ✅ PWA installable sur mobile
- ✅ Swipe gestures pour navigation
- ✅ Mode offline basique
- ✅ Interface mobile-first

**Version** : 4.0.0  
**Date** : 2025-10-11  
**Status** : ✅ Production Ready

