<?php

use App\Models\GastoFijo;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Burger y parrilla son dos locales distintos: cada uno paga $200.000 de
     * alquiler por mes ($50.000 por semana). Deja de ser compartido.
     */
    public function up(): void
    {
        if (GastoFijo::where('nombre', 'Alquiler parrilla')->exists()) {
            return;
        }

        $alquiler = GastoFijo::where('nombre', 'Alquiler')->first();

        $alquiler?->update(['nombre' => 'Alquiler burger', 'negocio' => 'burger']);

        GastoFijo::create([
            'nombre' => 'Alquiler parrilla',
            'negocio' => 'parrilla',
            'periodicidad' => 'mensual',
            'monto_mensual' => 200000,
            'dias_apertura_mes' => $alquiler?->dias_apertura_mes ?? 16,
            'activo' => true,
        ]);
    }

    public function down(): void
    {
        //
    }
};
