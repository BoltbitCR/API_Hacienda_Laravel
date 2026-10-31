<?php

use App\Services\Signature\XadesSignerService;
use Tests\Parity\GoldenFixture;
use Tests\Support\XmlSignatureVerifier;

/**
 * Verifica que la firma XAdES-EPES producida por el port sea
 * criptográficamente válida y estructuralmente equivalente a la del legacy
 * (golden 48-firmar-fe). La firma incluye SigningTime e IDs aleatorios, por
 * lo que la paridad no puede ser byte a byte.
 */
function goldenFeXml(): string
{
    $fixture = GoldenFixture::all()['genxml-fe-full'];
    $resp = json_decode($fixture->expectedBody, true);

    return base64_decode($resp['resp']['xml']);
}

const TEST_P12 = __DIR__.'/../../legacy/golden/test-cert.p12';
const TEST_PIN = '1234';

test('la firma XAdES es criptográficamente válida', function () {
    $signed = app(XadesSignerService::class)->sign(TEST_P12, TEST_PIN, goldenFeXml());

    $result = XmlSignatureVerifier::verify($signed);
    expect($result['ok'])->toBeTrue(implode('; ', $result['errors']));
});

test('el verificador de tests valida la firma del legacy (control)', function () {
    $legacyResp = json_decode(GoldenFixture::all()['firmar-fe']->expectedBody, true);
    $legacySigned = base64_decode($legacyResp['resp']['xmlFirmado']);

    $result = XmlSignatureVerifier::verify($legacySigned);
    expect($result['ok'])->toBeTrue(implode('; ', $result['errors']));
});

test('la firma tiene la misma estructura XAdES que la del legacy', function () {
    $signed = app(XadesSignerService::class)->sign(TEST_P12, TEST_PIN, goldenFeXml());

    $legacyFixture = GoldenFixture::all()['firmar-fe'];
    $legacyResp = json_decode($legacyFixture->expectedBody, true);
    $legacySigned = base64_decode($legacyResp['resp']['xmlFirmado']);

    // Solo el subárbol ds:Signature: el XML firmado cambia cuando cambia el
    // generador (p. ej. el teléfono del emisor), la estructura XAdES no.
    $shape = function (string $xml): array {
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $names = [];
        foreach ($xp->query('//ds:Signature/descendant-or-self::*') as $node) {
            $names[] = $node->nodeName;
        }

        return $names;
    };

    expect($shape($signed))->toBe($shape($legacySigned), 'La secuencia de elementos difiere de la firma legacy');
});

test('la política de firma v4.4 está presente', function () {
    $signed = app(XadesSignerService::class)->sign(TEST_P12, TEST_PIN, goldenFeXml());

    expect($signed)
        ->toContain('SignaturePolicyIdentifier')
        ->toContain('disposiciones_t%C3%A9cnicas_comprobantes_electr%C3%B3nicos')
        ->toContain('DWxin1xWOeI8OuWQXazh4VjLWAaCLAA954em7DMh0h8=');
});

test('rechaza documentos que no son v4.4', function () {
    $xml = '<?xml version="1.0"?><FacturaElectronica xmlns="https://tribunet.hacienda.go.cr/docs/esquemas/2017/v4.2/facturaElectronica"><Clave>1</Clave></FacturaElectronica>';

    app(XadesSignerService::class)->sign(TEST_P12, TEST_PIN, $xml);
})->throws(Exception::class, 'Unsupported Version');
