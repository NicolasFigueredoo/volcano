<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grupos con los que se agrupan los insumos para la separación de plata.
     */
    private array $mapa = [
        'carne' => ['Carne (vacío)'],
        'pan' => ['Pan de hamburguesa'],
        'papas' => ['Papas'],
        'cheddar' => ['Cheddar'],
        'panceta' => ['Panceta'],
        'descartables' => ['Bolsa delivery', 'Cartón papas', 'Aluminio'],
    ];

    public function up(): void
    {
        Schema::table('insumos', function (Blueprint $table) {
            $table->string('grupo_separacion')->default('varios')->after('descuenta_stock');
        });

        foreach ($this->mapa as $grupo => $nombres) {
            DB::table('insumos')
                ->whereIn('nombre', $nombres)
                ->update(['grupo_separacion' => $grupo]);
        }
    }

    public function down(): void
    {
        Schema::table('insumos', function (Blueprint $table) {
            $table->dropColumn('grupo_separacion');
        });
    }
};
