<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada insumo vendido guarda de qué negocio es (el de la línea vendida),
     * así al cerrar la caja se separa la plata de la burger y la de la
     * parrilla por separado.
     *
     * Lo ya vendido: va a parrilla lo de los insumos que solo usan recetas de
     * la parrilla (vacío, bondiola, chorizo, pan de sanguche); el resto queda
     * en burger. No se regenera nada para no tocar los costos históricos.
     */
    public function up(): void
    {
        Schema::table('venta_insumos', function (Blueprint $table) {
            $table->string('negocio', 20)->default('burger')->after('insumo_id');
        });

        $insumosDe = fn (string $operador) => DB::table('recetas')
            ->join('variantes', 'variantes.id', '=', 'recetas.variante_id')
            ->join('productos', 'productos.id', '=', 'variantes.producto_id')
            ->join('categorias', 'categorias.id', '=', 'productos.categoria_id')
            ->where('categorias.negocio', $operador, 'parrilla')
            ->distinct()
            ->pluck('recetas.insumo_id');

        $soloParrilla = $insumosDe('=')->diff($insumosDe('!='));

        if ($soloParrilla->isNotEmpty()) {
            DB::table('venta_insumos')->whereIn('insumo_id', $soloParrilla)->update(['negocio' => 'parrilla']);
        }
    }

    public function down(): void
    {
        Schema::table('venta_insumos', function (Blueprint $table) {
            $table->dropColumn('negocio');
        });
    }
};
