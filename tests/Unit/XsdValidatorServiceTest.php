<?php

use App\Services\Signature\XadesSignerService;
use App\Services\Xsd\XsdValidatorService;
use Tests\Parity\GoldenFixture;

/**
 * Los XSD v4.4 vienen tal cual de la OVi de TRIBU-CR (revisión con
 * bitácora al 22/04/2026). Estos tests cuidan dos cosas: que compilen con
 * libxml desde las dos carpetas (la importación de xmldsig es relativa) y
 * que traigan los catálogos de la revisión.
 */
function xsdFiles(): array
{
    // El dataset se arma antes de que exista la app: nada de base_path().
    $files = [];
    foreach (['resources/xsd/v4.4', 'legacy/www/xsd'] as $dir) {
        foreach (glob(__DIR__.'/../../'.$dir.'/*_V4.4*.xsd') as $path) {
            $files[$dir.'/'.basename($path)] = [$path];
        }
    }

    return $files;
}

function xsdXPath(string $path): DOMXPath
{
    $dom = new DOMDocument;
    $dom->load($path);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');

    return $xpath;
}

/** Valores de un xs:simpleType con nombre. */
function xsdEnumeration(string $path, string $type): array
{
    $xpath = xsdXPath($path);

    $values = [];
    foreach ($xpath->query("//xs:simpleType[@name='$type']//xs:enumeration/@value") as $node) {
        $values[] = $node->nodeValue;
    }

    return $values;
}

test('el XSD compila', function (string $path) {
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $dom = new DOMDocument;
    $dom->loadXML('<x/>');
    $dom->schemaValidate($path);

    // Con un esquema sano, el único error es que <x/> no está declarado.
    $errors = collect(libxml_get_errors())
        ->map(fn ($e) => trim($e->message))
        ->reject(fn ($m) => str_contains($m, 'No matching global declaration'))
        ->values()
        ->all();

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($errors)->toBe([]);
})->with(xsdFiles());

test('las dos copias de los XSD son iguales', function () {
    foreach (glob(base_path('resources/xsd/v4.4').'/*_V4.4*.xsd') as $path) {
        expect(file_get_contents($path))
            ->toBe(file_get_contents(base_path('legacy/www/xsd/'.basename($path))), basename($path));
    }
});

test('los comprobantes traen los códigos de referencia y TipoDocIR de la revisión', function (string $file, bool $pago) {
    $path = base_path('resources/xsd/v4.4/'.$file);

    $codigos = xsdEnumeration($path, 'CodigoReferenciaType');
    expect($codigos)->toContain('13', '14', '15', '16');
    // El 17 «Pago a comprobante electrónico» es exclusivo del REP.
    expect(in_array('17', $codigos, true))->toBe($pago);

    expect(xsdEnumeration($path, 'TipoDocReferenciaType'))->toContain('19', '20');
})->with([
    ['FacturaElectronica_V4.4.xsd', false],
    ['FacturaElectronica_V4.4-noSign.xsd', false],
    ['TiqueteElectronico_V4.4.xsd', false],
    ['NotaCreditoElectronica_V4.4.xsd', false],
    ['NotaDebitoElectronica_V4.4.xsd', false],
    ['FacturaElectronicaCompra_V4.4.xsd', false],
    ['FacturaElectronicaExportacion_V4.4.xsd', false],
    ['ReciboElectronicoPago_V4.4.xsd', true],
]);

test('la clave admite letras', function () {
    $xpath = xsdXPath(base_path('resources/xsd/v4.4/FacturaElectronica_V4.4.xsd'));

    expect($xpath->evaluate("string(//xs:simpleType[@name='ClaveType']//xs:pattern/@value)"))
        ->toBe('[a-zA-Z0-9]{50,50}');
});

test('XsdValidatorService valida una FE firmada y la misma FE sin firmar', function () {
    $fixture = GoldenFixture::all()['genxml-fe-full'];
    $xml = base64_decode(json_decode($fixture->expectedBody, true)['resp']['xml']);

    $validator = app(XsdValidatorService::class);

    $unsigned = $validator->validate($xml, 'FE', signed: false);
    expect($unsigned['valid'])->toBeTrue(implode("\n", $unsigned['errors']));

    $signedXml = app(XadesSignerService::class)
        ->sign(__DIR__.'/../../legacy/golden/test-cert.p12', '1234', $xml);
    $signed = $validator->validate($signedXml, 'FE');
    expect($signed['valid'])->toBeTrue(implode("\n", $signed['errors']));
});
