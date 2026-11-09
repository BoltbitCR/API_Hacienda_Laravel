<?php

namespace App\Services\Hacienda;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Consulta de estado de un comprobante en Hacienda. Port de
 * legacy/api/contrib/consultar/consultar.php::consutar().
 *
 * Contrato conservado: mensajes de error como strings con status ok, y la
 * respuesta de Hacienda devuelta con json_decode del body (sin headers).
 */
class StatusClient
{
    public function __construct(private readonly HaciendaEnvironment $env) {}

    public function consultar(string $clave, string $clientId, string $token): mixed
    {
        if ($clave === '') {
            return 'La clave no puede ser en blanco';
        }

        $url = $this->env->receptionUrl($clientId);
        if ($url === null) {
            return 'Ha ocurrido un error en el client_id.';
        }

        try {
            $response = $this->get($url, $clave, $token);
        } catch (\Throwable $e) {
            // El branch de error del legacy referencia $apiTo sin definir
            // (notice suprimido => null).
            return [
                'Status' => 0,
                'to' => null,
                'text' => $e->getMessage(),
            ];
        }

        return json_decode($response->body());
    }

    /**
     * Estado del comprobante para la API REST, que necesita distinguir los
     * casos que consultar() mezcla.
     *
     * Hacienda responde a una clave que no recibió con 400 y `X-Error-Cause`
     * «El comprobante [clave] no ha sido recibido.»; el 404 también se toma
     * como «no la tiene».
     *
     * @return object|null el estado (`ind-estado`, `respuesta-xml`…), o null si
     *                     Hacienda no tiene esa clave.
     *
     * @throws RuntimeException si no se pudo consultar: sin conexión, 5xx, otro
     *                          error de Hacienda o un 200 sin estado (el mensaje
     *                          trae la causa).
     */
    public function estado(string $clave, string $clientId, string $token): ?object
    {
        $url = $this->env->receptionUrl($clientId) ?? throw new RuntimeException('Ha ocurrido un error en el client_id.');

        try {
            $response = $this->get($url, $clave, $token);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo consultar Hacienda: '.$e->getMessage(), previous: $e);
        }

        $causa = trim((string) $response->header('X-Error-Cause'));

        if ($response->notFound() || ($response->status() === 400 && str_contains(mb_strtolower($causa), 'no ha sido recibido'))) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException("Hacienda respondió {$response->status()} a la consulta".($causa !== '' ? ": $causa" : '.'));
        }

        // Un 200 sin estado no dice si Hacienda tiene la clave: no se toma como «no la tiene».
        $estado = json_decode($response->body());
        if (! is_object($estado)) {
            throw new RuntimeException("Hacienda respondió {$response->status()} a la consulta sin el estado del comprobante.");
        }

        return $estado;
    }

    private function get(string $url, string $clave, string $token): Response
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])
            ->withOptions(['verify' => $this->env->verifySsl()])
            ->connectTimeout($this->env->connectTimeout())
            ->timeout(30)
            ->get($url.$clave);
    }
}
