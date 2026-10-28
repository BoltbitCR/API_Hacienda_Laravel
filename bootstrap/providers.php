<?php

use App\Providers\AppServiceProvider;
use App\Providers\LegacyServiceProvider;
use App\Providers\TelemetryServiceProvider;

return [
    AppServiceProvider::class,
    LegacyServiceProvider::class,
    TelemetryServiceProvider::class,
];
