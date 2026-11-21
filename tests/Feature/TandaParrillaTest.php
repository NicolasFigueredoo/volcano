<?php

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Insumo;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\Tanda;
use App\Models\Variante;
use App\Models\VentaInsumo;

beforeEach(function () {
    config(['separacion.fecha_corte' => '2000-01-01']);
});

/** Sanguche de "Chinchu" (250 g de receta + pan de $170) a $4.000. */
function escenarioTanda(): array
{
    $carne = Insumo::create([
        'nombre' => 'Chinchu test', 'unidad' => 'kg', 'costo_unitario' => 5000,
        'stock_actual' => 0, 'stock_minimo' => 0, 'descuenta_stock' => true, 'grupo_separacion' => 'chinchulin',
    ]);
    $pan = Insumo::create([
        'nombre' => 'Pan test', 'unidad' => 'unidad', 'costo_unitario' => 170,
        'stock_actual' => 100, 'stock_minimo' => 0, 'descuenta_stock' => true, 'grupo_separacion' => 'pan_sanguche',
    ]);

    $categoria = Categoria::create(['nombre' => 'Parrilla test', 'negocio' => 'parrilla', 'orden' => 9]);
    $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Chinchu', 'activo' => true]);
    $sanguche = Variante::create(['producto_id' => $producto->id, 'nombre' => 'Sanguche', 'precio_venta' => 4000, 'costo_calculado' => 0, 'activo' => true]);

    Receta::create(['variante_id' => $sanguche->id, 'insumo_id' => $carne->id, 'cantidad' => 0.25]);
    Receta::create(['variante_id' => $sanguche->id, 'insumo_id' => $pan->id, 'cantidad' => 1]);
    $sanguche->recalcularCosto();

    $cajero = usuarioConRol('empleado');
    $admin = usuarioConRol('admin');

    Caja::create([
        'fecha_operativa' => Caja::fechaOperativaActual(),
        'estado' => 'abierta',
        'abierta_por' => $cajero->id,
        'abierta_at' => now(),
    ]);

    return compact('carne', 'pan', 'sanguche', 'cajero', 'admin');
}

function venderSanguches(array $ctx, int $cantidad): void
{
    test()->actingAs($ctx['cajero'])->postJson('/api/pos/venta', [
        'items' => [['variante_id' => $ctx['sanguche']->id, 'cantidad' => $cantidad]],
        'pagos' => [['metodo' => 'efectivo', 'monto' => 4000 * $cantidad]],
    ])->assertCreated();
}

it('cada sanguche aparta lo pagado dividido los sanguches esperados', function () {
    $ctx = escenarioTanda();

    test()->actingAs($ctx['admin'])->postJson('/api/tandas', [
        'insumo_id' => $ctx['carne']->id, 'cantidad' => 4, 'total' => 28000, 'porciones_estimadas' => 8,
    ])->assertCreated()->assertJsonPath('abiertas.0.aparta_por_porcion', 3500);

    expect((float) $ctx['carne']->fresh()->stock_actual)->toEqual(4.0)
        ->and((float) $ctx['sanguche']->fresh()->costo_calculado)->toEqual(3670.0);

    venderSanguches($ctx, 6);

    expect((float) VentaInsumo::where('insumo_id', $ctx['carne']->id)->sum('subtotal'))->toEqual(21000.0);

    $dia = test()->actingAs($ctx['cajero'])->getJson('/api/separacion/dia')->assertOk()->json();

    expect($dia['tandas_abiertas'][0])
        ->porciones_vendidas->toBe(6)
        ->apartado->toEqual(21000)
        ->falta->toEqual(7000);
});

it('al terminarse suma lo que falta al sobre y aprende el rendimiento', function () {
    $ctx = escenarioTanda();

    test()->actingAs($ctx['admin'])->postJson('/api/tandas', [
        'insumo_id' => $ctx['carne']->id, 'cantidad' => 4, 'total' => 28000, 'porciones_estimadas' => 8,
    ])->assertCreated();

    venderSanguches($ctx, 6);

    $tanda = Tanda::firstOrFail();
    test()->actingAs($ctx['cajero'])->postJson("/api/tandas/{$tanda->id}/cerrar")->assertOk();

    $lineas = collect(test()->actingAs($ctx['cajero'])->getJson('/api/separacion/dia')->json('lineas'));
    $chinchu = $lineas->firstWhere('grupo', 'chinchulin');

    // 21.000 apartados por las ventas + 7.000 de ajuste = lo que se pagó.
    expect($chinchu['monto'])->toEqual(28000)
        ->and(collect($chinchu['insumos'])->firstWhere('ajuste', true)['monto'])->toEqual(7000);

    $historial = test()->actingAs($ctx['admin'])->getJson('/api/negocio/parrilla')->assertOk()->json('tandas.historial.0');

    // 6 sanguches × $4.000 − $28.000 de carne − 6 panes de $170.
    expect($historial)
        ->ganancia->toEqual(-5020)
        ->rendimiento->toEqual(1.5);

    $opcion = collect(test()->actingAs($ctx['admin'])->getJson('/api/tandas')->json('opciones'))->firstWhere('id', $ctx['carne']->id);
    expect($opcion['rendimiento'])->toEqual(1.5);
});

it('el cajero no puede cargar compras', function () {
    $ctx = escenarioTanda();

    test()->actingAs($ctx['cajero'])->postJson('/api/tandas', [
        'insumo_id' => $ctx['carne']->id, 'cantidad' => 4, 'total' => 28000, 'porciones_estimadas' => 8,
    ])->assertForbidden();
});

it('muestra las cuentas de un solo negocio', function () {
    $ctx = escenarioTanda();
    venderSanguches($ctx, 2);

    test()->actingAs($ctx['admin'])->getJson('/api/negocio/parrilla')
        ->assertOk()
        ->assertJsonPath('totales.ventas', 8000)
        ->assertJsonPath('totales.unidades', 2)
        ->assertJsonPath('productos.0.cantidad', 2);

    test()->actingAs($ctx['admin'])->getJson('/api/negocio/burger')
        ->assertOk()
        ->assertJsonPath('totales.jornadas', 0)
        ->assertJsonPath('tandas', null);
});
