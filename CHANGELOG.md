# Cambios

Formato: [Keep a Changelog](https://keepachangelog.com/es/1.1.0/). Los cambios de
contrato de la API REST v1 se marcan con **[contrato]**: son aditivos salvo que se
indique lo contrario.

## Sin publicar — rama `audit/v4.4-2026-11`

### Corregido
- **[contrato]** `POST /api/v1/documents` ya no deja todo en `enviado`: el estado
  refleja la recepción de Hacienda (`202` → `enviado`, `400` → `rechazado` con la causa
  del header `X-Error-Cause`, `0`/`5xx` → `error`). La respuesta sigue siendo `201` con
  los mismos campos y agrega `estado` y `recepcion {status, causa}`; `documents` guarda
  `recepcion_status` y `mensaje_error` (#25).
- `GET /documents/{id}/status` guarda el `MensajeHacienda` (`respuesta-xml`) en
  `documents.respuesta_mh_base64`; `GET /documents/{id}` y `/status` devuelven además
  `xml_enviado_base64` y `respuesta_mh_base64` (respaldo fiscal descargable, Res. 0027 art. 7) (#25).
- `tipo: MR` por REST funciona: la clave es la del comprobante recibido, el consecutivo
  es el de la serie propia 05/06/07 según `mensaje`, y la recepción recibe `receptor` y
  `consecutivoReceptor` como `sendMensaje`. Campos nuevos del cuerpo: `clave`, `mensaje`,
  `detalle_mensaje`, `tipo_identificacion_emisor`, `numero_cedula_emisor`,
  `total_factura`, `monto_total_impuesto`, `codigo_actividad`, `condicion_impuesto`,
  `monto_total_impuesto_acreditar`, `monto_total_gasto_aplicable` (#24).
- El teléfono del emisor se omitía siempre (el generador comparaba el número contra
  8–20 en vez de su longitud; CRLibre #215, corregido upstream en #225). Mismo arreglo en
  `legacy/api/contrib/genXML/genXML.php`; se recapturaron las 7 golden afectadas, cuya
  única diferencia es el nodo `Telefono` del emisor.
- La fecha de la clave (posiciones 4–9) sale de `fecha_emision`, no del reloj del
  servidor; sin `fecha_emision`, el XML lleva la hora actual de Costa Rica.
- Dependencias con CVE: `laravel/framework` 12.70.2 (CVE-2026-114509 crítica,
  CVE-2026-114508, CVE-2026-102279), `guzzlehttp/guzzle` 7.16.1, `symfony/http-foundation`
  7.4.22 (ReDoS CVE-2026-108082), `symfony/yaml` 8.1.10, `symfony/mime` 7.4.22,
  `league/commonmark` 2.10.6, `league/flysystem` 3.36.0. `composer audit`: 0 avisos.

### Agregado
- **[contrato]** Header opcional `Idempotency-Key` (≤ 100 caracteres) en
  `POST /documents`: repetirlo devuelve `200` con el comprobante ya emitido
  (`idempotent_replay: true`) en vez de emitir otro. Un consecutivo que ya llegó a
  Hacienda responde `409` con `document_id`; uno que nunca llegó (error de firma, red,
  `400`) se puede reintentar.
- **[contrato]** `POST /api/v1/documents/validate`: mismo cuerpo que la emisión; genera,
  firma y valida contra el XSD oficial v4.4 sin consumir consecutivo ni enviar. Responde
  `{valid, errors, xml}`.
- `HACIENDA_XSD_BEFORE_SEND=true` valida el XML firmado antes de enviarlo (422 si no
  cumple). Apagado por defecto.
- Parámetros opcionales de la emisión: `situacion` (`normal`/`contingencia`/`sininternet`,
  por comprobante), `callback_url` (viaja como `callbackUrl` a Hacienda).
- `companies.proveedor_sistemas` (`PUT /company`): cédula del proveedor del sistema para
  `ProveedorSistemas`; vacío = la del emisor.
- Token del IdP de Hacienda en caché (TTL `expires_in − 30 s`, tope 270 s): una sola
  autenticación por ventana en vez de una por operación.
- Certificados `.p12` subidos cifrados en reposo con la `APP_KEY`
  (`stored_files.encrypted`); los existentes en claro siguen funcionando. El
  `download_code` pasa a ser aleatorio.
- `SANCTUM_EXPIRATION` (minutos de vida de los tokens; vacío = no expiran) y
  `AUTH_REGISTRATION_ENABLED` (apaga `POST /auth/register`).
- Imagen de producción publicada en GHCR por `.github/workflows/docker-publish.yml`;
  `docker-compose.prod.yml` acepta `API_IMAGE`.
- `openapi.json` exportado con `php artisan scramble:export`.
- Catálogo `medio_pago`: códigos `06` SINPE MOVIL y `07` Plataforma Digital (nota 6).

### Cambiado
- Orden de la emisión: credenciales, certificado y token se resuelven **antes** de
  consumir el consecutivo; cada intento queda registrado en `documents` desde que se
  reserva (estado `generando` → `enviado`/`rechazado`/`error`). Un fallo de firma o
  de XSD deja el intento en `error` con `mensaje_error` (antes no quedaba rastro).
- El código de seguridad de la clave es aleatorio cuando el cliente no lo manda
  (antes `00000000`).

### Migraciones
`2026_10_30_000001` … `000004`: columnas nuevas, todas nullable o con default; no tocan
datos existentes.
