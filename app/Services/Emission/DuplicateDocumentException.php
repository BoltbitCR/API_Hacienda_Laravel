<?php

namespace App\Services\Emission;

/**
 * Ya existe un comprobante de la empresa con la misma clave (o la misma
 * Idempotency-Key) que llegó a Hacienda: no se vuelve a emitir.
 */
class DuplicateDocumentException extends EmissionException
{
    public function __construct(string $message, public readonly ?int $documentId = null)
    {
        parent::__construct($message);
    }
}
