<?php

namespace App\Services\Hacienda;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Envío de comprobantes a la recepción de Hacienda. Port de
 * legacy/api/contrib/send/send.php (send / sendMensaje / sendTE).
 *
 * Contrato conservado: el legacy usaba CURLOPT_HEADER=true, por lo que
 * `text` es explode("\n", <headers crudos + body>) — los clientes parsean
 * ese formato. El shape de retorno es siempre ['Status' => <código HTTP de
 * Hacienda>, 'text' => ...], que LegacyResponse::reply() colapsa (error=>500,
 * ok=>200, otro=>400).
 */
class ReceptionClient
{
    public function __construct(private readonly HaciendaEnvironment $env) {}

    /**
     * @param  array<string, mixed>  $datos  Payload de recepción ya armado.
     * @return array{Status: mixed, to?: string, text: mixed}
     */
    public function send(array $datos, string $clientId, string $token): array
    {
        $url = $this->env->receptionUrl($clientId);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'bearer '.$token,
                'Content-Type' => 'application/json',
            ])
                ->withBody(json_encode($datos), 'application/json')
                ->withOptions(['verify' => $this->env->verifySsl()])
                ->connectTimeout($this->env->connectTimeout())
                ->timeout($this->env->timeout())
                ->post((string) $url);
        } catch (\Throwable $e) {
            // Equivalente al branch de curl_error del legacy.
            return [
                'Status' => 0,
                'to' => $clientId,
                'text' => $e->getMessage(),
            ];
        }

        return [
            'Status' => $response->status(),
            'text' => explode("\n", $this->rawHttpMessage($response)),
        ];
    }

    /**
     * Reconstruye el mensaje HTTP crudo (status line + headers + body) tal
     * como lo entregaba curl con CURLOPT_HEADER=true.
     */
    private function rawHttpMessage(Response $response): string
    {
        $psr = $response->toPsrResponse();

        $version = $psr->getProtocolVersion();
        $reason = $psr->getReasonPhrase();
        $raw = 'HTTP/'.$version.' '.$psr->getStatusCode().($reason !== '' ? ' '.$reason : ' ')."\r\n";

        foreach ($psr->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $raw .= $name.': '.$value."\r\n";
            }
        }

        return $raw."\r\n".$response->body();
    }
}
