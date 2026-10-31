<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clave' => $this->clave,
            'consecutivo' => $this->consecutivo,
            'tipo_documento' => $this->tipo_documento,
            'estado' => $this->estado,
            // HTTP de la recepción de Hacienda (202 recibido, 400 rechazado,
            // 0 sin conexión); null en comprobantes anteriores a la columna.
            'recepcion_status' => $this->recepcion_status,
            'mensaje_error' => $this->mensaje_error,
            'environment' => $this->env,
            'fecha_creacion' => optional($this->fecha_creacion)->toIso8601String(),
        ];
    }
}
