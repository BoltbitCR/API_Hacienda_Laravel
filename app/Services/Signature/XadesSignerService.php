<?php

namespace App\Services\Signature;

use CRLibre\XmlSecLibs\XMLSecurityDSig;
use CRLibre\XmlSecLibs\XMLSecurityKey;

/**
 * Firma XAdES-EPES de comprobantes electrónicos v4.4.
 *
 * Port de legacy/api/contrib/firmarXML/hacienda/firmador.php
 * (Hacienda\Firmador, © 2019 Enzo Jiménez, AGPL-3.0) sobre el paquete
 * interno crlibre/xades-xmlseclibs. La secuencia de referencias, la
 * canonicalización C14N y la política de firma se conservan idénticas:
 * cambiarlas provoca rechazo en la recepción de Hacienda.
 */
class XadesSignerService
{
    /**
     * @param  string  $p12Path  Ruta local del certificado .p12.
     * @param  string  $pin  PIN del certificado.
     * @param  string  $xml  Documento XML (string, sin firmar).
     * @return string XML firmado.
     *
     * @throws \Exception si el p12/pin es inválido o el XML no es v4.4.
     */
    public function sign(string $p12Path, string $pin, string $xml): string
    {
        $doc = new \DOMDocument;
        $doc->loadXML($xml);

        $objSec = new XMLSecurityDSig;
        $objSec->xmlFirstChild = $doc->firstChild;

        $objSec->setSignPolicy(array_filter((array) config('hacienda.sign_policy'), 'filled'));

        $certInfo = $objSec->loadCertInfo($p12Path, $pin);

        $objSec->setCanonicalMethod($objSec::C14N);

        $objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $objKey->loadKey($certInfo['privateKey']);

        $objSec->add509Cert($certInfo['publicKey'], true);
        $objSec->appendKeyValue($certInfo);
        $objSec->appendXades($certInfo);

        // Referencia 0: el documento (enveloped signature).
        $objSec->addReference(
            $doc,
            $objSec::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
            ['id_ref' => $objSec->reference0Id, 'force_uri' => true]
        );

        // Referencia 1: el nodo KeyInfo.
        $objSec->addReference(
            $objSec->getKeyInfoNode(),
            $objSec::SHA256,
            null,
            ['id_ref' => $objSec->reference1Id, 'force_uri' => false, 'overwrite' => false]
        );

        // Referencia 2: las SignedProperties de XAdES.
        $objSec->addReference(
            $objSec->getXadesNode(),
            $objSec::SHA256,
            null,
            ['force_uri' => false, 'overwrite' => false, 'type' => 'http://uri.etsi.org/01903#SignedProperties'],
            [['qualifiedName' => 'xmlns:xades', 'value' => $objSec::XADES]]
        );

        $objSec->sign($objKey);
        $objSec->appendSignature($doc->documentElement);

        return $doc->saveXML();
    }

    public function signToBase64(string $p12Path, string $pin, string $xml): string
    {
        return base64_encode($this->sign($p12Path, $pin, $xml));
    }
}
