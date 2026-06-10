<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variante_id')->constrained('variantes')->cascadeOnDelete();
            $table->foreignId('insumo_id')->constrained('insumos')->cascadeOnDelete();
            $table->decimal('cantidad', 10, 3);
            $table->timestamps();

            $table->unique(['variante_id', 'insumo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recetas');
    }
};
