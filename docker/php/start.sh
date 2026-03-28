#!/usr/bin/env sh
set -eu

cd /var/www/html

if [ ! -f .env ] && [ -f .env.docker.example ]; then
  cp .env.docker.example .env
  echo "[local] .env created from .env.docker.example"
fi

# Evite les erreurs de chemins absolus entre host Windows et conteneur Linux.
mkdir -p var/cache/di
rm -rf var/cache/di/*

if [ ! -f vendor/autoload.php ]; then
  echo "[local] Installing Composer dependencies..."
  composer install
fi

exec php -S 0.0.0.0:80 -t public public/index.php
