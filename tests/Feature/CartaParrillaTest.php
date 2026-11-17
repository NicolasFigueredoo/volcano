<?php

use App\Models\Categoria;
use App\Models\GastoFijo;
use App\Models\Variante;

it('la migración carga la parrilla con los costos iniciales', function () {
    $parrilla = Categoria::where('negocio', 'parrilla')->firstOrFail();

    $costo = fn (string $producto) => (float) Variante::whereHas('producto', fn ($q) => $q
        ->where('categoria_id', $parrilla->id)->where('nombre', $producto))->value('costo_calculado');

    expect(round($costo('Vacío')))->toEqual(4325.0)
        ->and(round($costo('Bondiola')))->toEqual(2665.0)
        ->and(round($costo('Choripán')))->toEqual(1297.0);

    $jornada = GastoFijo::where('negocio', 'parrilla')->where('periodicidad', 'jornada')->get();

    expect($jornada->sum('costo_diario'))->toEqual(38000.0);
});
