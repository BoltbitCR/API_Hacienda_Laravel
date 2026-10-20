<?php

/**
 * Shims de runtime para el código legacy portado verbatim (genxml_functions.php
 * y futuros ports). Dentro del namespace App\Services\Xml\Legacy, las llamadas
 * a params_get()/grace_debug()/etc. resuelven aquí en lugar de al framework
 * casero original.
 */

namespace App\Services\Xml\Legacy;

use App\Legacy\LegacyParams;

/**
 * Excepción que replica el tools_reply($msg, true) del legacy: corta la
 * ejecución y responde {"status":"error","resp":"ERROR: <msg>"} con HTTP 200.
 */
class LegacyReplyKill extends \RuntimeException {}

/**
 * Holder del set de parámetros de la petición en curso (sustituye al
 * global $params del legacy).
 */
final class CurrentParams
{
    private static ?LegacyParams $params = null;

    public static function set(?LegacyParams $params): void
    {
        self::$params = $params;
    }

    public static function get(): LegacyParams
    {
        return self::$params ??= LegacyParams::fromArray([]);
    }

    /**
     * Ejecuta $fn con $params como contexto y limpia al terminar.
     *
     * El legacy corre con error_reporting(0): acceder a propiedades o claves
     * inexistentes concatena vacío en lugar de fallar (p. ej. genXMLTE emite
     * <ImpuestoAsumidoEmisorFabrica></...> cuando el detalle no trae el
     * campo). En PHP 8 esos avisos serían ErrorException vía el handler de
     * Laravel, así que aquí se suprimen los no fatales durante la ejecución
     * del código portado.
     */
    public static function with(LegacyParams $params, \Closure $fn): mixed
    {
        $previous = self::$params;
        self::$params = $params;
        try {
            return withLegacyErrorSuppression($fn);
        } finally {
            self::$params = $previous;
        }
    }
}

/**
 * Ejecuta $fn con los errores no fatales silenciados, como el
 * error_reporting(0) del legacy. Imprescindible para código portado que
 * escribe a un output buffer (p. ej. phpqrcode: un Deprecated impreso
 * dentro del buffer corrompería el PNG).
 */
function withLegacyErrorSuppression(\Closure $fn): mixed
{
    set_error_handler(
        fn (): bool => true,
        E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED
    );
    try {
        return $fn();
    } finally {
        restore_error_handler();
    }
}

function params_get(string $p, mixed $def = false): mixed
{
    return CurrentParams::get()->get($p, $def);
}

function params_set(string $p, mixed $val = false): void
{
    CurrentParams::get()->set($p, $val);
}

function grace_debug(mixed $message): void
{
    // El legacy escribía a su propio log; aquí es ruido: no-op.
}

function grace_error(mixed $message): void
{
    logger()->warning('[legacy-port] '.(is_scalar($message) ? $message : json_encode($message)));
}

function tools_reply(mixed $response, bool $killMe = false): void
{
    // El único uso dentro del código portado es con $killMe=true (corta la
    // petición con un error de validación de detalle).
    throw new LegacyReplyKill(is_scalar($response) ? (string) $response : json_encode($response));
}

/**
 * Semántica PHP 7.4 de `$v != ""`. El legacy corre en PHP 7.4, donde
 * 0 != "" es false (número vs string vacío compara numéricamente); en
 * PHP 8 es true. El port verbatim usa estos helpers en lugar de los
 * operadores para conservar la salida byte a byte (p. ej. omitir
 * <ImpuestoAsumidoEmisorFabrica> cuando el monto es 0).
 */
function _ne74(mixed $v): bool
{
    if (is_int($v) || is_float($v)) {
        return $v != 0;
    }

    return $v != '';
}

/**
 * Valor listo para un nodo de texto del XML. El legacy concatenaba los
 * datos crudos, así que un `&` o `<` en un nombre, una dirección o un
 * detalle dejaba el comprobante mal formado y la firma fallaba. El cast
 * es el mismo de la concatenación: sin caracteres especiales la salida
 * no cambia.
 */
function _xml(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Semántica PHP 7.4 de `$v == ""`. */
function _eq74(mixed $v): bool
{
    return ! _ne74($v);
}
