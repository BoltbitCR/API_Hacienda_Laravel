<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Xml\TipoIdentificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo geográfico del Ministerio de Hacienda (provincias, cantones,
 * distritos, barrios) y catálogos auxiliares. Endpoints públicos de lectura.
 */
class GeoController extends Controller
{
    public function provinces(): JsonResponse
    {
        return response()->json(
            DB::table('codificacion_mh')
                ->select('id_provincia', 'nombre_provincia')
                ->distinct()->orderByRaw('MIN(id)')->groupBy('id_provincia', 'nombre_provincia')->get()
        );
    }

    public function cantons(string $province): JsonResponse
    {
        return response()->json($this->distinct(['id_canton', 'nombre_canton'], ['id_provincia' => $province]));
    }

    public function districts(string $province, string $canton): JsonResponse
    {
        return response()->json($this->distinct(
            ['id_distrito', 'nombre_distrito'],
            ['id_provincia' => $province, 'id_canton' => $canton]
        ));
    }

    public function neighborhoods(string $province, string $canton, string $district): JsonResponse
    {
        return response()->json($this->distinct(
            ['id_barrio', 'nombre_barrio'],
            ['id_provincia' => $province, 'id_canton' => $canton, 'id_distrito' => $district]
        ));
    }

    public function taxTypes(): JsonResponse
    {
        return response()->json(DB::table('tipo_impuestos')->get());
    }

    public function measureUnits(): JsonResponse
    {
        return response()->json(DB::table('unidad_medida')->orderBy('id')->get());
    }

    public function idTypes(): JsonResponse
    {
        return response()->json(TipoIdentificacion::catalogo());
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $where
     */
    private function distinct(array $columns, array $where): array
    {
        $q = DB::table('codificacion_mh');
        foreach ($where as $col => $val) {
            $q->where($col, $val);
        }

        return $q->groupBy($columns)->orderByRaw('MIN(id)')->get($columns)->all();
    }
}
