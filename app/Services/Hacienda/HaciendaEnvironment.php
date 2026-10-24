<?php

namespace App\Services\Hacienda;

/**
 * Resuelve endpoints del Ministerio de Hacienda según el client_id que
 * viaja en cada petición legacy (api-stag | api-prod). Los valores viven en
 * config/hacienda.php (antes hardcodeados en send.php / mhToken.php).
 */
class HaciendaEnvironment
{
    public function receptionUrl(string $clientId): ?string
    {
        return match ($clientId) {
            'api-stag' => config('hacienda.endpoints.stag.reception'),
            'api-prod' => config('hacienda.endpoints.prod.reception'),
            default => null,
        };
    }

    public function comprobantesUrl(string $clientId): ?string
    {
        return match ($clientId) {
            'api-stag' => config('hacienda.endpoints.stag.comprobantes'),
            'api-prod' => config('hacienda.endpoints.prod.comprobantes'),
            default => null,
        };
    }

    public function idpUrl(string $clientId): ?string
    {
        return match ($clientId) {
            'api-stag' => config('hacienda.endpoints.stag.idp'),
            'api-prod' => config('hacienda.endpoints.prod.idp'),
            default => null,
        };
    }

    public function verifySsl(): bool
    {
        return (bool) config('hacienda.verify_ssl', true);
    }

    public function timeout(): int
    {
        return (int) config('hacienda.timeout', 60);
    }
}
