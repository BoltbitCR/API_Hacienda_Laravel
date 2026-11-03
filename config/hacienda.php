<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Endpoints del Ministerio de Hacienda
    |--------------------------------------------------------------------------
    | Antes hardcodeados en legacy/api/contrib/send/send.php y token/mhToken.php.
    */

    'endpoints' => [
        'stag' => [
            'reception' => env('HACIENDA_STAG_RECEPTION', 'https://api-sandbox.comprobanteselectronicos.go.cr/recepcion/v1/recepcion/'),
            'comprobantes' => env('HACIENDA_STAG_COMPROBANTES', 'https://api-sandbox.comprobanteselectronicos.go.cr/recepcion/v1/comprobantes'),
            'idp' => env('HACIENDA_STAG_IDP', 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/protocol/openid-connect/token'),
            'client_id' => 'api-stag',
        ],
        'prod' => [
            'reception' => env('HACIENDA_PROD_RECEPTION', 'https://api.comprobanteselectronicos.go.cr/recepcion/v1/recepcion/'),
            'comprobantes' => env('HACIENDA_PROD_COMPROBANTES', 'https://api.comprobanteselectronicos.go.cr/recepcion/v1/comprobantes'),
            'idp' => env('HACIENDA_PROD_IDP', 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut/protocol/openid-connect/token'),
            'client_id' => 'api-prod',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Verificación SSL hacia Hacienda
    |--------------------------------------------------------------------------
    | El legacy usaba CURLOPT_SSL_VERIFYPEER=false. El default nuevo es true;
    | HACIENDA_VERIFY_SSL=false existe solo como escape temporal.
    */

    'verify_ssl' => env('HACIENDA_VERIFY_SSL', true),

    'timeout' => env('HACIENDA_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Validar contra el XSD antes de enviar
    |--------------------------------------------------------------------------
    | Con true, el XML firmado se valida contra el XSD oficial v4.4 y, si no
    | cumple, la emisión responde 422 sin enviarlo a Hacienda (queda
    | registrada en `documents` con estado error). Apagado por defecto para
    | no cambiar el comportamiento de las instalaciones existentes;
    | POST /api/v1/documents/validate está siempre disponible.
    */

    'xsd_before_send' => (bool) env('HACIENDA_XSD_BEFORE_SEND', false),

    /*
    |--------------------------------------------------------------------------
    | Política de firma XAdES-EPES (formato v4.4)
    |--------------------------------------------------------------------------
    | Antes hardcodeada en el fork de xmlseclibs
    | (legacy/api/contrib/firmarXML/xmlseclibs/src/XMLSecurityDSig.php).
    | Vacías = la política oficial que trae crlibre/xades-xmlseclibs. Solo
    | cambiarlas si Hacienda publica otra resolución: url y digest (SHA-256
    | del PDF en base64) van juntos, o la recepción rechaza la firma.
    */

    'sign_policy' => [
        'url' => env('HACIENDA_POLICY_URL'),
        'digest' => env('HACIENDA_POLICY_DIGEST'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compatibilidad legacy
    |--------------------------------------------------------------------------
    | enabled: el endpoint /api.php solo responde si se activa de forma
    | explícita; apagado (default) devuelve 404.
    */

    'legacy' => [
        'enabled' => (bool) env('LEGACY_API_ENABLED', false),
        'crypto_key' => env('LEGACY_CRYPTO_KEY', ''),
        // Vida de sesión legacy en segundos (variable sessionLifetime del
        // .env viejo). -1 replica el bug histórico: nunca valida.
        'session_lifetime' => env('LEGACY_SESSION_LIFETIME', 3600),
        'version_file' => env('LEGACY_VERSION_FILE', base_path('VERSION')),
    ],

];
