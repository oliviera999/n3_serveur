#!/bin/bash

# Script de déploiement pour le serveur de production
# À exécuter sur le serveur iot.olution.info

echo "🚀 Déploiement du projet FFP3 sur le serveur de production..."

# Vérifier si Composer est installé
if ! command -v composer &> /dev/null; then
    echo "❌ Composer n'est pas installé. Installation..."
    # Installer Composer
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
    echo "✅ Composer installé"
fi

# Aller dans le répertoire du projet
cd /home4/oliviera/iot.olution.info/ffp3/

# Mettre à jour le code depuis GitHub
echo "📥 Récupération du code depuis GitHub..."
git fetch --all
git reset --hard origin/main

# Installer les dépendances
echo "📦 Installation des dépendances Composer..."
composer install --no-dev --optimize-autoloader

# Vérifier les permissions
echo "🔐 Vérification des permissions..."
chmod -R 755 public/
chmod -R 644 config/
chmod -R 644 src/

# Nettoyer le cache
echo "🧹 Nettoyage du cache..."
rm -rf var/cache/*

echo "✅ Déploiement terminé avec succès !"
echo "🌐 Votre site est maintenant accessible sur https://iot.olution.info/ffp3/"