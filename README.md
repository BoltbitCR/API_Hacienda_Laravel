![https://crlibre.org](https://crlibre.org/wp-content/uploads/2018/03/cropped-CRLibre-Logo_15-1.png)

# API Hacienda — Facturación Electrónica de Costa Rica 

![Instalaciones activas](https://img.shields.io/endpoint?url=https%3A%2F%2Ftelemetria-api-hacienda.boltbitcr.com%2Fbadge.json)
[![GitHub](https://img.shields.io/github/license/BoltbitCR/API_Hacienda_Laravel.svg)](https://github.com/BoltbitCR/API_Hacienda_Laravel/blob/v4.4/LICENSE)
[![GitHub commit activity the past week, 4 weeks, year](https://img.shields.io/github/commit-activity/y/BoltbitCR/API_Hacienda_Laravel/v4.4.svg?logo=github)](https://github.com/BoltbitCR/API_Hacienda_Laravel/commits/v4.4)
[![GitHub issues](https://img.shields.io/github/issues-raw/BoltbitCR/API_Hacienda_Laravel.svg)](https://github.com/BoltbitCR/API_Hacienda_Laravel/issues)
[![GitHub pulls](https://img.shields.io/github/issues-pr/BoltbitCR/API_Hacienda_Laravel.svg)](https://github.com/BoltbitCR/API_Hacienda_Laravel/pulls)
[![Tests](https://github.com/BoltbitCR/API_Hacienda_Laravel/actions/workflows/tests.yml/badge.svg?branch=v4.4)](https://github.com/BoltbitCR/API_Hacienda_Laravel/actions/workflows/tests.yml)


Migración y modernización del proyecto de [API_Hacienda de CRLibre](https://github.com/CRLibre/API_Hacienda) en Laravel 12.

Soporte actual para V4.4


## Documentación

Toda la documentación está en la **[wiki](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki)**:

- [Instalación](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Instalación) y [configuración](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Configuración)
- [Autenticación](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Autenticación) y [emisión de comprobantes](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Emisión-de-comprobantes)
- [Referencia de parámetros](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Referencia-de-parámetros) e [impuestos y tarifas](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Impuestos-y-tarifas)
- [API legacy (`/api.php`)](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/API-legacy) para clientes del API original
- [Solución de problemas](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Solución-de-problemas) y [limitaciones conocidas](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Limitaciones-conocidas)
- [Arquitectura](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Arquitectura), [pruebas](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Pruebas) y [cómo contribuir](https://github.com/BoltbitCR/API_Hacienda_Laravel/wiki/Contribuir)

Cada instalación sirve además su especificación OpenAPI en `/docs/api`.

## Telemetría

- `php artisan telemetry:status` muestra exactamente lo que se envía.
- Para apagarla: `TELEMETRY_ENABLED=false` en el `.env`.


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

