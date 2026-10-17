<?php

namespace App\Services\Xml;

/**
 * Catálogos de InformacionReferencia de los Anexos y Estructuras 4.4
 * (revisión con bitácora al 22/04/2026), en un solo lugar: los usan el
 * generador y la validación de la API REST.
 */
final class InformacionReferencia
{
    /** Nota 10: TipoDocIR (tipo del documento de referencia). */
    public const TIPOS_DOC = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '99'];

    /** Nota 9: código de referencia. */
    public const CODIGOS = ['01', '02', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '99'];

    /** Nota 9, nota al pie 35: el 17 «Pago a comprobante electrónico» es exclusivo del Recibo Electrónico de Pago. */
    public const CODIGOS_SOLO_REP = ['17'];

    /**
     * TipoDocIR cuyo Numero Hacienda valida como clave (pág. 58), más el 10
     * (comprobante rechazado), que también tiene clave de 50.
     */
    public const TIPOS_CON_CLAVE = ['01', '02', '03', '04', '09', '10', '11', '12', '15', '17', '18', '19', '20'];

    /** Pág. 58: con TipoDocIR 13 «Facturación mes vencido» no son obligatorios Numero, Codigo ni Razon. */
    public const TIPO_MES_VENCIDO = '13';

    /** Nota al pie 36: el 09 «Devolución mercadería» solo en NC y ND. */
    public const TIPO_SOLO_NOTAS = '09';

    /** Pág. 57: el 16 «Comprobante de Proveedor No Domiciliado» solo en la Factura Electrónica de Compra. */
    public const TIPO_SOLO_FEC = '16';

    /** Máximo de repeticiones de InformacionReferencia en el XSD. */
    public const MAXIMO = 10;
}
