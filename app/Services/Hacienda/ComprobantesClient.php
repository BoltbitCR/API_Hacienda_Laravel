<?php

namespace App\Services\Hacienda;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lista los comprobantes que Hacienda tiene registrados para un emisor
 * (GET /recepcion/v1/comprobantes). Sin el filtro emisor Hacienda responde
 * 400. Pagina de a 50: responde 206 y el total viaja en Content-Range
 * ("0-50/97"). Las confirmaciones del receptor (05-07) no aparecen aquí.
 */
class ComprobantesClient
{
    public const PAGE_SIZE = 50;

    /** Tope de páginas para no quedar en un ciclo si Content-Range miente. */
    public const MAX_PAGES = 400;

    public function __construct(private readonly HaciendaEnvironment $env) {}

    /**
     * Emisor como lo pide Hacienda: tipo de identificación (2 dígitos) y
     * número rellenado con ceros a 12 dígitos.
     */
    public static function emisor(string $tipoCedula, string $cedula): string
    {
        return str_pad($tipoCedula, 2, '0', STR_PAD_LEFT).str_pad(preg_replace('/\D/', '', $cedula), 12, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException si Hacienda no responde 200/206 en alguna página.
     */
    public function listar(string $emisor, string $clientId, string $token): array
    {
        $url = $this->env->comprobantesUrl($clientId) ?? throw new RuntimeException('Ha ocurrido un error en el client_id.');

        $items = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = Http::withToken($token)
                ->withOptions(['verify' => $this->env->verifySsl()])
                ->timeout($this->env->timeout())
                ->get($url, ['emisor' => $emisor, 'offset' => $page * self::PAGE_SIZE, 'limit' => self::PAGE_SIZE]);

            if (! in_array($response->status(), [200, 206], true)) {
                throw new RuntimeException('Hacienda respondió HTTP '.$response->status().' al listar comprobantes: '.$response->body());
            }

            $batch = $response->json();
            if (! is_array($batch) || $batch === []) {
                break;
            }

            array_push($items, ...array_values($batch));

            $total = $this->total($response->header('Content-Range'));
            if ($total === null || count($items) >= $total) {
                break;
            }
        }

        return $items;
    }

    private function total(string $contentRange): ?int
    {
        return preg_match('~/(\d+)\s*$~', $contentRange, $m) === 1 ? (int) $m[1] : null;
    }
}
