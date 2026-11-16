<?php

use App\Models\Insumo;
use App\Models\Producto;
use App\Models\Variante;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * es_extra: variante que no se muestra en la grilla del POS sino como
     * agregado de una linea (ej. "Patty extra" para armar una cuadruple o
     * quintuple sobre la triple).
     *
     * Para cada burger con Doble y Triple se crea su "Patty extra": un
     * medallon con cheddar a $1500. Precio y receta se pueden ajustar despues
     * desde Admin.
     */
    public function up(): void
    {
        Schema::table('variantes', function (Blueprint $table) {
            $table->boolean('es_extra')->default(false)->after('activo');
        });

        Producto::with('variantes.recetas')->get()->each(function (Producto $producto) {
            $doble = $producto->variantes->firstWhere('nombre', 'Doble');
            $triple = $producto->variantes->firstWhere('nombre', 'Triple');

            if (! $doble || ! $triple || $producto->variantes->contains('es_extra', true)) {
                return;
            }

            $extra = $producto->variantes()->create([
                'nombre' => 'Patty extra',
                'precio_venta' => 1500,
                'costo_calculado' => 0,
                'activo' => true,
                'es_extra' => true,
                'orden' => 99,
            ]);

            foreach (['Carne%', 'Cheddar%'] as $nombre) {
                $insumo = Insumo::where('nombre', 'like', $nombre)->first();

                if ($insumo) {
                    $extra->recetas()->create([
                        'insumo_id' => $insumo->id,
                        'cantidad' => 1,
                    ]);
                }
            }

            $extra->recalcularCosto();
        });
    }

    public function down(): void
    {
        Variante::where('es_extra', true)->each(function (Variante $v) {
            $v->recetas()->delete();
            if (! $v->detalleVentas()->exists()) {
                $v->delete();
            } else {
                $v->update(['activo' => false]);
            }
        });

        Schema::table('variantes', function (Blueprint $table) {
            $table->dropColumn('es_extra');
        });
    }
};
