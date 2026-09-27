<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedor_movimientos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $table->string('tipo'); // saldo_inicial | entrega | separacion | pago | ajuste
            $table->date('fecha');

            // Siempre positivo, salvo en 'ajuste' que lleva signo.
            $table->decimal('monto', 12, 2);

            // Solo saldo_inicial: parte de la deuda que ya era mercadería vendida.
            $table->decimal('monto_vendido', 12, 2)->nullable();

            $table->foreignId('insumo_id')->nullable()->constrained('insumos')->nullOnDelete();
            $table->decimal('cantidad', 10, 3)->nullable();
            $table->string('unidad')->nullable();
            // Cantidad convertida a la unidad del insumo (ej: 5 kg -> 50 medallones).
            $table->decimal('cantidad_insumo', 10, 3)->nullable();

            $table->foreignId('caja_id')->nullable()->constrained('cajas')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacion')->nullable();

            $table->timestamps();

            $table->index(['proveedor_id', 'tipo']);
        });

        Schema::create('caja_separaciones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('caja_id')->unique()->constrained('cajas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total', 12, 2)->default(0);
            $table->json('detalle')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_separaciones');
        Schema::dropIfExists('proveedor_movimientos');
    }
};
