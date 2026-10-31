<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resultado de la recepción de Hacienda por comprobante: el HTTP que
 * respondió (202/400/0...) y la causa legible del rechazo o del error.
 * Antes todo quedaba como `enviado` sin importar la respuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedSmallInteger('recepcion_status')->nullable()->after('estado');
            $table->text('mensaje_error')->nullable()->after('recepcion_status');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['recepcion_status', 'mensaje_error']);
        });
    }
};
