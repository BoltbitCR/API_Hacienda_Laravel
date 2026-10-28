<?php

namespace App\Console\Commands;

use App\Services\Telemetry\TelemetryPinger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Muestra el estado de la telemetría y el JSON exacto que se envía.
 */
class TelemetryStatus extends Command
{
    protected $signature = 'telemetry:status
        {--send : Envía el ping ahora, sin esperar el intervalo}';

    protected $description = 'Muestra qué envía la telemetría anónima y si está activa';

    public function handle(TelemetryPinger $pinger): int
    {
        $enabled = $pinger->enabled();

        $this->line('Activa:  '.($enabled ? 'sí' : 'no (TELEMETRY_ENABLED=false)'));
        $this->line('Destino: '.config('telemetry.url'));
        $this->line('Envío:   '.json_encode($pinger->payload(), JSON_UNESCAPED_SLASHES));

        if (! $this->option('send')) {
            return self::SUCCESS;
        }
        if (! $enabled) {
            $this->error('La telemetría está apagada; no se envía nada.');

            return self::FAILURE;
        }

        try {
            $ok = $pinger->send();
        } catch (Throwable $e) {
            $this->error('No se pudo enviar: '.$e->getMessage());

            return self::FAILURE;
        }

        $ok ? $this->info('Enviado.') : $this->error('El colector no aceptó el envío.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
