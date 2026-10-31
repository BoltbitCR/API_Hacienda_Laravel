<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency-Key opcional de POST /api/v1/documents: la misma llave en la
 * misma empresa devuelve el comprobante ya emitido en vez de emitir otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('env');
            $table->unique(['company_id', 'idempotency_key'], 'documents_company_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_company_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
