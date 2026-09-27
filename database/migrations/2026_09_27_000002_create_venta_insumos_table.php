<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_insumos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('caja_id')->nullable()->constrained('cajas')->nullOnDelete();
            $table->foreignId('insumo_id')->constrained('insumos')->cascadeOnDelete();
            $table->string('grupo_separacion');

            $table->decimal('cantidad', 10, 3);
            $table->decimal('costo_unitario', 12, 2);
            $table->decimal('subtotal', 12, 2);

            // Rubros sin proveedor: se estampa la reposición que los liquidó.
            $table->foreignId('reposicion_id')->nullable()->constrained('reposiciones')->nullOnDelete();

            $table->timestamps();

            $table->index('caja_id');
            $table->index(['insumo_id', 'caja_id']);
            $table->index(['grupo_separacion', 'reposicion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_insumos');
    }
};
