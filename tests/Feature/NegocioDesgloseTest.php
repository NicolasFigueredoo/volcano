<?php

use App\Models\Categoria;
use App\Models\DetalleVenta;
use App\Models\GastoFijo;
use App\Models\Producto;
use App\Models\Variante;

function varianteDeNegocio(string $negocio, string $nombre, float $precio, float $costo): Variante
{
    $categoria = Categoria::firstOrCreate(['nombre' => ucfirst($negocio)], ['negocio' => $negocio]);
    $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => $nombre]);

    return Variante::create([
        'producto_id' => $producto->id,
        'nombre' => 'Única',
        'precio_venta' => $precio,
        'costo_calculado' => $costo,
        'activo' => true,
    ]);
}

function venderConCajero(array $payload): void
{
    $cajero = usuarioConRol('empleado');
    test()->actingAs($cajero)->postJson('/api/caja/abrir', [])->assertSuccessful();
    test()->actingAs($cajero)->postJson('/api/pos/venta', $payload)->assertCreated();
}

function desgloseDeHoy(): array
{
    return test()->actingAs(usuarioConRol('admin'))
        ->getJson('/api/caja/hoy')
        ->assertOk()
        ->json('stats.por_negocio');
}

beforeEach(function () {
    // La migración carga los gastos de la parrilla; acá se arma un escenario propio.
    GastoFijo::query()->delete();

    GastoFijo::create(['nombre' => 'Alquiler', 'negocio' => 'compartido', 'monto_mensual' => 32000, 'dias_apertura_mes' => 16]);
    GastoFijo::create(['nombre' => 'Delivery', 'negocio' => 'burger', 'monto_mensual' => 20000, 'dias_apertura_mes' => 1]);
    GastoFijo::create(['nombre' => 'Parrillero', 'negocio' => 'parrilla', 'monto_mensual' => 30000, 'dias_apertura_mes' => 1]);
});

it('reparte un pedido mezclado entre burger, parrilla y general', function () {
    $burger = varianteDeNegocio('burger', 'Cheese', 10000, 4000);
    $vacio = varianteDeNegocio('parrilla', 'Vacío', 9000, 4325);
    $coca = varianteDeNegocio('general', 'Coca', 2000, 1200);

    venderConCajero([
        'descuento' => 2100, // 10% del pedido, se reparte proporcional
        'items' => [
            ['variante_id' => $burger->id, 'cantidad' => 1],
            ['variante_id' => $vacio->id, 'cantidad' => 1],
            ['variante_id' => $coca->id, 'cantidad' => 1],
        ],
        'pagos' => [['metodo' => 'efectivo', 'monto' => 18900]],
    ]);

    $d = desgloseDeHoy();

    // Alquiler $2000 por día, mitad y mitad entre los dos que vendieron.
    expect($d['burger'])->toMatchArray([
        'ventas' => 9000, 'costo_insumos' => 4000, 'gastos_fijos' => 21000, 'ganancia_neta' => -16000,
    ])->and($d['parrilla'])->toMatchArray([
        'ventas' => 8100, 'costo_insumos' => 4325, 'gastos_fijos' => 31000, 'ganancia_neta' => -27225,
    ])->and($d['general'])->toMatchArray([
        'ventas' => 1800, 'costo_insumos' => 1200, 'gastos_fijos' => 0, 'ganancia_neta' => 600,
    ]);
});

it('carga todo el alquiler a la burger si la parrilla no vendió', function () {
    $burger = varianteDeNegocio('burger', 'Cheese', 10000, 4000);

    venderConCajero([
        'items' => [['variante_id' => $burger->id, 'cantidad' => 1]],
        'pagos' => [['metodo' => 'efectivo', 'monto' => 10000]],
    ]);

    $d = desgloseDeHoy();

    expect($d['burger']['gastos_fijos'])->toEqual(22000)
        ->and($d['parrilla']['gastos_fijos'])->toEqual(30000);
});

it('la línea vendida conserva su negocio aunque después se mueva la categoría', function () {
    $vacio = varianteDeNegocio('parrilla', 'Vacío', 9000, 4325);

    venderConCajero([
        'items' => [['variante_id' => $vacio->id, 'cantidad' => 1]],
        'pagos' => [['metodo' => 'efectivo', 'monto' => 9000]],
    ]);

    $vacio->producto->categoria->update(['negocio' => 'burger']);

    expect(DetalleVenta::first()->negocio)->toBe('parrilla');
});

it('el admin puede cambiar el negocio de una categoría', function () {
    $categoria = Categoria::create(['nombre' => 'Sanguches']);

    $this->actingAs(usuarioConRol('admin'))
        ->putJson("/api/admin/categorias/{$categoria->id}", ['negocio' => 'parrilla'])
        ->assertOk()
        ->assertJsonPath('negocio', 'parrilla');

    $this->actingAs(usuarioConRol('admin'))
        ->putJson("/api/admin/categorias/{$categoria->id}", ['negocio' => 'otro'])
        ->assertUnprocessable();
});

it('un gasto por jornada se descuenta entero cada día', function () {
    $gasto = GastoFijo::create([
        'nombre' => 'Carbón',
        'negocio' => 'parrilla',
        'periodicidad' => 'jornada',
        'monto_jornada' => 3500,
        'dias_apertura_mes' => 16,
    ]);

    expect($gasto->costo_diario)->toEqual(3500.0)
        ->and((float) $gasto->monto_mensual)->toEqual(56000.0);

    $this->actingAs(usuarioConRol('admin'))
        ->putJson("/api/admin/gastos-fijos/{$gasto->id}", ['periodicidad' => 'jornada', 'monto_jornada' => 4000])
        ->assertOk();

    expect($gasto->fresh()->costo_diario)->toEqual(4000.0);
});
