<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cédula del proveedor del sistema de facturación (ProveedorSistemas, Anexos
 * 4.4 pág. 18). Vacía = desarrollo propio: se usa la cédula del emisor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('proveedor_sistemas', 20)->nullable()->after('cedula');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('proveedor_sistemas');
        });
    }
};
