<?php

use App\Models\Caja;
use App\Models\CajaSeparacion;
use App\Models\Categoria;
use App\Models\Insumo;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\ProveedorMovimiento;
use App\Models\Receta;
use App\Models\Variante;
use App\Models\VentaInsumo;

beforeEach(function () {
    config(['separacion.fecha_corte' => '2000-01-01']);
});

/**
 * Carnicero que provee la carne (medallón de 100 g a $1.400, se compra por
 * kg = 10 medallones) y una Volcano Doble con 2 medallones + 1 pan.
 */
function escenarioProveedor(): array
{
    $carnicero = Proveedor::create(['nombre' => 'Carnicero']);

    $carne = Insumo::create([
        'nombre' => 'Carne (vacío)',
        'unidad' => 'medallón',
        'costo_unitario' => 1400,
        'stock_actual' => 60,
        'stock_minimo' => 0,
        'descuenta_stock' => true,
        'grupo_separacion' => 'carne',
        'proveedor_id' => $carnicero->id,
        'unidad_compra' => 'kg',
        'equivalencia_compra' => 10,
    ]);

    $pan = Insumo::create([
        'nombre' => 'Pan de hamburguesa',
        'unidad' => 'unidad',
        'costo_unitario' => 500,
        'stock_actual' => 100,
        'stock_minimo' => 0,
        'descuenta_stock' => true,
        'grupo_separacion' => 'pan',
    ]);

    $categoria = Categoria::create(['nombre' => 'Burgers', 'orden' => 1]);
    $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Volcano', 'activo' => true]);

    $doble = Variante::create([
        'producto_id' => $producto->id,
        'nombre' => 'Doble',
        'precio_venta' => 12000,
        'costo_calculado' => 0,
        'activo' => true,
    ]);

    Receta::create(['variante_id' => $doble->id, 'insumo_id' => $carne->id, 'cantidad' => 2]);
    Receta::create(['variante_id' => $doble->id, 'insumo_id' => $pan->id, 'cantidad' => 1]);
    $doble->recalcularCosto();

    $cajero = usuarioConRol('empleado');
    $admin = usuarioConRol('admin');

    $caja = Caja::create([
        'fecha_operativa' => Caja::fechaOperativaActual(),
        'estado' => 'abierta',
        'abierta_por' => $cajero->id,
        'abierta_at' => now(),
    ]);

    return compact('carnicero', 'carne', 'pan', 'doble', 'cajero', 'admin', 'caja');
}

function venderDoble(array $ctx, int $cantidad = 1): void
{
    test()->actingAs($ctx['cajero'])->postJson('/api/pos/venta', [
        'items' => [['variante_id' => $ctx['doble']->id, 'cantidad' => $cantidad]],
        'pagos' => [['metodo' => 'efectivo', 'monto' => 12000 * $cantidad]],
    ])->assertSuccessful();
}

function estadoCarnicero(array $ctx): array
{
    return collect(test()->actingAs($ctx['admin'])->getJson('/api/proveedores')->assertOk()->json())
        ->firstWhere('id', $ctx['carnicero']->id);
}

it('una Volcano Doble pasa $2.800 de stock a vendido sin pagar', function () {
    $ctx = escenarioProveedor();

    $this->actingAs($ctx['admin'])
        ->postJson("/api/proveedores/{$ctx['carnicero']->id}/movimientos", ['tipo' => 'saldo_inicial', 'monto' => 84000])
        ->assertCreated();

    $antes = estadoCarnicero($ctx);

    expect((float) $antes['deuda'])->toEqual(84000.0)
        ->and((float) $antes['vendido_sin_pagar'])->toEqual(0.0)
        ->and((float) $antes['en_stock_sin_pagar'])->toEqual(84000.0);

    venderDoble($ctx);

    $despues = estadoCarnicero($ctx);

    expect((float) $despues['deuda'])->toEqual(84000.0)
        ->and((float) $despues['vendido_sin_pagar'])->toEqual(2800.0)
        ->and((float) $despues['en_stock_sin_pagar'])->toEqual(81200.0)
        ->and((float) $despues['falta_separar'])->toEqual(2800.0)
        ->and($despues['unidades_hoy'][0]['cantidad'])->toEqual(2)
        ->and($despues['unidades_hoy'][0]['cantidad_compra'])->toEqual(0.2);
});

it('usa la parte ya vendida del saldo inicial', function () {
    $ctx = escenarioProveedor();
    $url = "/api/proveedores/{$ctx['carnicero']->id}/movimientos";

    $this->actingAs($ctx['admin'])->postJson($url, ['tipo' => 'saldo_inicial', 'monto' => 182000, 'monto_vendido' => 98000])->assertCreated();

    $estado = estadoCarnicero($ctx);

    expect((float) $estado['vendido_sin_pagar'])->toEqual(98000.0)
        ->and((float) $estado['en_stock_sin_pagar'])->toEqual(84000.0);

    $this->actingAs($ctx['admin'])->postJson($url, ['tipo' => 'pago', 'monto' => 98000])->assertCreated();

    $estado = estadoCarnicero($ctx);

    expect((float) $estado['deuda'])->toEqual(84000.0)
        ->and((float) $estado['vendido_sin_pagar'])->toEqual(0.0)
        ->and((float) $estado['en_stock_sin_pagar'])->toEqual(84000.0);
});

it('un pago baja la deuda y lo separado en el mismo monto', function () {
    $ctx = escenarioProveedor();
    $url = "/api/proveedores/{$ctx['carnicero']->id}/movimientos";

    $this->actingAs($ctx['admin'])->postJson($url, ['tipo' => 'saldo_inicial', 'monto' => 50000])->assertCreated();
    $this->actingAs($ctx['admin'])->postJson($url, ['tipo' => 'separacion', 'monto' => 20000])->assertCreated();

    $antes = estadoCarnicero($ctx);

    $this->actingAs($ctx['admin'])->postJson($url, ['tipo' => 'pago', 'monto' => 15000])->assertCreated();

    $despues = estadoCarnicero($ctx);

    expect((float) $antes['deuda'] - (float) $despues['deuda'])->toEqual(15000.0)
        ->and((float) $antes['separado'] - (float) $despues['separado'])->toEqual(15000.0);
});

it('un ajuste corrige solo la deuda y exige observación', function () {
    $ctx = escenarioProveedor();
    $url = "/api/proveedores/{$ctx['carnicero']->id}/movimientos";

    $this->actingAs($ctx['admin'])->postJson($url, ['tipo' => 'ajuste', 'monto' => -1000])->assertStatus(422);
    $this->actingAs($ctx['admin'])->postJson($url, ['tipo' => 'ajuste', 'monto' => -1000, 'observacion' => 'Descuento'])->assertCreated();

    $estado = estadoCarnicero($ctx);

    expect((float) $estado['deuda'])->toEqual(-1000.0)
        ->and((float) $estado['separado'])->toEqual(0.0);
});

it('una entrega en kg suma deuda y stock y actualiza el costo', function () {
    $ctx = escenarioProveedor();

    $res = $this->actingAs($ctx['admin'])
        ->postJson("/api/proveedores/{$ctx['carnicero']->id}/movimientos", [
            'tipo' => 'entrega',
            'monto' => 75000,
            'insumo_id' => $ctx['carne']->id,
            'cantidad' => 5,
            'unidad' => 'kg',
            'actualizar_costo' => true,
        ])
        ->assertCreated()
        ->json();

    expect((float) $res['costo_anterior'])->toEqual(1400.0)
        ->and((float) $res['costo_nuevo'])->toEqual(1500.0)
        ->and((float) $res['movimiento']['cantidad_insumo'])->toEqual(50.0)
        ->and((float) $res['proveedor']['deuda'])->toEqual(75000.0)
        ->and((float) $ctx['carne']->fresh()->stock_actual)->toEqual(110.0)
        ->and((float) $ctx['carne']->fresh()->costo_unitario)->toEqual(1500.0)
        ->and((float) $ctx['doble']->fresh()->costo_calculado)->toEqual(3500.0);
});

it('cambiar costos o recetas no mueve los montos de ventas pasadas', function () {
    $ctx = escenarioProveedor();

    venderDoble($ctx);

    $this->actingAs($ctx['admin'])->putJson("/api/admin/insumos/{$ctx['carne']->id}", ['costo_unitario' => 3000])->assertOk();
    Receta::where('variante_id', $ctx['doble']->id)->where('insumo_id', $ctx['carne']->id)->update(['cantidad' => 3]);

    expect((float) VentaInsumo::where('insumo_id', $ctx['carne']->id)->sum('subtotal'))->toEqual(2800.0)
        ->and((float) estadoCarnicero($ctx)['vendido_sin_pagar'])->toEqual(2800.0);
});

it('la vista del día suma lo mismo que costo_insumos de la caja', function () {
    $ctx = escenarioProveedor();

    venderDoble($ctx, 3);

    $dia = $this->actingAs($ctx['admin'])->getJson("/api/separacion/dia?caja_id={$ctx['caja']->id}")->assertOk()->json();
    $caja = $this->actingAs($ctx['admin'])->getJson("/api/caja/historial/{$ctx['caja']->id}")->assertOk()->json();

    $carne = collect($dia['grupos'])->firstWhere('grupo', 'carne');

    expect((float) $dia['total'])->toEqual((float) $caja['stats_guardadas']['costo_insumos'])
        ->and((float) $dia['total'])->toEqual(3 * 3300.0)
        ->and($carne['insumos'][0]['cantidad'])->toEqual(6)
        ->and((float) $carne['monto'])->toEqual(8400.0);

    $lineas = collect($dia['lineas']);

    expect((float) $lineas->firstWhere('proveedor_id', $ctx['carnicero']->id)['monto'])->toEqual(8400.0)
        ->and((float) $lineas->firstWhere('grupo', 'pan')['monto'])->toEqual(1500.0);
});

it('el cajero confirma la separación de su caja una sola vez', function () {
    $ctx = escenarioProveedor();

    venderDoble($ctx);

    $this->actingAs($ctx['cajero'])->postJson('/api/caja/cerrar', [])->assertOk();

    $dia = $this->actingAs($ctx['cajero'])->getJson('/api/separacion/dia')->assertOk()->json();

    expect($dia['caja']['id'])->toBe($ctx['caja']->id)
        ->and($dia['separacion'])->toBeNull();

    $lineas = collect($dia['lineas'])->map(fn ($l) => [
        'proveedor_id' => $l['proveedor_id'],
        'grupo' => $l['grupo'],
        'label' => $l['label'],
        'monto' => $l['monto'],
    ])->all();

    $url = "/api/separacion/caja/{$ctx['caja']->id}/confirmar";

    $this->actingAs($ctx['cajero'])->postJson($url, ['lineas' => $lineas])
        ->assertCreated()
        ->assertJsonPath('separacion.total', 3300);

    $this->actingAs($ctx['cajero'])->postJson($url, ['lineas' => $lineas])->assertStatus(422);

    expect(CajaSeparacion::count())->toBe(1)
        ->and(ProveedorMovimiento::where('tipo', 'separacion')->count())->toBe(1)
        ->and((float) ProveedorMovimiento::where('tipo', 'separacion')->value('monto'))->toEqual(2800.0)
        ->and(ProveedorMovimiento::where('tipo', 'separacion')->value('caja_id'))->toBe($ctx['caja']->id);

    $estado = estadoCarnicero($ctx);

    expect((float) $estado['separado'])->toEqual(2800.0)
        ->and((float) $estado['falta_separar'])->toEqual(0.0);
});

it('el cajero no ve proveedores ni cajas viejas', function () {
    $ctx = escenarioProveedor();

    $vieja = Caja::create([
        'fecha_operativa' => '2026-01-01',
        'estado' => 'cerrada',
        'abierta_por' => $ctx['cajero']->id,
        'abierta_at' => now(),
    ]);

    $this->actingAs($ctx['cajero'])->getJson('/api/proveedores')->assertStatus(403);
    $this->actingAs($ctx['cajero'])->postJson("/api/proveedores/{$ctx['carnicero']->id}/movimientos", ['tipo' => 'pago', 'monto' => 1])->assertStatus(403);
    $this->actingAs($ctx['cajero'])->getJson("/api/separacion/dia?caja_id={$vieja->id}")->assertStatus(403);
    $this->actingAs($ctx['cajero'])->postJson("/api/separacion/caja/{$vieja->id}/confirmar", ['lineas' => [['grupo' => 'pan', 'monto' => 1]]])->assertStatus(403);
    $this->actingAs($ctx['admin'])->getJson("/api/separacion/dia?caja_id={$vieja->id}")->assertOk();
});

it('los rubros con proveedor no aparecen en el fondo por rubro', function () {
    $ctx = escenarioProveedor();

    venderDoble($ctx);

    $res = $this->actingAs($ctx['admin'])->getJson('/api/separacion')->assertOk()->json();

    $grupos = collect($res['grupos'])->pluck('grupo');

    expect($grupos)->not->toContain('carne')
        ->and($grupos)->toContain('pan')
        ->and((float) $res['total_general'])->toEqual(500.0)
        ->and((float) $res['hoy']['total'])->toEqual(3300.0)
        ->and($res['proveedores'])->toHaveCount(1);
});
