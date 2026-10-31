<?php

namespace App\Legacy\Modules;

use App\Legacy\LegacyModule;
use App\Legacy\LegacyParams;
use App\Legacy\LegacyResponse;
use App\Legacy\LegacyRoute;
use App\Services\Files\FileStorageService;
use App\Services\Signature\XadesSignerService;

/**
 * Port de legacy/api/contrib/firmarXML (w=firmarXML&r=firmar).
 *
 * Peculiaridad de contrato conservada: ante un PIN incorrecto o un p12
 * ilegible, el legacy moría con un fatal silencioso => HTTP 200 con body
 * vacío (golden 49-firmar-bad-pin). Se replica capturando la excepción.
 */
class FirmarXmlModule implements LegacyModule
{
    public function __construct(
        private readonly XadesSignerService $signer,
        private readonly FileStorageService $files,
    ) {}

    public function routes(): array
    {
        return [
            new LegacyRoute(
                r: 'firmar',
                action: function (LegacyParams $p) {
                    // El .p12 puede estar cifrado en reposo: ruta legible temporal.
                    $stored = $this->files->findByDownloadCode((string) $p->get('p12Url'));
                    $pfx = (string) ($stored ? $this->files->readablePath($stored) : '');
                    $pin = (string) $p->get('pinP12');
                    $xml = base64_decode((string) $p->get('inXml'));

                    try {
                        $base64 = $this->signer->signToBase64($pfx, $pin, (string) $xml);
                    } catch (\Throwable) {
                        return LegacyResponse::plainDie('');
                    } finally {
                        $this->files->discardTemp($pfx);
                    }

                    return ['xmlFirmado' => $base64];
                },
                params: [
                    ['key' => 'p12Url', 'def' => '', 'req' => true],
                    ['key' => 'pinP12', 'def' => '', 'req' => true],
                    ['key' => 'inXml', 'def' => '', 'req' => false],
                ],
            ),
        ];
    }
}
