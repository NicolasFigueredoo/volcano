<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar estado al pedido y número de orden visible
        Schema::table('ventas', function (Blueprint $table) {
            $table->enum('estado', ['pendiente', 'preparacion', 'pagado', 'entregado'])
                  ->default('pendiente')
                  ->after('mesa');
            $table->unsignedSmallInteger('numero_orden')->nullable()->after('estado');
        });

        // Pagos mixtos: una venta puede tener efectivo + transferencia
        Schema::create('pagos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->enum('metodo', ['efectivo', 'transferencia']);
            $table->decimal('monto', 10, 2);
            $table->timestamps();
        });

        // Gastos fijos configurables
        Schema::create('gastos_fijos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');             // 'alquiler', 'empleado', 'garrafa_luz'
            $table->decimal('monto_mensual', 10, 2);
            $table->unsignedTinyInteger('dias_apertura_mes')->default(16); // jue-dom x 4 semanas
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Historial de cierres de caja por día
        Schema::create('cierres_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->date('fecha');
            $table->decimal('total_ventas', 10, 2)->default(0);
            $table->decimal('total_efectivo', 10, 2)->default(0);
            $table->decimal('total_transferencia', 10, 2)->default(0);
            $table->decimal('costo_insumos', 10, 2)->default(0);
            $table->decimal('ganancia_bruta', 10, 2)->default(0);
            $table->decimal('gastos_fijos', 10, 2)->default(0);
            $table->decimal('ganancia_neta', 10, 2)->default(0);
            $table->integer('cantidad_ventas')->default(0);
            $table->json('resumen_json')->nullable(); // snapshot completo
            $table->timestamps();

            $table->unique('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cierres_caja');
        Schema::dropIfExists('gastos_fijos');
        Schema::dropIfExists('pagos_venta');
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn(['estado', 'numero_orden']);
        });
    }
};
