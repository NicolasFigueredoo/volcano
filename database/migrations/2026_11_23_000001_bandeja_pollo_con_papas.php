<?php

use App\Models\Insumo;
use App\Models\Producto;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * En producción corrió la primera versión de la bandeja ("Pollo con
     * arroz", variante "Bandeja"). Se pasa a "Bandeja de pollo" con dos
     * opciones: "Con arroz" (la que ya existe, mismo precio) y "Con papas"
     * ($500 más, porque las papas cuestan ~$500 más que el arroz).
     */
    public function up(): void
    {
        $producto = Producto::where('nombre', 'Pollo con arroz')->first();

        if (! $producto || Producto::where('nombre', 'Bandeja de pollo')->exists()) {
            return;
        }

        $producto->update(['nombre' => 'Bandeja de pollo']);

        $arroz = $producto->variantes()->with('recetas.insumo')->orderBy('orden')->first();

        if (! $arroz) {
            return;
        }

        if ($arroz->nombre === 'Bandeja') {
            $arroz->update(['nombre' => 'Con arroz']);
        }

        if ($producto->variantes()->where('nombre', 'Con papas')->exists()) {
            return;
        }

        $papas = $producto->variantes()->create([
            'nombre' => 'Con papas',
            'precio_venta' => (float) $arroz->precio_venta + 500,
            'costo_calculado' => 0,
            'activo' => true,
            'orden' => (int) $arroz->orden + 1,
        ]);

        // Misma receta sin el arroz, más lo que lleva el cono de papas.
        foreach ($arroz->recetas as $receta) {
            if ($receta->insumo?->nombre !== 'Porción de arroz') {
                $papas->recetas()->create(['insumo_id' => $receta->insumo_id, 'cantidad' => $receta->cantidad]);
            }
        }

        foreach (['Papas', 'Aceite freidora', 'Garrafa'] as $nombre) {
            if ($insumo = Insumo::where('nombre', $nombre)->first()) {
                $papas->recetas()->create(['insumo_id' => $insumo->id, 'cantidad' => 1]);
            }
        }

        $papas->recalcularCosto();
    }

    public function down(): void
    {
        //
    }
};
