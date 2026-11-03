<?php

namespace App\Services\Xml;

/**
 * Tipos de identificación de `Identificacion/Tipo` en el XML v4.4
 * (IdentificacionType del XSD).
 *
 * El catálogo `tipo_cedula` heredado guarda nombres (`fisico`, `juridico`…)
 * que el XSD no acepta; se conserva tal cual para la capa legacy.
 */
final class TipoIdentificacion
{
    public const CODIGOS = [
        '01' => 'Cédula Física',
        '02' => 'Cédula Jurídica',
        '03' => 'DIMEX',
        '04' => 'NITE',
        '05' => 'Extranjero No Domiciliado',
        '06' => 'No Contribuyente',
    ];

    private const NOMBRES_HEREDADOS = [
        'fisico' => '01',
        'juridico' => '02',
        'dimex' => '03',
        'nite' => '04',
        'extranjero' => '05',
        'extragero' => '05',
    ];

    /** @return list<array{codigo: string, descripcion: string}> */
    public static function catalogo(): array
    {
        return array_map(
            fn (string $codigo, string $descripcion) => ['codigo' => $codigo, 'descripcion' => $descripcion],
            array_keys(self::CODIGOS),
            self::CODIGOS,
        );
    }

    /** Código v4.4 de un nombre heredado; cualquier otro valor pasa igual. */
    public static function codigo(string $tipo): string
    {
        return self::NOMBRES_HEREDADOS[strtolower(trim($tipo))] ?? $tipo;
    }
}
