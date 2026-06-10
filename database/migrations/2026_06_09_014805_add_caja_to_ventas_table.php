<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('caja_id')->nullable()->after('id')->constrained('cajas')->nullOnDelete();
            $table->date('fecha_operativa')->nullable()->after('caja_id');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caja_id');
            $table->dropColumn('fecha_operativa');
        });
    }
};