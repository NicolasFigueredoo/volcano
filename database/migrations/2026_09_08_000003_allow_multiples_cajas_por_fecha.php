<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Se permite abrir y cerrar varias cajas dentro de la misma fecha operativa,
     * por lo que la fecha deja de ser única y pasa a ser un índice común.
     */
    public function up(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropUnique('cajas_fecha_operativa_unique');
            $table->index('fecha_operativa');
        });
    }

    public function down(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropIndex(['fecha_operativa']);
            $table->unique('fecha_operativa');
        });
    }
};
