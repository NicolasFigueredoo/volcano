<?php

use App\Models\Producto;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Sanguche de chinchulín baja de $6.000 a $4.000. */
    public function up(): void
    {
        Producto::where('nombre', 'Chinchulín')->first()?->variantes()->update(['precio_venta' => 4000]);
    }

    public function down(): void
    {
        //
    }
};
