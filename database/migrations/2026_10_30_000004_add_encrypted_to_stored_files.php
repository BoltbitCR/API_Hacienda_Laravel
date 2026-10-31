<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los certificados .p12 nuevos se guardan cifrados en reposo con la APP_KEY;
 * los archivos anteriores siguen en claro (encrypted = false) y se leen igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stored_files', function (Blueprint $table) {
            $table->boolean('encrypted')->default(false)->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('stored_files', function (Blueprint $table) {
            $table->dropColumn('encrypted');
        });
    }
};
