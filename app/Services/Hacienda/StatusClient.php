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
     * @return object|null el estado (`ind-estado`, `respuesta-xml`…), o null si
     *                     Hacienda no tiene esa clave (404 o cuerpo vacío).
     *
     * @throws RuntimeException si no se pudo consultar: sin conexión, 5xx u otro
     *                          error de Hacienda (el mensaje trae la causa).
     */
    public function estado(string $clave, string $clientId, string $token): ?object
    {
        $url = $this->env->receptionUrl($clientId) ?? throw new RuntimeException('Ha ocurrido un error en el client_id.');

        try {
            $response = $this->get($url, $clave, $token);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo consultar Hacienda: '.$e->getMessage(), previous: $e);
        }

        if ($response->notFound()) {
            return null;
        }

        if (! $response->successful()) {
            $causa = trim((string) $response->header('X-Error-Cause'));
            throw new RuntimeException("Hacienda respondió {$response->status()} a la consulta".($causa !== '' ? ": $causa" : '.'));
        }

        $estado = json_decode($response->body());

        return is_object($estado) ? $estado : null;
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
