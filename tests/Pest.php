<?php

use App\Models\Company;
use App\Models\LegacySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Parity', 'Unit');

/**
 * Master user legacy con su empresa (company_id == user id) y una sesión
 * viva, para llamar a /api.php?w=facturador como el dueño.
 *
 * @return array{id: int, params: array<string, string>}
 */
function facturadorMaster(string $userName): array
{
    $user = User::create([
        'full_name' => $userName, 'user_name' => $userName, 'email' => "$userName@example.com",
        'about' => '{}', 'country' => 'crc', 'status' => '1', 'legacy_timestamp' => time(),
        'last_access' => time(), 'password' => password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]),
        'avatar' => '0', 'settings' => 'NULL',
    ]);
    Company::create(['id' => $user->id, 'owner_user_id' => $user->id]);
    LegacySession::create([
        'user_id' => $user->id, 'session_key' => "sk-$userName", 'ip' => '127.0.0.1', 'last_access' => time(),
    ]);

    return ['id' => $user->id, 'params' => ['w' => 'facturador', 'iam' => $userName, 'sessionKey' => "sk-$userName"]];
}
