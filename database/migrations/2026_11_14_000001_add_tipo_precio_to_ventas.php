<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * tipo_precio: con que precio se cobro el pedido.
     *  - cliente: precio de venta normal
     *  - costo:   se cobra solo el costo (consumo propio / personal)
     *  - regalo:  no se cobra nada (el costo queda como perdida)
     */
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('tipo_precio', 10)->default('cliente')->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('tipo_precio');
        });
    }
};
