<?php

use App\Services\Hacienda\ComprobantesClient;
use App\Services\Hacienda\ReceptionClient;
use App\Services\Hacienda\StatusClient;
use App\Services\Hacienda\TokenService;
use Illuminate\Support\Facades\Http;

/**
 * Todas las llamadas a Hacienda conectan con HACIENDA_CONNECT_TIMEOUT (corto)
 * y respetan su tope por petición: una caída falla rápido en vez de retener
 * el proceso hasta el timeout completo.
 */
test('las llamadas a Hacienda pasan connect_timeout y timeout de la config', function () {
    config(['hacienda.connect_timeout' => 7, 'hacienda.timeout' => 45]);

    $opciones = [];
    Http::fake(function ($request, array $options) use (&$opciones) {
        $opciones[] = [$request->url(), $options['connect_timeout'] ?? null, $options['timeout'] ?? null];

        return str_contains($request->url(), 'idp')
            ? Http::response(['access_token' => 't', 'expires_in' => 300], 200)
            : Http::response('[]', 200);
    });

    app(TokenService::class)->accessToken('api-stag', 'u', 'p');
    app(ReceptionClient::class)->send(['clave' => 'x'], 'api-stag', 't');
    app(StatusClient::class)->estado(str_repeat('5', 50), 'api-stag', 't');
    app(ComprobantesClient::class)->listar('023101234567', 'api-stag', 't');

    expect($opciones)->toHaveCount(4)
        ->and(array_column($opciones, 1))->each->toBe(7)
        // La consulta de estado conserva su tope histórico de 30 s.
        ->and(array_column($opciones, 2))->toBe([45, 45, 30, 45]);
});

test('connect_timeout por defecto es 10 s', function () {
    expect(config('hacienda.connect_timeout'))->toBe(10);
});
