<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * La carne de la parrilla se compra al mismo proveedor que la de las
     * burgers ("carne"). Se asigna solo a los insumos que todavía no tienen
     * proveedor, así no pisa lo que se haya cambiado a mano desde la pantalla.
     */
    public function up(): void
    {
        $proveedorId = DB::table('proveedores')
            ->whereRaw('LOWER(TRIM(nombre)) = ?', ['carne'])
            ->value('id');

        if (! $proveedorId) {
            return;
        }

        DB::table('insumos')
            ->whereIn('nombre', ['Vacío (parrilla)', 'Bondiola', 'Chorizo'])
            ->whereNull('proveedor_id')
            ->update(['proveedor_id' => $proveedorId]);
    }

    public function down(): void
    {
        //
    }
};
