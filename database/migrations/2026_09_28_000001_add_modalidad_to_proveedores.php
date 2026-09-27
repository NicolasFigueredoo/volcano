<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            // cuenta_corriente: fiado, se le paga a medida que se vende (carnicero).
            // contado: se paga al comprar y se junta plata para la próxima compra (pan).
            $table->string('modalidad')->default('cuenta_corriente')->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn('modalidad');
        });
    }
};
