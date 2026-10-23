#!/bin/sh
set -e

# Caches de config/rutas/eventos a partir del entorno del contenedor
# (env_file: .env en docker-compose.prod.yml). No se hornean en la imagen.
# Las migraciones NO corren aquí: se lanzan a mano en cada deploy (README).
php artisan optimize

exec "$@"
