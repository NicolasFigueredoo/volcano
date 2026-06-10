<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas', function (Blueprint $table) {
            $table->id();

            $table->date('fecha_operativa')->unique();
            $table->string('estado')->default('abierta'); // abierta, cerrada

            $table->foreignId('abierta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('abierta_at')->nullable();
            $table->timestamp('cerrada_at')->nullable();

            $table->decimal('total_ventas', 12, 2)->default(0);
            $table->decimal('total_efectivo', 12, 2)->default(0);
            $table->decimal('total_transferencia', 12, 2)->default(0);

            $table->decimal('costo_insumos', 12, 2)->default(0);
            $table->decimal('ganancia_bruta', 12, 2)->default(0);
            $table->decimal('gastos_fijos', 12, 2)->default(0);
            $table->decimal('ganancia_neta', 12, 2)->default(0);

            $table->unsignedInteger('cantidad_ventas')->default(0);

            $table->json('resumen_json')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cajas');
    }
};