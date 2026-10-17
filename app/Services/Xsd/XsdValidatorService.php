<?php

namespace App\Services\Xsd;

/**
 * Validación de comprobantes contra los XSD oficiales v4.4
 * (resources/xsd/v4.4/, tomados del repo legacy).
 *
 * Sustituye al módulo legacy `check`, que estaba roto (cargaba un fac.xml
 * hardcodeado contra el XSD 4.2 y solo para FE). Divergencia documentada:
 * el golden master 31-check-xml-fe captura un volcado de warnings HTML que
 * no es reproducible ni útil como contrato.
 */
class XsdValidatorService
{
    private const XSD_BY_TIPO = [
        'FE' => 'FacturaElectronica_V4.4.xsd',
        'ND' => 'NotaDebitoElectronica_V4.4.xsd',
        'NC' => 'NotaCreditoElectronica_V4.4.xsd',
        'TE' => 'TiqueteElectronico_V4.4.xsd',
        'CCE' => 'MensajeReceptor_V4.4.xsd',
        'CPCE' => 'MensajeReceptor_V4.4.xsd',
        'RCE' => 'MensajeReceptor_V4.4.xsd',
        'FEC' => 'FacturaElectronicaCompra_V4.4.xsd',
        'FEE' => 'FacturaElectronicaExportacion_V4.4.xsd',
        'REP' => 'ReciboElectronicoPago_V4.4.xsd',
    ];

    /** Variante sin firma, útil para validar antes de firmar. */
    private const XSD_NOSIGN = [
        'FE' => 'FacturaElectronica_V4.4-noSign.xsd',
    ];

    public function supports(string $tipoDocumento): bool
    {
        return isset(self::XSD_BY_TIPO[$tipoDocumento]);
    }

    /**
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(string $xml, string $tipoDocumento, bool $signed = true): array
    {
        $file = $signed
            ? self::XSD_BY_TIPO[$tipoDocumento] ?? null
            : (self::XSD_NOSIGN[$tipoDocumento] ?? self::XSD_BY_TIPO[$tipoDocumento] ?? null);

        if ($file === null) {
            throw new \InvalidArgumentException("Tipo de documento sin XSD: $tipoDocumento");
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument;
        $errors = [];

        if (! $dom->loadXML($xml)) {
            $errors[] = 'El XML no es válido';
        } elseif (! $dom->schemaValidate(resource_path('xsd/v4.4/'.$file))) {
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message).' (línea '.$error->line.')';
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return ['valid' => $errors === [], 'errors' => $errors];
    }
}
