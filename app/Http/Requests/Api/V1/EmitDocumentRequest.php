<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Emission\DocumentEmissionService;
use App\Services\Xml\InformacionReferencia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Solicitud de emisión de un comprobante electrónico. El emisor sale de la
 * empresa autenticada; el cliente envía el tipo, el ambiente y los datos del
 * documento (receptor, detalles, medios de pago, totales, referencias…).
 */
class EmitDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', Rule::in(DocumentEmissionService::supportedTypes())],
            'environment' => ['sometimes', Rule::in(['stag', 'prod'])],

            // Datos del documento (mismas claves que el generador v4.4).
            'fecha_emision' => ['sometimes', 'string'],
            // REP: solo 09/11 (nota 5); fuera del REP no se aceptan.
            'condicion_venta' => ['required_unless:tipo,MR', 'string', $this->input('tipo') === 'REP'
                ? Rule::in(InformacionReferencia::REP_CONDICIONES_VENTA)
                : Rule::notIn(InformacionReferencia::REP_CONDICIONES_VENTA)],
            'medios_pago' => ['sometimes'],
            'detalles' => ['required_unless:tipo,MR'],
            'total_ventas' => ['required_unless:tipo,MR'],
            'total_ventas_neta' => ['required_unless:tipo,MR'],
            'total_comprobante' => ['required_unless:tipo,MR'],

            // Receptor (opcional en TE / omitible).
            'receptor_nombre' => ['sometimes', 'string'],
            'receptor_tipo_identif' => ['sometimes', 'string'],
            'receptor_num_identif' => ['sometimes', 'string'],
            'receptor_email' => ['sometimes', 'email'],

            // InformacionReferencia (notas 9 y 10 de los Anexos 4.4). Si viene
            // en `params`, prepareForValidation() la sube acá.
            'informacion_referencia' => ['required_if:tipo,NC,ND,REP', 'array', 'max:'.InformacionReferencia::MAXIMO],
            'informacion_referencia.*' => ['array'],
            'informacion_referencia.*.tipoDoc' => ['required', 'string', Rule::in(InformacionReferencia::TIPOS_DOC)],
            'informacion_referencia.*.tipoDocOtro' => ['required_if:informacion_referencia.*.tipoDoc,99', 'nullable', 'string', 'min:5', 'max:100'],
            'informacion_referencia.*.numero' => ['required_unless:informacion_referencia.*.tipoDoc,'.InformacionReferencia::TIPO_MES_VENCIDO, 'nullable', 'string', 'max:50'],
            'informacion_referencia.*.fechaEmision' => ['required', 'date'],
            'informacion_referencia.*.codigo' => ['required_unless:informacion_referencia.*.tipoDoc,'.InformacionReferencia::TIPO_MES_VENCIDO, 'nullable', 'string', Rule::in(InformacionReferencia::CODIGOS)],
            'informacion_referencia.*.codigoOtro' => ['required_if:informacion_referencia.*.codigo,99', 'nullable', 'string', 'min:5', 'max:100'],
            'informacion_referencia.*.razon' => ['required_unless:informacion_referencia.*.tipoDoc,'.InformacionReferencia::TIPO_MES_VENCIDO, 'nullable', 'string', 'min:3', 'max:180'],

            // Escape hatch: parámetros adicionales del generador legacy.
            'params' => ['sometimes', 'array'],
        ];
    }

    /**
     * La referencia puede llegar como JSON (string) o dentro de `params`:
     * se deja como array en `informacion_referencia` para validarla igual
     * venga por donde venga.
     */
    protected function prepareForValidation(): void
    {
        $params = $this->input('params');
        $referencia = $this->input('informacion_referencia');

        if ($referencia === null && is_array($params) && array_key_exists('informacion_referencia', $params)) {
            $referencia = $params['informacion_referencia'];
            unset($params['informacion_referencia']);
            $this->merge(['params' => $params]);
        }

        if (is_string($referencia)) {
            $decoded = json_decode($referencia, true);
            $referencia = is_array($decoded) ? $decoded : $referencia;
        }

        if ($referencia !== null) {
            $this->merge(['informacion_referencia' => $referencia]);
        }
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [$this->validateReferenciasCruzadas(...)];
    }

    /**
     * Reglas que dependen del tipo de comprobante o de otro campo de la
     * misma referencia (anexo, págs. 57-58 y notas al pie 35-36).
     */
    private function validateReferenciasCruzadas(Validator $validator): void
    {
        $tipo = $this->input('tipo');
        $referencias = $this->input('informacion_referencia');

        if (! is_array($referencias)) {
            return;
        }

        foreach ($referencias as $i => $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $tipoDoc = $ref['tipoDoc'] ?? null;
            $codigo = $ref['codigo'] ?? null;
            $numero = $ref['numero'] ?? null;

            if ($tipo === 'REP' && ! in_array($tipoDoc, InformacionReferencia::REP_TIPOS_DOC, true)) {
                $validator->errors()->add("informacion_referencia.{$i}.tipoDoc", 'En el Recibo Electrónico de Pago el tipo de documento de referencia es 01, 04, 07, 08, 10 o 20.');
            }

            if ($tipo !== 'REP' && in_array($codigo, InformacionReferencia::CODIGOS_SOLO_REP, true)) {
                $validator->errors()->add("informacion_referencia.{$i}.codigo", 'El código 17 «Pago a comprobante electrónico» solo se usa en el Recibo Electrónico de Pago.');
            }

            if ($tipoDoc === InformacionReferencia::TIPO_SOLO_NOTAS && ! in_array($tipo, ['NC', 'ND'], true)) {
                $validator->errors()->add("informacion_referencia.{$i}.tipoDoc", 'El tipo 09 «Devolución mercadería» solo se usa en notas de crédito y débito.');
            }

            if ($tipoDoc === InformacionReferencia::TIPO_SOLO_FEC && $tipo !== 'FEC') {
                $validator->errors()->add("informacion_referencia.{$i}.tipoDoc", 'El tipo 16 «Comprobante de Proveedor No Domiciliado» solo se usa en la factura electrónica de compra.');
            }

            if (in_array($tipoDoc, InformacionReferencia::TIPOS_CON_CLAVE, true)
                && is_string($numero) && ! preg_match('/^[A-Za-z0-9]{50}$/', $numero)) {
                $validator->errors()->add("informacion_referencia.{$i}.numero", 'Para este tipo de documento el número es la clave de 50 caracteres del comprobante.');
            }
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'informacion_referencia.required_if' => 'Las notas de crédito y débito y el Recibo Electrónico de Pago requieren la información de referencia.',
            'condicion_venta.in' => 'El Recibo Electrónico de Pago solo admite la condición de venta 09 u 11.',
            'condicion_venta.not_in' => 'Las condiciones de venta 09 y 11 son exclusivas del Recibo Electrónico de Pago.',
            'informacion_referencia.max' => 'Se permiten como máximo 10 referencias.',
            'informacion_referencia.*.tipoDoc.required' => 'Indica el tipo de documento de referencia (tipoDoc).',
            'informacion_referencia.*.tipoDoc.in' => 'El tipo de documento de referencia no está en la nota 10 de los Anexos 4.4.',
            'informacion_referencia.*.tipoDocOtro.required_if' => 'Con tipoDoc 99 describe el documento en tipoDocOtro.',
            'informacion_referencia.*.numero.required_unless' => 'Indica el número o la clave del documento de referencia.',
            'informacion_referencia.*.fechaEmision.required' => 'Indica la fecha de emisión del documento de referencia.',
            'informacion_referencia.*.fechaEmision.date' => 'La fecha de emisión de la referencia debe ser una fecha ISO-8601.',
            'informacion_referencia.*.codigo.required_unless' => 'Indica el código de referencia.',
            'informacion_referencia.*.codigo.in' => 'El código de referencia no está en la nota 9 de los Anexos 4.4.',
            'informacion_referencia.*.codigoOtro.required_if' => 'Con código 99 describe la referencia en codigoOtro.',
            'informacion_referencia.*.razon.required_unless' => 'Indica la razón de la referencia.',
            'informacion_referencia.*.razon.max' => 'La razón de la referencia admite como máximo 180 caracteres.',
        ];
    }

    /**
     * Reúne los parámetros del documento para el generador (todo menos los
     * campos de control), fusionando el escape hatch `params`.
     *
     * @return array<string, mixed>
     */
    public function documentParams(): array
    {
        $data = $this->except(['tipo', 'environment', 'params']);
        $data = $this->flattenJson($data);

        return array_merge($data, $this->input('params', []));
    }

    /**
     * detalles/medios_pago/otrosCargos pueden llegar como arrays JSON; el
     * generador legacy espera strings JSON, así que se recodifican.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flattenJson(array $data): array
    {
        foreach (['detalles', 'medios_pago', 'otrosCargos', 'informacion_referencia', 'otros'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = json_encode($data[$key]);
            }
        }

        return $data;
    }
}
