<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $table = 'documents';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Clave con la que se consulta este documento en GET /recepcion/{clave}.
     *
     * El mensaje receptor guarda la clave del comprobante AJENO que confirma, y con esa clave
     * sola Hacienda responde el estado de la factura del proveedor. Al mensaje lo identifica
     * como `{clave}-{NumeroConsecutivoReceptor}` (consecutivo de 20 dígitos, el mismo
     * `consecutivoReceptor` de la recepción).
     */
    public function claveConsulta(): string
    {
        if ($this->tipo_documento === 'MR') {
            return $this->clave.'-'.str_pad((string) $this->consecutivo, 20, '0', STR_PAD_LEFT);
        }

        return (string) $this->clave;
    }
}
