<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tandas de compra de la parrilla: cada compra de carne (kg y total
     * pagado) se va pagando con los sanguches que salen de ella. Al cerrarla
     * se sabe cuánto rindió de verdad y cuánto falta o sobra en el sobre.
     */
    public function up(): void
    {
        Schema::create('tandas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insumo_id')->constrained('insumos');
            $table->string('negocio')->default('parrilla');
            // Lo comprado (kg o unidades) y lo pagado.
            $table->decimal('cantidad', 10, 3);
            $table->decimal('total', 12, 2);
            // Sanguches que se esperan sacar y lo que aparta cada unidad de
            // receta (kg de receta) para que entre todos sumen el total.
            $table->unsignedInteger('porciones_estimadas');
            $table->decimal('costo_unitario', 12, 2);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('observacion', 500)->nullable();
            // Cierre: cuándo se terminó y en qué caja va el ajuste del sobre.
            $table->timestamp('cerrada_at')->nullable();
            $table->foreignId('caja_cierre_id')->nullable()->constrained('cajas')->nullOnDelete();
            $table->decimal('apartado', 12, 2)->nullable();
            $table->decimal('cantidad_vendida', 10, 3)->nullable();
            $table->timestamps();

            $table->index(['insumo_id', 'cerrada_at']);
        });

        Schema::table('venta_insumos', function (Blueprint $table) {
            $table->foreignId('tanda_id')->nullable()->after('reposicion_id')->constrained('tandas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('venta_insumos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tanda_id');
        });

        Schema::dropIfExists('tandas');
    }
};
