<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reposiciones', function (Blueprint $table) {
            $table->id();

            $table->string('grupo');
            $table->decimal('monto_acumulado', 12, 2)->default(0);
            $table->decimal('monto_real', 12, 2)->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('observacion')->nullable();

            $table->timestamps();

            $table->index('grupo');
        });

        Schema::create('separacion_movimientos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->string('grupo');
            $table->decimal('monto', 12, 2)->default(0);

            $table->foreignId('reposicion_id')->nullable()->constrained('reposiciones')->nullOnDelete();

            $table->timestamps();

            $table->unique(['venta_id', 'grupo']);
            $table->index(['grupo', 'reposicion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('separacion_movimientos');
        Schema::dropIfExists('reposiciones');
    }
};
