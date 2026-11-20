<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El chinchulín se cargó en "Varios" y en la separación quedaba escondido
     * dentro de esa línea. Pasa a su propio rubro, igual que vacío, chorizo y
     * bondiola. También reetiqueta lo vendido que todavía no se repuso.
     */
    public function up(): void
    {
        $ids = DB::table('insumos')->where('nombre', 'Chinchulín')->pluck('id');

        DB::table('insumos')->whereIn('id', $ids)->where('grupo_separacion', 'varios')
            ->update(['grupo_separacion' => 'chinchulin']);

        DB::table('venta_insumos')->whereIn('insumo_id', $ids)->where('grupo_separacion', 'varios')
            ->whereNull('reposicion_id')
            ->update(['grupo_separacion' => 'chinchulin']);
    }

    public function down(): void
    {
        DB::table('insumos')->where('grupo_separacion', 'chinchulin')->update(['grupo_separacion' => 'varios']);
        DB::table('venta_insumos')->where('grupo_separacion', 'chinchulin')->update(['grupo_separacion' => 'varios']);
    }
};
