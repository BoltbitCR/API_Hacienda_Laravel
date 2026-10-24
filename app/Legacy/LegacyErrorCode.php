<?php

namespace App\Legacy;

/**
 * Códigos de error del framework legacy (legacy/api/modules/users/module.php
 * y legacy/api/core/boot.php). Son strings numéricos: los handlers los
 * devuelven tal cual y tools_reply() los mapea a HTTP + texto en español.
 */
final class LegacyErrorCode
{
    public const USERS_NO_VALID = '-300';

    public const USERS_WRONG_LOGIN_INFO = '-301';

    public const USERS_NO_VALID_SESSION = '-302';

    public const USERS_ACCESS_DENIED = '-303';

    public const USERS_EXISTS = '-304';

    /** ERROR_DB_NO_RESULTS_FOUND de legacy/api/modules/db: query sin filas afectadas. */
    public const DB_NO_RESULTS_FOUND = '-200';

    /** ERROR_DB_ERROR de legacy/api/modules/db: la BD rechazó la query. */
    public const DB_ERROR = '-201';

    /** ERROR_BAD_REQUEST del legacy (int -1): action inexistente en el módulo. */
    public const BAD_REQUEST = -1;

    /**
     * Mapeo exacto de tools_reply(): código => [HTTP status, texto].
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function map(): array
    {
        return [
            self::USERS_NO_VALID => [400, 'Usuario no válido'],
            self::USERS_WRONG_LOGIN_INFO => [401, 'Información de acceso incorrecta'],
            self::USERS_NO_VALID_SESSION => [440, 'Sesión no válida o expirada'],
            self::USERS_ACCESS_DENIED => [403, 'Acceso denegado'],
            self::USERS_EXISTS => [409, 'El usuario ya existe'],
        ];
    }
}
