<?php

use App\Models\HaciendaCredential;
use App\Models\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;

/**
 * El .p12 subido por la API se guarda cifrado con la APP_KEY (F-07) y la
 * emisión lo descifra en un temporal que no sobrevive a la petición. Los
 * certificados anteriores (en claro) siguen funcionando.
 */
test('el certificado subido por REST queda cifrado en disco y sigue firmando', function () {
    [, $company, $token] = emisorDePrueba();
    haciendaFalsa();

    $p12 = file_get_contents(base_path('legacy/golden/test-cert.p12'));
    $r = $this->withToken($token)->post('/api/v1/company/certificate', [
        'environment' => 'stag',
        'certificate' => UploadedFile::fake()->createWithContent('cert.p12', $p12),
        'pin' => '1234',
    ], ['Accept' => 'application/json'])->assertCreated();

    $stored = StoredFile::where('download_code', $r->json('p12_download_code'))->firstOrFail();
    $enDisco = file_get_contents(storage_path('app/'.$stored->path));

    expect($stored->encrypted)->toBeTrue()
        ->and($enDisco)->not->toBe($p12)
        ->and(Crypt::decryptString($enDisco))->toBe($p12)
        ->and($stored->download_code)->toMatch('/^[0-9a-f]{32}$/')
        ->and(HaciendaCredential::where('company_id', $company->id)->value('p12_download_code'))->toBe($stored->download_code);

    $antes = count(glob(sys_get_temp_dir().'/p12-*'));
    $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);
    expect(count(glob(sys_get_temp_dir().'/p12-*')))->toBe($antes);
});

test('un certificado guardado en claro antes del cifrado sigue firmando', function () {
    [, , $token] = emisorDePrueba(); // el helper lo deja en claro (encrypted = false)
    haciendaFalsa();

    $this->withToken($token)->postJson('/api/v1/documents', cuerpoFe())->assertStatus(201);
});

test('la vía legacy firma con un certificado cifrado y lo descarga descifrado', function () {
    config(['hacienda.legacy.enabled' => true]);
    [$user] = emisorDePrueba();
    $p12 = file_get_contents(base_path('legacy/golden/test-cert.p12'));

    $stored = StoredFile::create([
        'user_id' => $user->id, 'name' => 'cifrado.p12', 'download_code' => 'cifrado-1', 'type' => 'hacienda',
        'path' => 'legacy-files/'.$user->id.'/hacienda/cifrado.p12', 'encrypted' => true,
    ]);
    file_put_contents(storage_path('app/'.$stored->path), Crypt::encryptString($p12));

    $xml = base64_encode('<?xml version="1.0" encoding="utf-8"?><FacturaElectronica xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica"><Clave>1</Clave></FacturaElectronica>');
    $firmado = $this->post('/api.php', ['w' => 'firmarXML', 'r' => 'firmar', 'p12Url' => 'cifrado-1', 'pinP12' => '1234', 'inXml' => $xml])
        ->assertOk()->json('resp.xmlFirmado');
    expect(base64_decode((string) $firmado))->toContain('<ds:Signature');

    $this->get('/api.php?w=files&r=files_view_file&code=cifrado-1')->assertOk()->assertSee($p12, false);
});
