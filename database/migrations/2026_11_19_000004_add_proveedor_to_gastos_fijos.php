<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un gasto por jornada (carbón, criolla y chimi) puede separarse en un
     * proveedor: cada caja en la que vendió su negocio suma el monto de la
     * jornada al sobre de ese negocio, en la línea de ese proveedor.
     */
    public function up(): void
    {
        Schema::table('gastos_fijos', function (Blueprint $table) {
            $table->foreignId('proveedor_id')->nullable()->after('negocio')->constrained('proveedores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gastos_fijos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proveedor_id');
        });
    }
};
