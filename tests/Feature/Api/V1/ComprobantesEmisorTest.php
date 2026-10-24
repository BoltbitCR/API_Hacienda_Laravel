<?php

use App\Models\Company;
use App\Models\HaciendaCredential;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * GET /api/v1/hacienda/comprobantes: lo que Hacienda tiene registrado con la
 * empresa como emisor, recorriendo todas las páginas. Hacienda mockeado.
 */
function emisorConCredenciales(array $company = []): string
{
    $user = User::create([
        'full_name' => 'Emisor', 'user_name' => 'emisor', 'email' => 'emisor@example.com',
        'about' => '', 'country' => 'crc', 'status' => '1', 'legacy_timestamp' => time(), 'last_access' => time(),
        'password' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), 'avatar' => '0', 'settings' => null,
    ]);
    Company::create(array_merge(['id' => $user->id, 'owner_user_id' => $user->id, 'tipo_cedula' => '01', 'cedula' => '702150493'], $company));
    HaciendaCredential::create([
        'company_id' => $user->id, 'environment' => 'prod',
        'username' => 'cpf-07-0215-0493@prod.comprobanteselectronicos.go.cr', 'password' => 'atv', 'pin' => '1234',
    ]);

    return $user->createToken('test', ['master'])->plainTextToken;
}

/** $n comprobantes con claves consecutivas a partir de $desde. */
function paginaDeComprobantes(int $desde, int $n): array
{
    return array_map(fn ($i) => [
        'clave' => '506220826000702150493001000010100000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT).'1'.str_repeat('9', 9),
        'fecha' => '2026-10-22T10:00:00-06:00',
    ], range($desde, $desde + $n - 1));
}

test('recorre todas las páginas con el emisor de la empresa', function () {
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'tok'], 200),
        '*recepcion/v1/comprobantes*' => Http::sequence()
            ->push(paginaDeComprobantes(1, 50), 206, ['Content-Range' => '0-50/97'])
            ->push(paginaDeComprobantes(51, 47), 206, ['Content-Range' => '50-97/97']),
    ]);

    $respuesta = $this->withToken(emisorConCredenciales())->getJson('/api/v1/hacienda/comprobantes?environment=prod')
        ->assertOk();

    expect($respuesta->json('environment'))->toBe('prod')
        ->and($respuesta->json('emisor'))->toBe('01000702150493')
        ->and($respuesta->json('total'))->toBe(97)
        ->and($respuesta->json('comprobantes.96.clave'))->toBe(paginaDeComprobantes(97, 1)[0]['clave']);

    $paginas = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/v1/comprobantes'))
        ->map(fn ($par) => parse_url($par[0]->url(), PHP_URL_QUERY))->values()->all();
    expect($paginas)->toBe([
        'emisor=01000702150493&offset=0&limit=50',
        'emisor=01000702150493&offset=50&limit=50',
    ]);
    Http::assertSent(fn (HttpRequest $r) => ! str_contains($r->url(), '/v1/comprobantes') || $r->hasHeader('Authorization', 'Bearer tok'));
});

test('sin Content-Range o con una página vacía no pide más páginas', function () {
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'tok'], 200),
        '*recepcion/v1/comprobantes*' => Http::response(paginaDeComprobantes(1, 3), 200),
    ]);

    $this->withToken(emisorConCredenciales())->getJson('/api/v1/hacienda/comprobantes')
        ->assertOk()->assertJsonPath('total', 3);
    Http::assertSentCount(2);
});

test('la empresa sin identificación no consulta a Hacienda', function () {
    Http::fake();

    $this->withToken(emisorConCredenciales(['cedula' => '']))->getJson('/api/v1/hacienda/comprobantes')
        ->assertStatus(422);
    Http::assertNothingSent();
});

test('un error de Hacienda en cualquier página responde 502', function () {
    Http::fake([
        '*idp*' => Http::response(['access_token' => 'tok'], 200),
        '*recepcion/v1/comprobantes*' => Http::sequence()
            ->push(paginaDeComprobantes(1, 50), 206, ['Content-Range' => '0-50/97'])
            ->push('Bad Request', 400),
    ]);

    $this->withToken(emisorConCredenciales())->getJson('/api/v1/hacienda/comprobantes')
        ->assertStatus(502);
});

test('sin credenciales del ambiente pedido responde 422', function () {
    Http::fake();

    $this->withToken(emisorConCredenciales())->getJson('/api/v1/hacienda/comprobantes?environment=stag')
        ->assertStatus(422);
    Http::assertNothingSent();
});
