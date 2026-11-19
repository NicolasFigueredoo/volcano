<?php

use App\Models\Producto;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Choripán pasa de $2.500 a $3.500 (19/11/2026). */
    public function up(): void
    {
        Producto::where('nombre', 'Choripán')->first()?->variantes()->update(['precio_venta' => 3500]);
    }

    public function down(): void
    {
        //
    }
};
