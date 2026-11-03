<?php

use App\Models\Company;
use App\Models\HaciendaCredential;
use App\Models\StoredFile;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Http\UploadedFile;

/**
 * CRUD y catálogos de la API v1.
 */
function ownerToken(): array
{
    $user = User::create([
        'full_name' => 'Owner', 'user_name' => 'owner', 'email' => 'owner@example.com',
        'about' => '', 'country' => 'crc', 'status' => '1', 'legacy_timestamp' => time(),
        'last_access' => time(), 'password' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
        'avatar' => '0', 'settings' => null,
    ]);
    $company = Company::create([
        'id' => $user->id, 'owner_user_id' => $user->id, 'nombre' => 'ACME',
        'tipo_cedula' => '02', 'cedula' => '3101234567', 'env' => 'api-stag',
    ]);

    return [$user->createToken('t', ['master'])->plainTextToken, $company];
}

test('los catálogos geográficos son públicos', function () {
    (new CatalogSeeder)->run();

    $this->getJson('/api/v1/geo/provinces')->assertOk()->assertJsonFragment(['nombre_provincia' => 'San jose']);
    $this->getJson('/api/v1/geo/provinces/1/cantons')->assertOk();
    $this->getJson('/api/v1/catalogs/tax-types')->assertOk();
    $this->getJson('/api/v1/catalogs/measure-units')->assertOk();
});

test('catalogs/id-types devuelve los códigos 01–06 del XML v4.4', function () {
    $this->getJson('/api/v1/catalogs/id-types')->assertOk()->assertExactJson([
        ['codigo' => '01', 'descripcion' => 'Cédula Física'],
        ['codigo' => '02', 'descripcion' => 'Cédula Jurídica'],
        ['codigo' => '03', 'descripcion' => 'DIMEX'],
        ['codigo' => '04', 'descripcion' => 'NITE'],
        ['codigo' => '05', 'descripcion' => 'Extranjero No Domiciliado'],
        ['codigo' => '06', 'descripcion' => 'No Contribuyente'],
    ]);
});

test('CRUD de receptores respeta la empresa del token', function () {
    [$token, $company] = ownerToken();

    $create = $this->withToken($token)->postJson('/api/v1/receivers', [
        'nombre_cliente' => 'Cliente 1', 'numero_cedula' => '108880777', 'tipo_cedula' => '01',
    ])->assertCreated();

    $id = $create->json('id');
    $this->withToken($token)->getJson('/api/v1/receivers')->assertOk()->assertJsonFragment(['nombre_cliente' => 'Cliente 1']);
    $this->withToken($token)->putJson("/api/v1/receivers/$id", ['nombre_cliente' => 'Cliente 1', 'numero_cedula' => '108880777', 'tipo_cedula' => '01', 'nombre_comercial' => 'C1'])->assertOk();
    $this->withToken($token)->deleteJson("/api/v1/receivers/$id")->assertOk();

    // Borrado lógico: estado_cliente = 0.
    $this->assertDatabaseHas('receivers', ['id' => $id, 'estado_cliente' => '0']);
});

test('CRUD de inventario y credenciales', function () {
    [$token] = ownerToken();

    $this->withToken($token)->postJson('/api/v1/products', [
        'sucursal' => '001', 'nombre' => 'Producto', 'unidad_medida' => 'Unid',
        'precio_venta' => 1500, 'codigo_barras' => 'ABC123',
    ])->assertCreated();

    $this->withToken($token)->getJson('/api/v1/products')->assertOk()->assertJsonFragment(['codigo_barras' => 'ABC123']);

    // Credenciales: password/pin se guardan cifrados y no se devuelven.
    $this->withToken($token)->putJson('/api/v1/company/credentials', [
        'environment' => 'stag', 'username' => 'user@stag', 'password' => 'secreto', 'pin' => '1234',
    ])->assertCreated()->assertJsonMissing(['password' => 'secreto']);

    $this->withToken($token)->getJson('/api/v1/company/credentials')
        ->assertOk()->assertJsonFragment(['environment' => 'stag', 'has_pin' => true]);
});

test('la ruta de documentación OpenAPI está registrada y protegida', function () {
    // Scramble restringe /docs fuera del entorno local (403), no 404: la
    // ruta existe y está bajo control de acceso. En local se sirve la UI.
    $this->get('/docs/api.json')->assertStatus(403);

    expect(collect(app('router')->getRoutes())->contains(
        fn ($r) => $r->uri() === 'docs/api.json'
    ))->toBeTrue();
});

test('sube el certificado .p12 y lo vincula a la credencial del ambiente', function () {
    [$token, $company] = ownerToken();

    $response = $this->withToken($token)->post('/api/v1/company/certificate', [
        'environment' => 'stag',
        'certificate' => UploadedFile::fake()->create('certificado.p12', 10),
        'pin' => '1234',
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('environment', 'stag')
        ->assertJsonPath('has_pin', true);

    $code = $response->json('p12_download_code');
    $cred = HaciendaCredential::where('company_id', $company->id)->where('environment', 'stag')->firstOrFail();
    $stored = StoredFile::where('download_code', $code)->first();

    expect($code)->not->toBeEmpty()
        ->and($cred->p12_download_code)->toBe($code)
        ->and($cred->pin)->toBe('1234')
        ->and($stored)->not->toBeNull()
        ->and(is_file(storage_path('app/'.$stored->path)))->toBeTrue();

    @unlink(storage_path('app/'.$stored->path));
});

test('rechaza certificados que no son .p12 o .pfx', function () {
    [$token] = ownerToken();

    $this->withToken($token)->post('/api/v1/company/certificate', [
        'environment' => 'stag',
        'certificate' => UploadedFile::fake()->create('malware.exe', 10),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('certificate');
});
