<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Detalle de un comprobante: además de los datos del listado, los dos XML
 * que son el respaldo fiscal (el enviado y el MensajeHacienda), para que el
 * cliente los descargue y conserve (Res. 0027 art. 7).
 */
class DocumentDetailResource extends DocumentResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'xml_enviado_base64' => $this->xml_enviado_base64,
            'respuesta_mh_base64' => $this->respuesta_mh_base64,
        ];
    }
}
