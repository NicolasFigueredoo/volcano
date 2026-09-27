<?php

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Insumo;
use App\Models\Producto;
use App\Models\Receta;
use App\Models\Reposicion;
use App\Models\Variante;
use App\Models\Venta;
use App\Models\VentaInsumo;

beforeEach(function () {
    config(['separacion.fecha_corte' => '2000-01-01']);
});

/**
 * Arma un escenario mínimo: una hamburguesa con 1 medallón de carne ($1000),
 * 1 pan ($400) y 1 bolsa de delivery ($100, sin descuento de stock).
 */
function escenarioSeparacion(): array
{
    $carne = Insumo::create([
        'nombre' => 'Carne test',
        'unidad' => 'medallón',
        'costo_unitario' => 1000,
        'stock_actual' => 100,
        'stock_minimo' => 0,
        'descuenta_stock' => true,
        'grupo_separacion' => 'carne',
    ]);

    $pan = Insumo::create([
        'nombre' => 'Pan test',
        'unidad' => 'unidad',
        'costo_unitario' => 400,
        'stock_actual' => 100,
        'stock_minimo' => 0,
        'descuenta_stock' => true,
        'grupo_separacion' => 'pan',
    ]);

    $bolsa = Insumo::create([
        'nombre' => 'Bolsa test',
        'unidad' => 'unidad',
        'costo_unitario' => 100,
        'stock_actual' => 100,
        'stock_minimo' => 0,
        'descuenta_stock' => false,
        'grupo_separacion' => 'descartables',
    ]);

    $categoria = Categoria::create(['nombre' => 'Burgers', 'orden' => 1]);

    $producto = Producto::create([
        'categoria_id' => $categoria->id,
        'nombre' => 'Simple',
        'activo' => true,
    ]);

    $variante = Variante::create([
        'producto_id' => $producto->id,
        'nombre' => 'Única',
        'precio_venta' => 5000,
        'costo_calculado' => 0,
        'activo' => true,
    ]);

    foreach ([$carne, $pan, $bolsa] as $insumo) {
        Receta::create([
            'variante_id' => $variante->id,
            'insumo_id' => $insumo->id,
            'cantidad' => 1,
        ]);
    }

    $variante->recalcularCosto();

    $cajero = usuarioConRol('empleado');

    $caja = Caja::create([
        'fecha_operativa' => Caja::fechaOperativaActual(),
        'estado' => 'abierta',
        'abierta_por' => $cajero->id,
        'abierta_at' => now(),
    ]);

    return compact('carne', 'pan', 'bolsa', 'variante', 'cajero', 'caja');
}

function ventaPagada(array $ctx, int $cantidad = 1): Venta
{
    return Venta::registrarEnCaja(
        $ctx['caja'],
        ['estado' => 'pagado'],
        [['variante_id' => $ctx['variante']->id, 'cantidad' => $cantidad]],
        [['metodo' => 'efectivo', 'monto' => 5000 * $cantidad]],
        $ctx['cajero']->id
    );
}

it('guarda el snapshot de insumos de la venta', function () {
    $ctx = escenarioSeparacion();

    $venta = ventaPagada($ctx, 2);

    $filas = VentaInsumo::where('venta_id', $venta->id)->get()->keyBy('grupo_separacion');

    expect($filas)->toHaveCount(3)
        ->and((float) $filas['carne']->subtotal)->toEqual(2000.0)
        ->and((float) $filas['carne']->cantidad)->toEqual(2.0)
        ->and($filas['carne']->caja_id)->toBe($ctx['caja']->id)
        ->and((float) $filas['pan']->subtotal)->toEqual(800.0)
        ->and((float) $filas['descartables']->subtotal)->toEqual(200.0);
});

it('no duplica movimientos si la venta cambia de estado varias veces', function () {
    $ctx = escenarioSeparacion();

    $venta = ventaPagada($ctx);

    $venta->update(['estado' => 'entregado']);
    $venta->update(['estado' => 'pagado']);
    $venta->update(['estado' => 'entregado']);

    expect(VentaInsumo::where('venta_id', $venta->id)->count())->toBe(3)
        ->and((float) VentaInsumo::where('venta_id', $venta->id)->where('grupo_separacion', 'carne')->value('subtotal'))
        ->toEqual(1000.0);
});

it('genera el snapshot aunque la venta esté pendiente (igual que costo_insumos)', function () {
    $ctx = escenarioSeparacion();

    $venta = Venta::registrarEnCaja(
        $ctx['caja'],
        ['estado' => 'pendiente'],
        [['variante_id' => $ctx['variante']->id, 'cantidad' => 1]],
        [['metodo' => 'efectivo', 'monto' => 5000]],
        $ctx['cajero']->id
    );

    expect(VentaInsumo::where('venta_id', $venta->id)->count())->toBe(3);
});

it('acumula el total de un grupo y lo resetea al reponer', function () {
    $ctx = escenarioSeparacion();
    $admin = usuarioConRol('admin');

    ventaPagada($ctx);
    ventaPagada($ctx);

    $antes = $this->actingAs($admin)->getJson('/api/separacion')->assertOk()->json();

    $carne = collect($antes['grupos'])->firstWhere('grupo', 'carne');

    expect((float) $carne['monto_acumulado'])->toEqual(2000.0)
        ->and($carne['cantidad_ventas'])->toBe(2)
        ->and((float) $antes['total_general'])->toEqual(3000.0);

    $despues = $this->actingAs($admin)
        ->postJson('/api/separacion/carne/reponer', ['monto_real' => 2500, 'observacion' => 'Compré 5 kg'])
        ->assertCreated()
        ->json();

    $carneDespues = collect($despues['grupos'])->firstWhere('grupo', 'carne');

    expect((float) $carneDespues['monto_acumulado'])->toEqual(0.0)
        ->and($carneDespues['cantidad_ventas'])->toBe(0)
        ->and((float) $despues['historial'][0]['diferencia'])->toEqual(500.0);

    // Nada se borra: las filas quedan estampadas con la reposición.
    $reposicion = Reposicion::where('grupo', 'carne')->firstOrFail();

    expect(VentaInsumo::where('grupo_separacion', 'carne')->count())->toBe(2)
        ->and(VentaInsumo::where('reposicion_id', $reposicion->id)->count())->toBe(2);
});

it('descuenta del acumulado una venta anulada que todavía no se repuso', function () {
    $ctx = escenarioSeparacion();

    $venta = ventaPagada($ctx);
    $otra = ventaPagada($ctx);

    $venta->update(['estado' => 'anulado']);

    expect(VentaInsumo::where('venta_id', $venta->id)->count())->toBe(0)
        ->and(VentaInsumo::where('venta_id', $otra->id)->count())->toBe(3);
});

it('conserva los movimientos ya repuestos aunque se anule la venta', function () {
    $ctx = escenarioSeparacion();
    $admin = usuarioConRol('admin');

    $venta = ventaPagada($ctx);

    $this->actingAs($admin)->postJson('/api/separacion/carne/reponer', [])->assertCreated();

    $venta->update(['estado' => 'anulado']);

    expect(VentaInsumo::where('venta_id', $venta->id)->where('grupo_separacion', 'carne')->count())->toBe(1)
        ->and(VentaInsumo::where('venta_id', $venta->id)->where('grupo_separacion', 'pan')->count())->toBe(0);
});

it('no cuenta ventas de cajas anteriores a la fecha de corte', function () {
    $ctx = escenarioSeparacion();
    $admin = usuarioConRol('admin');

    ventaPagada($ctx);

    config(['separacion.fecha_corte' => now()->addDays(2)->toDateString()]);

    $res = $this->actingAs($admin)->getJson('/api/separacion')->assertOk()->json();

    expect((float) $res['total_general'])->toEqual(0.0);
});

it('rearma el snapshot al des-anular una venta', function () {
    $ctx = escenarioSeparacion();

    $venta = ventaPagada($ctx);
    $venta->update(['estado' => 'anulado']);
    $venta->update(['estado' => 'pagado']);

    expect(VentaInsumo::where('venta_id', $venta->id)->count())->toBe(3);
});

it('solo permite el acceso a los administradores', function () {
    $cajero = usuarioConRol('empleado');

    $this->actingAs($cajero)->getJson('/api/separacion')->assertStatus(403);
    $this->actingAs($cajero)->postJson('/api/separacion/carne/reponer', [])->assertStatus(403);
    $this->actingAs($cajero)->get('/separacion')->assertRedirect(route('pos'));
});

it('rechaza un grupo inexistente', function () {
    $admin = usuarioConRol('admin');

    $this->actingAs($admin)->postJson('/api/separacion/chorizo/reponer', [])->assertStatus(422);
});
