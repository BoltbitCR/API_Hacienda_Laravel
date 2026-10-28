<?php

namespace App\Providers;

use App\Services\Telemetry\TelemetryPinger;
use Illuminate\Support\ServiceProvider;

class TelemetryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Solo peticiones HTTP y después de enviar la respuesta: no suma
        // latencia al cliente. Sin scheduler, el ping lo dispara el tráfico.
        if ($this->app->runningInConsole()) {
            return;
        }

        $this->app->terminating(function (): void {
            $this->app->make(TelemetryPinger::class)->maybePing();
        });
    }
}
