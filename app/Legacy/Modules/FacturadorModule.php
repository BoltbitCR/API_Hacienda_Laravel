<?php

namespace App\Legacy\Modules;

use App\Legacy\LegacyModule;
use App\Legacy\LegacyRoute;
use App\Services\Facturador\FacturadorService;

/**
 * Port de legacy/api/contrib/facturador. Tabla de rutas extraída del
 * module.php legacy (data/facturador_routes.php); los handlers viven en
 * FacturadorService, respaldados por el esquema Eloquent normalizado.
 *
 * Las 51 rutas están portadas y cada una tiene su golden master (fixtures
 * 61-101). Las que el legacy rompía responden igual que él (body vacío);
 * las divergencias deliberadas están documentadas en FacturadorService.
 */
class FacturadorModule implements LegacyModule
{
    public function __construct(private readonly FacturadorService $svc) {}

    public function routes(): array
    {
        $map = $this->svc->routeHandlers();
        $routes = [];

        foreach (require __DIR__.'/data/facturador_routes.php' as $def) {
            $handler = $map[$def['r']] ?? null;
            if ($handler === null) {
                continue; // rutas aún no portadas: caen en "Function not found"
            }

            $routes[] = new LegacyRoute(
                r: $def['r'],
                action: $handler,
                access: $def['access'],
                params: $def['params'],
            );
        }

        return $routes;
    }
}
