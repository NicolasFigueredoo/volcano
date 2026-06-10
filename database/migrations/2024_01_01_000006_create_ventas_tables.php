<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('mesa')->nullable();
            $table->enum('metodo_pago', ['efectivo', 'tarjeta', 'transferencia', 'mercado_pago']);
            $table->decimal('subtotal', 10, 2);
            $table->decimal('descuento', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->text('notas')->nullable();
            $table->timestamp('cerrada_at')->nullable();
            $table->timestamps();
        });

        Schema::create('detalle_ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('variante_id')->constrained('variantes')->restrictOnDelete();
            $table->string('nombre_snapshot'); // nombre al momento de la venta
            $table->decimal('precio_snapshot', 10, 2); // precio al momento de la venta
            $table->decimal('costo_snapshot', 10, 2)->default(0);
            $table->integer('cantidad');
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_ventas');
        Schema::dropIfExists('ventas');
    }
};
