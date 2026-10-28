<?php

namespace App\Services\Telemetry;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ping anónimo de instalación activa (ver config/telemetry.php). El
 * identificador es un UUID aleatorio guardado en storage: no se deriva de
 * nada del servidor ni de sus datos.
 */
class TelemetryPinger
{
    private const CACHE_KEY = 'telemetry:last-ping';

    public function enabled(): bool
    {
        return (bool) config('telemetry.enabled') && filled(config('telemetry.url'));
    }

    public function instanceId(): string
    {
        $path = storage_path('app/telemetry/instance-id');

        if (File::exists($path)) {
            $id = trim(File::get($path));
            if (Str::isUuid($id)) {
                return $id;
            }
        }

        $id = (string) Str::uuid();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $id);

        return $id;
    }

    /**
     * @return array{id: string, version: string, php: string}
     */
    public function payload(): array
    {
        return [
            'id' => $this->instanceId(),
            'version' => (string) config('telemetry.version'),
            'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
        ];
    }

    /**
     * Envía el ping si pasó el intervalo desde el último. Nunca lanza: un
     * fallo de red o de storage no debe afectar la petición que lo disparó.
     */
    public function maybePing(): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }
            if (! Cache::add(self::CACHE_KEY, time(), (int) config('telemetry.interval'))) {
                return;
            }
            $this->send();
        } catch (Throwable) {
            // Silencioso a propósito.
        }
    }

    /**
     * Envío inmediato, sin mirar el intervalo. Devuelve si el colector
     * respondió 2xx.
     */
    public function send(): bool
    {
        return Http::timeout((int) config('telemetry.timeout'))
            ->acceptJson()
            ->post((string) config('telemetry.url'), $this->payload())
            ->successful();
    }
}
