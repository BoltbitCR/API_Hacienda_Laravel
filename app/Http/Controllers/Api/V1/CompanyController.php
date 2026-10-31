<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Support\ActingCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Datos de la empresa del token: consulta y actualización de la información
 * del emisor (ex EAV `<id>_master_config_companny`).
 */
class CompanyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $company = ActingCompany::for($request->user());
        abort_if($company === null, 403, 'Sin empresa asociada.');

        return response()->json($company);
    }

    public function update(Request $request): JsonResponse
    {
        $company = ActingCompany::for($request->user());
        abort_if($company === null, 403, 'Sin empresa asociada.');

        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'nombre_comercial' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email'],
            'tel_cod_pais' => ['sometimes', 'string', 'max:5'],
            'tel_numero' => ['sometimes', 'string', 'max:20'],
            'fax_cod_pais' => ['sometimes', 'string', 'max:5'],
            'fax_numero' => ['sometimes', 'string', 'max:20'],
            'tipo_cedula' => ['sometimes', 'string', 'max:10'],
            'cedula' => ['sometimes', 'string', 'max:20'],
            // Cédula del proveedor del sistema de facturación (ProveedorSistemas);
            // vacía = desarrollo propio (se usa la del emisor).
            'proveedor_sistemas' => ['sometimes', 'nullable', 'string', 'max:20'],
            'id_provincia' => ['sometimes', 'string', 'max:10'],
            'id_canton' => ['sometimes', 'string', 'max:10'],
            'id_distrito' => ['sometimes', 'string', 'max:10'],
            'id_barrio' => ['sometimes', 'string', 'max:10'],
            'sennas' => ['sometimes', 'string', 'max:255'],
            'tipo_cambio' => ['sometimes', 'string', 'max:20'],
            'env' => ['sometimes', 'in:api-stag,api-prod'],
            'situacion' => ['sometimes', 'in:normal,contingencia,sininternet'],
        ]);

        $company->update($data);

        return response()->json($company);
    }
}
