# 🔧 Instructions pour résoudre le problème Composer sur le serveur

## 📋 Problème identifié
Le fichier `composer.lock` sur le serveur n'est pas à jour avec les nouvelles dépendances dans `composer.json`. Les packages suivants manquent :
- `php-di/php-di`
- `minishlink/web-push` 
- `bacon/bacon-qr-code`

## 🚀 Solution rapide

### Option 1: Script automatisé (recommandé)
```bash
# Sur le serveur
bash fix-composer-server.sh
```

### Option 2: Commandes manuelles
```bash
# Se connecter au serveur
ssh oliviera@iot.olution.info

# Aller dans le répertoire
cd /home4/oliviera/iot.olution.info/ffp3/

# Sauvegarder l'ancien lock
cp composer.lock composer.lock.backup

# Supprimer l'ancien lock et vendor
rm composer.lock
rm -rf vendor/

# Réinstaller avec mise à jour
composer update --no-dev --optimize-autoloader
```

### Option 3: Force update du lock existant
```bash
# Sur le serveur
composer update --lock --no-dev --optimize-autoloader
```

## 🧪 Vérification
Après installation, tester :
```bash
php -r "require_once 'vendor/autoload.php'; echo class_exists('DI\ContainerBuilder') ? 'OK' : 'ERREUR';"
```

## 🎯 Résultat attendu
- ✅ Dossier `vendor/php-di/` présent
- ✅ Fichier `vendor/autoload.php` généré
- ✅ Classe `DI\ContainerBuilder` disponible
- ✅ Site accessible sur https://iot.olution.info/ffp3/

## ⚠️ Note importante
Le `composer update` va mettre à jour toutes les dépendances à leurs dernières versions compatibles. Si vous préférez garder les versions exactes, utilisez `composer install` après avoir mis à jour le `composer.lock` localement.
