<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('telefono')->nullable();
            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('insumos', function (Blueprint $table) {
            $table->foreignId('proveedor_id')->nullable()->after('grupo_separacion')->constrained('proveedores')->nullOnDelete();

            // Unidad en la que se compra (kg, bloque) y cuántas unidades del
            // insumo trae cada una (ej: 1 kg = 10 medallones de 100 g).
            $table->string('unidad_compra')->nullable()->after('proveedor_id');
            $table->decimal('equivalencia_compra', 10, 3)->nullable()->after('unidad_compra');
        });
    }

    public function down(): void
    {
        Schema::table('insumos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proveedor_id');
            $table->dropColumn(['unidad_compra', 'equivalencia_compra']);
        });

        Schema::dropIfExists('proveedores');
    }
};
