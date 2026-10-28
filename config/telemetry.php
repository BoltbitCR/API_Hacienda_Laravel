<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telemetría anónima de instalaciones
    |--------------------------------------------------------------------------
    | Una vez cada 24 h la instalación envía un identificador aleatorio, la
    | versión del API y la de PHP, para contar cuántas instalaciones están
    | activas. No viaja ningún dato de emisores, comprobantes ni credenciales.
    | `php artisan telemetry:status` muestra exactamente lo que se envía.
    | TELEMETRY_ENABLED=false la apaga.
    */

    'enabled' => (bool) env('TELEMETRY_ENABLED', true),

    'url' => env('TELEMETRY_URL', 'https://telemetria-api-hacienda.boltbitcr.com/ping'),

    'version' => env('API_VERSION', '0.0.1'),

    // Segundos entre envíos.
    'interval' => 86400,

    'timeout' => 3,

];
