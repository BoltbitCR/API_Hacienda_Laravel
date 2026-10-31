<?php

use App\Services\Hacienda\TokenService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * El token del IdP se reutiliza mientras vive (5 min, Anexo 3) en vez de
 * pedir uno por operación (F-06).
 */
test('dos emisiones seguidas piden un solo token al IdP', function () {
    [, , $token] = emisorDePrueba();
    haciendaFalsa();

    $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);
    $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);

    Http::assertSentCount(3); // 1 IdP + 2 recepciones
    expect(collect(Http::recorded())->filter(fn ($par) => str_contains($par[0]->url(), 'idp'))->count())->toBe(1);
});

test('la caché es por credencial y no guarda nada si el IdP no da token', function () {
    Http::fake([
        '*idp*' => Http::sequence()
            ->push(['error' => 'invalid_grant'], 401)
            ->push(['access_token' => 't-1', 'expires_in' => 300], 200)
            ->push(['access_token' => 't-2', 'expires_in' => 300], 200)
            ->push(['access_token' => 't-3', 'expires_in' => 300], 200),
    ]);
    $svc = app(TokenService::class);

    expect($svc->accessToken('api-stag', 'u', 'mala'))->toBeNull()
        ->and($svc->accessToken('api-stag', 'u', 'buena'))->toBe('t-1')
        ->and($svc->accessToken('api-stag', 'u', 'buena'))->toBe('t-1')
        ->and($svc->accessToken('api-stag', 'otro', 'buena'))->toBe('t-2');

    Cache::flush();
    expect($svc->accessToken('api-stag', 'u', 'buena'))->toBe('t-3');
});
