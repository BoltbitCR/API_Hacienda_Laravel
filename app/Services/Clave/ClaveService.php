<?php

namespace App\Services\Clave;

/**
 * Generación de la clave numérica de 50 dígitos y del consecutivo de 20
 * dígitos de los comprobantes electrónicos de Costa Rica.
 *
 * Port fiel de legacy/api/contrib/clave/clave.php::getClave(). Los mensajes
 * de error son parte del contrato del API legacy: no cambiarlos.
 */
class ClaveService
{
    public const TIPOS_DOCUMENTO = [
        'FE' => '01',   // Factura Electrónica
        'ND' => '02',   // Nota de Débito
        'NC' => '03',   // Nota de Crédito
        'TE' => '04',   // Tiquete Electrónico
        'CCE' => '05',  // Confirmación Comprobante Electrónico
        'CPCE' => '06', // Confirmación Parcial Comprobante Electrónico
        'RCE' => '07',  // Rechazo Comprobante Electrónico
        'FEC' => '08',  // Factura Electrónica de Compra
        'FEE' => '09',  // Factura Electrónica de Exportación
        'REP' => '10',  // Recibo Electrónico de Pago (Anexos 4.4, nota 3)
    ];

    /**
     * Cédula jurídica con consecutivo alfanumérico del Registro Nacional
     * (oficio DGL-188-2026): 4 dígitos de clase + 6 caracteres, p. ej.
     * 3101A00001. Hacienda la admite en la clave (Anexos 4.4, nota 4.1).
     */
    public const CEDULA_JURIDICA_ALFANUMERICA = '/^[0-9]{4}[0-9A-Z]{6}$/';

    public const SITUACIONES = [
        'normal' => 1,
        'contingencia' => 2,
        'sininternet' => 3,
    ];

    /**
     * @return array{clave: string, consecutivo: string, length: int}|string
     *                                                                       El array con la clave, o un string con el mensaje de error
     *                                                                       (contrato legacy: los errores de validación son strings).
     */
    public function generate(
        string $tipoDocumento,
        string $tipoCedula,
        string $cedula,
        string $situacion,
        string $codigoPais,
        string $consecutivo,
        string $codigoSeguridad,
        string $sucursal = '001',
        string $terminal = '00001',
        ?\DateTimeInterface $fecha = null,
    ): array|string {
        $fecha ??= now();
        $dia = $fecha->format('d');
        $mes = $fecha->format('m');
        $ano = $fecha->format('y');

        $esJuridica = in_array($tipoCedula, ['juridico', '02'], true);
        if ($esJuridica) {
            $cedula = self::normalizarCedula($cedula);
        }

        if (! ctype_digit($cedula)
            && ! ($esJuridica && preg_match(self::CEDULA_JURIDICA_ALFANUMERICA, $cedula))) {
            return 'El parametro cedula no es numeral';
        }

        if (! ctype_digit($codigoPais)) {
            return 'El parametro codigoPais no es numeral';
        } elseif (strlen($codigoPais) != 3) {
            return 'El parametro codigoPais debe ser de 3 digitos';
        }

        if (! ctype_digit($sucursal)) {
            return 'El parametro sucursal no es numeral';
        } elseif (strlen($sucursal) < 3) {
            $sucursal = str_pad($sucursal, 3, '0', STR_PAD_LEFT);
        } elseif (strlen($sucursal) > 3) {
            return 'El parametro sucursal debe ser de 3 digitos';
        }

        if (! ctype_digit($terminal)) {
            return 'El parametro terminal no es numeral';
        } elseif (strlen($terminal) < 5) {
            $terminal = str_pad($terminal, 5, '0', STR_PAD_LEFT);
        } elseif (strlen($terminal) > 5) {
            return 'El parametro terminal debe ser de 5 digitos';
        }

        if (! ctype_digit($consecutivo)) {
            return 'El parametro consecutivo no es numeral';
        } elseif (strlen($consecutivo) < 10) {
            $consecutivo = str_pad($consecutivo, 10, '0', STR_PAD_LEFT);
        } elseif (strlen($consecutivo) > 10) {
            return 'El parametro consecutivo debe ser de 10 digitos';
        }

        if (! ctype_digit($codigoSeguridad)) {
            return 'El parametro codigoSeguridad no es numeral';
        } elseif (strlen($codigoSeguridad) < 8) {
            $codigoSeguridad = str_pad($codigoSeguridad, 8, '0', STR_PAD_LEFT);
        } elseif (strlen($codigoSeguridad) > 8) {
            return 'El parametro codigoSeguridad debe ser de 8 digitos';
        }

        $codigoTipoDocumento = self::TIPOS_DOCUMENTO[$tipoDocumento] ?? null;
        if ($codigoTipoDocumento === null) {
            return "No se encuentra el tipo de documento [$tipoDocumento]";
        }

        $consecutivoFinal = $sucursal.$terminal.$codigoTipoDocumento.$consecutivo;

        $identificacion = null;
        switch ($tipoCedula) {
            case 'fisico':
            case '01':
            case 'nite':
            case '04':
                $identificacion = str_pad($cedula, 12, '0', STR_PAD_LEFT);
                break;
            case 'juridico':
            case '02':
                if (strlen($cedula) < 12) {
                    $identificacion = str_pad($cedula, 12, '0', STR_PAD_LEFT);
                } elseif (strlen($cedula) === 12) {
                    $identificacion = $cedula;
                } else {
                    return 'cedula juridico incorrecto';
                }
                break;
            case 'dimex':
            case '03':
                if (strlen($cedula) < 12) {
                    $identificacion = str_pad($cedula, 12, '0', STR_PAD_LEFT);
                } elseif (strlen($cedula) == 12) {
                    $identificacion = $cedula;
                } else {
                    return 'dimex incorrecto';
                }
                break;
            default:
                return 'No se encuentra tipo de cedula';
        }

        $codSituacion = self::SITUACIONES[strtolower($situacion)] ?? null;
        if ($codSituacion === null) {
            return "No se encuentra el tipo de situacion [$situacion]";
        }

        $clave = $codigoPais.$dia.$mes.$ano.$identificacion.$consecutivoFinal.$codSituacion.$codigoSeguridad;

        return [
            'clave' => "$clave",
            'consecutivo' => "$consecutivoFinal",
            'length' => strlen($clave),
        ];
    }

    /** Mayúsculas y sin guiones ni espacios: «3-101-a00001» → «3101A00001». */
    public static function normalizarCedula(string $cedula): string
    {
        return strtoupper(str_replace(['-', ' '], '', $cedula));
    }
}
