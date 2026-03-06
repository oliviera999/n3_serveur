# TODO : Améliorations Interface de Contrôle

**Date de création** : 08/10/2025  
**Statut** : Interface fonctionnelle - Améliorations esthétiques et fonctionnelles à prévoir

---

## 🎨 Interface Visuelle

### Priorité Haute
- [ ] **Comparer pixel par pixel** avec l'original (`ffp3control/securecontrol/ffp3-outputs.php`)
  - [ ] Vérifier taille des switches (actuellement 120x68px dans `ffp3-style.css`)
  - [ ] Vérifier espacement entre les éléments
  - [ ] Vérifier police de caractères et tailles
- [ ] **Encodage caractères accentués**
  - [ ] "Radiateurs (stoppés si relais activé)" → vérifier affichage
  - [ ] "Lumière" → vérifier affichage
  - [ ] "Pompe réserve" → vérifier affichage
- [ ] **Disposition formulaire**
  - [ ] Vérifier largeur des champs input
  - [ ] Vérifier padding et margin des labels
  - [ ] Vérifier couleur du bouton submit (#008B74)

### Priorité Moyenne
- [ ] Ajouter favicon olution.info
- [ ] Vérifier le responsive mobile
- [ ] Améliorer feedback visuel lors du toggle (animation)
- [ ] Ajouter loader pendant les requêtes AJAX

---

## ⚙️ Fonctionnalités

### Priorité Haute
- [ ] **Tester tous les GPIO physiques un par un**
  - [ ] GPIO 2 - Radiateurs
  - [ ] GPIO 15 - Lumière
  - [ ] GPIO 16 - Pompe aquarium
  - [ ] GPIO 18 - Pompe réserve
  - [ ] GPIO ? - Chauffage (problème matériel ?)
- [ ] **Valider mise à jour paramètres système**
  - [ ] Test complet du formulaire
  - [ ] Vérifier que tous les GPIO 100+ sont bien mis à jour
  - [ ] Ajouter confirmation visuelle après soumission

### Priorité Moyenne
- [ ] **Gestion d'erreurs améliorée**
  - [ ] Afficher message si GPIO inexistant
  - [ ] Gérer timeout des requêtes AJAX
  - [ ] Logger les erreurs côté serveur
- [ ] **Historique des actions**
  - [ ] Créer table `control_history` (user, gpio, action, timestamp)
  - [ ] Afficher dernières actions dans l'interface
- [ ] **Permissions et sécurité**
  - [ ] Ajouter `.htaccess` avec authentification HTTP Basic sur `/control`
  - [ ] Copier la config de `ffp3control/securecontrol/.htaccess`

---

## 🤖 ESP32 / API

### Priorité Haute
- [ ] **Documentation API pour ESP32**
  - [ ] Format de la requête GET `/api/outputs/state`
  - [ ] Format de la réponse JSON
  - [ ] Exemples de code Arduino/ESP32
- [ ] **Test complet ESP32**
  - [ ] ESP32 peut lire l'état des outputs
  - [ ] ESP32 peut exécuter les commandes (via GPIO)
  - [ ] Vérifier latence réseau

### Priorité Basse
- [ ] Ajouter endpoint `/api/outputs/history` pour historique
- [ ] Créer endpoint `/api/outputs/batch` pour actions multiples
- [ ] Webhooks pour notifications externes

---

## 📚 Documentation

### Priorité Haute
- [ ] **Guide utilisateur interface de contrôle**
  - [ ] Comment accéder à l'interface
  - [ ] Explication de chaque GPIO
  - [ ] Explication des paramètres système
  - [ ] Différence PROD vs TEST
- [ ] **Guide développeur API**
  - [ ] Documentation OpenAPI/Swagger
  - [ ] Exemples curl pour chaque endpoint
  - [ ] Code d'erreur et gestion

### Priorité Moyenne
- [ ] Vidéo tutoriel de l'interface
- [ ] FAQ avec cas d'usage courants
- [ ] Troubleshooting (que faire si GPIO ne répond pas)

---

## 🔒 Sécurité

### Priorité Haute
- [ ] **Authentification**
  - [ ] Copier `.htaccess` de `ffp3control/securecontrol/` vers route `/control`
  - [ ] Tester que l'authentification fonctionne
  - [ ] Documenter les credentials
- [ ] **Logging des actions**
  - [ ] Utiliser `LogService` pour enregistrer chaque toggle
  - [ ] Format : `[timestamp] User: X - GPIO: Y - Action: Z`

### Priorité Moyenne
- [ ] Rate limiting sur API (éviter spam)
- [ ] CSRF protection sur formulaire
- [ ] Validation stricte des paramètres (GPIO valides uniquement)

---

## 🧪 Tests

### Priorité Haute
- [ ] **Tests manuels complets**
  - [ ] Checklist de tous les GPIO
  - [ ] Checklist de tous les paramètres
  - [ ] Test PROD vs TEST (vérifier séparation)
- [ ] **Tests automatisés**
  - [ ] PHPUnit pour `OutputService`
  - [ ] PHPUnit pour `OutputRepository`
  - [ ] Tests API avec curl/PHPUnit

### Priorité Basse
- [ ] Tests d'intégration end-to-end
- [ ] Tests de charge (combien de requêtes simultanées ?)
- [ ] Tests de sécurité (injection, XSS, etc.)

---

## 🗄️ Base de Données

### Priorité Moyenne
- [ ] **Nettoyage table ffp3Outputs**
  - [ ] Supprimer les 300+ entrées "GPIO 16 -" vides
  - [ ] Script SQL de nettoyage
  - [ ] Documenter structure de la table
- [ ] **Optimisation**
  - [ ] Index sur colonne `gpio` si pas déjà fait
  - [ ] Index sur colonne `board` si pas déjà fait

---

## 📱 Legacy / Compatibilité

### Priorité Basse
- [ ] **Redirection anciens endpoints**
  - [ ] `ffp3control/securecontrol/ffp3-outputs.php` → `/control`
  - [ ] `ffp3control/securecontrol/ffp3-outputs2.php` → `/control-test`
  - [ ] `ffp3control/ffp3-outputs-action.php` → API moderne
- [ ] **Dépréciation progressive**
  - [ ] Ajouter warning dans anciens fichiers
  - [ ] Documentation de migration
  - [ ] Date de fin de support des anciens endpoints

---

## 🎯 Objectifs Long Terme

- [ ] Interface mobile native (PWA ?)
- [ ] Notifications push quand GPIO change
- [ ] Graphiques historiques des états GPIO
- [ ] Intégration Home Assistant / domotique
- [ ] API publique avec authentification OAuth

---

## 📝 Notes

- **Chauffage** : Problème matériel possible (faux contact). À tester en physique.
- **Encodage** : Utiliser `|raw` dans Twig pour afficher caractères accentués.
- **CSS** : Le fichier `ffp3-style.css` définit les styles des switches (à ne pas modifier).

---

*Document créé pour suivre les améliorations progressives - À mettre à jour régulièrement*

