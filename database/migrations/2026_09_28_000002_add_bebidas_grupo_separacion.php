<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pasa las bebidas al rubro propio "bebidas": las que se llaman como las
     * de siempre y cualquier insumo usado en productos de la categoría
     * Bebidas. También reetiqueta lo ya vendido en venta_insumos.
     */
    public function up(): void
    {
        $ids = DB::table('insumos')
            ->whereIn('nombre', ['Coca Cola', 'Sprite'])
            ->pluck('id')
            ->merge(
                DB::table('recetas')
                    ->join('variantes', 'variantes.id', '=', 'recetas.variante_id')
                    ->join('productos', 'productos.id', '=', 'variantes.producto_id')
                    ->join('categorias', 'categorias.id', '=', 'productos.categoria_id')
                    ->whereRaw('LOWER(categorias.nombre) LIKE ?', ['%bebida%'])
                    ->pluck('recetas.insumo_id')
            )
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('insumos')->whereIn('id', $ids)->update(['grupo_separacion' => 'bebidas']);
        DB::table('venta_insumos')->whereIn('insumo_id', $ids)->update(['grupo_separacion' => 'bebidas']);
    }

    public function down(): void
    {
        DB::table('insumos')->where('grupo_separacion', 'bebidas')->update(['grupo_separacion' => 'varios']);
        DB::table('venta_insumos')->where('grupo_separacion', 'bebidas')->update(['grupo_separacion' => 'varios']);
    }
};
