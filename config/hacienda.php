<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ambiente por defecto
    |--------------------------------------------------------------------------
    | "stag" (sandbox) o "prod". En el API legacy el ambiente viaja por
    | request vía client_id (api-stag | api-prod); esto es solo el default.
    */

    'environment' => env('HACIENDA_ENV', 'stag'),

    /*
    |--------------------------------------------------------------------------
    | Endpoints del Ministerio de Hacienda
    |--------------------------------------------------------------------------
    | Antes hardcodeados en legacy/api/contrib/send/send.php y token/mhToken.php.
    */

    'endpoints' => [
        'stag' => [
            'reception' => env('HACIENDA_STAG_RECEPTION', 'https://api-sandbox.comprobanteselectronicos.go.cr/recepcion/v1/recepcion/'),
            'idp' => env('HACIENDA_STAG_IDP', 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/protocol/openid-connect/token'),
            'client_id' => 'api-stag',
        ],
        'prod' => [
            'reception' => env('HACIENDA_PROD_RECEPTION', 'https://api.comprobanteselectronicos.go.cr/recepcion/v1/recepcion/'),
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
    | Política de firma XAdES-EPES (formato v4.4)
    |--------------------------------------------------------------------------
    | Antes hardcodeada en el fork de xmlseclibs
    | (legacy/api/contrib/firmarXML/xmlseclibs/src/XMLSecurityDSig.php).
    */

    'sign_policy' => [
        'version' => 'v4.4',
        'url' => env('HACIENDA_POLICY_URL', 'https://www.hacienda.go.cr/ATV/ComprobanteElectronico/docs/esquemas/2016/v4.4/Resolucion_Comprobantes_Electronicos_DGT-R-033-2019_4.4.pdf'),
        'digest' => env('HACIENDA_POLICY_DIGEST', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compatibilidad legacy
    |--------------------------------------------------------------------------
    | enabled: el endpoint /api.php solo responde si se activa de forma
    | explícita; apagado (default) devuelve 404.
    | open_endpoints: si true (default, comportamiento histórico) los
    | endpoints de facturación (genXML, firmar, send, token…) no requieren
    | autenticación propia del API.
    */

    'legacy' => [
        'enabled' => (bool) env('LEGACY_API_ENABLED', false),
        'open_endpoints' => env('LEGACY_OPEN_ENDPOINTS', true),
        'crypto_key' => env('LEGACY_CRYPTO_KEY', ''),
        // Vida de sesión legacy en segundos (variable sessionLifetime del
        // .env viejo). -1 replica el bug histórico: nunca valida.
        'session_lifetime' => env('LEGACY_SESSION_LIFETIME', 3600),
        'version_file' => env('LEGACY_VERSION_FILE', base_path('VERSION')),
    ],

];
