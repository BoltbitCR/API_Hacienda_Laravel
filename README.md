![https://crlibre.org](https://crlibre.org/wp-content/uploads/2018/03/cropped-CRLibre-Logo_15-1.png)

# API Hacienda — Facturación Electrónica de Costa Rica 

Migración y modernización del proyecto de [API_Hacienda de CRLibre](https://github.com/CRLibre/API_Hacienda) en Laravel, manteniendo **compatibilidad total** con los clientes del API original.

Soporte actual para V4.4


> El código original en `/legacy` se mantiene **de forma permanente** como
> referencia histórica y golden master.

## Documentación

Toda la documentación está en la **[wiki](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki)**:

- [Instalación](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Instalación) y [configuración](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Configuración)
- [Autenticación](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Autenticación) y [emisión de comprobantes](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Emisión-de-comprobantes)
- [Referencia de parámetros](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Referencia-de-parámetros) e [impuestos y tarifas](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Impuestos-y-tarifas)
- [API legacy (`/api.php`)](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/API-legacy) para clientes del API original
- [Solución de problemas](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Solución-de-problemas) y [limitaciones conocidas](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Limitaciones-conocidas)
- [Arquitectura](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Arquitectura), [pruebas](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Pruebas) y [cómo contribuir](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Contribuir)

Cada instalación sirve además su especificación OpenAPI en `/docs/api`.

## Inicio rápido

```bash
cp .env.example .env
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Antes de levantarlo, lea [Instalación](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Instalación): con Docker la conexión a la base de datos va en el `.env`.

## Producción

El `docker-compose.yml` es solo para desarrollo. En producción se usa `docker-compose.prod.yml`:

- **app**: imagen propia (`Dockerfile`, FrankenPHP + PHP 8.4.27). Corre como `www-data` y no monta el código.
- **mysql**: MariaDB 11.8 sin puerto publicado.
- Los datos viven en dos volúmenes persistentes: `mysql-data` (la base) y `app-storage` (certificados `.p12`, XML subidos y logs).

La app solo escucha en `127.0.0.1:8888`; para exponerla, ponga delante un proxy inverso con TLS. Otro stack del mismo host puede consumirla en `http://api_hacienda-app-1:8000` uniéndose a la red `api_hacienda_default`.

### Instalación nueva

```bash
cp .env.production.example .env
# Completar DB_PASSWORD y DB_ROOT_PASSWORD (openssl rand -hex 24), MAIL_* y CORS_ALLOWED_ORIGINS.
docker compose build
docker compose run --rm --no-deps app php artisan key:generate --show   # → APP_KEY en .env
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --class=CatalogSeeder --force
curl -s http://127.0.0.1:8888/api/v1/geo/provinces   # debe listar las 7 provincias
```

El `.env` fija `COMPOSE_FILE=docker-compose.prod.yml`, así que `docker compose` a secas usa el compose de producción. Puntos clave:

- `APP_DEBUG=false` y `SCRAMBLE_ENABLED=false`: sin errores detallados y sin `/docs/api`.
- La `APP_KEY` se genera **una sola vez** y se respalda fuera del servidor. Cifra las credenciales ATV guardadas.
- Ver [`.env.production.example`](.env.production.example) para el detalle de cada variable.

### Actualizar

```bash
git pull
docker compose build --pull     # --pull: trae la base con el último parche de PHP
docker compose up -d
docker compose exec app php artisan migrate --force
```

Un `docker compose pull` no basta: la imagen de la app se construye localmente.

### Si ya corría con el compose de desarrollo

La base se conserva: el volumen `mysql-data` es el mismo. Mantenga la `APP_KEY` y la contraseña de la base que ya usaba, levante con `docker compose up -d --build --remove-orphans` y copie los archivos subidos al volumen:

```bash
docker compose cp ./storage/app/legacy-files app:/app/storage/app/
docker compose exec -u root app chown -R www-data:www-data storage/app/legacy-files
```

Más detalle en el wiki: [Despliegue en producción](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Despliegue-en-producción).

## CRLibre y Factura Electrónica en Costa Rica

- [Comunidad CRLibre](https://crlibre.org) · [Telegram @CRLibreFE](https://crlibre.org/chats/)

## Licencia

Este proyecto está licenciado bajo la **Licencia GNU Affero General Public
License v3 (AGPL v3)**.  
**Todos los usuarios y desarrolladores que utilicen, modifiquen o distribuyan
este módulo están obligados a colaborar en su mantenimiento y mejora, conforme a
los términos de la licencia.**

## 🔹 Condiciones principales

- Cualquier modificación o mejora debe ser publicada y compartida con la
  comunidad bajo la misma licencia AGPL v3.
- Si el módulo se utiliza en entornos privados o en servicios web, el código
  fuente debe estar disponible para todos los usuarios que interactúen con él.
- Se espera que todos los beneficiarios del módulo contribuyan con *
  *correcciones, mejoras o documentación** para asegurar su evolución y
  mantenimiento.

💡 _El incumplimiento de estas condiciones podría considerarse una violación de
los términos de la licencia AGPL v3._

