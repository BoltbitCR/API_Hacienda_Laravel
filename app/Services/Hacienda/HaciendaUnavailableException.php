<?php

namespace App\Services\Hacienda;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Hacienda (IdP o API) no respondió o respondió con un error propio. La
 * petición del cliente es válida: repetirla más tarde puede funcionar, por
 * eso sale como 503 con Retry-After y nunca como 422.
 */
class HaciendaUnavailableException extends ServiceUnavailableHttpException
{
    public const RETRY_AFTER_SECONDS = 60;

    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct(self::RETRY_AFTER_SECONDS, $message, $previous);
    }
}
