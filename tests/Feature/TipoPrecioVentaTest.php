<?php

use App\Models\Categoria;
use App\Models\Insumo;
use App\Models\Producto;
use App\Models\Variante;
use App\Models\Venta;

function varianteDePrueba(): Variante
{
    $categoria = Categoria::create(['nombre' => 'Burgers']);
    $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Burger']);

    return Variante::create([
        'producto_id' => $producto->id,
        'nombre' => 'Simple',
        'precio_venta' => 1000,
        'costo_calculado' => 400,
        'activo' => true,
    ]);
}

function abrirCajaYVender(array $payload)
{
    $cajero = usuarioConRol('empleado');
    test()->actingAs($cajero)->postJson('/api/caja/abrir', [])->assertSuccessful();

    return test()->actingAs($cajero)->postJson('/api/pos/venta', $payload);
}

it('cobra precio de cliente por defecto', function () {
    $variante = varianteDePrueba();

    abrirCajaYVender([
        'items' => [['variante_id' => $variante->id, 'cantidad' => 2]],
        'pagos' => [['metodo' => 'efectivo', 'monto' => 2000]],
    ])->assertCreated();

    $venta = Venta::first();
    expect($venta->tipo_precio)->toBe('cliente')
        ->and((float) $venta->total)->toBe(2000.0);
});

it('cobra solo el costo cuando el tipo es costo', function () {
    $variante = varianteDePrueba();

    abrirCajaYVender([
        'tipo_precio' => 'costo',
        'descuento' => 100,
        'items' => [['variante_id' => $variante->id, 'cantidad' => 2]],
        'pagos' => [['metodo' => 'transferencia', 'monto' => 800]],
    ])->assertCreated();

    $venta = Venta::with('detalles')->first();
    expect($venta->tipo_precio)->toBe('costo')
        ->and((float) $venta->total)->toBe(800.0)
        ->and((float) $venta->descuento)->toBe(0.0)
        ->and((float) $venta->detalles->first()->precio_snapshot)->toBe(400.0)
        ->and((float) $venta->detalles->first()->costo_snapshot)->toBe(400.0);
});

it('registra un regalo sin pagos y en cero', function () {
    $variante = varianteDePrueba();

    abrirCajaYVender([
        'tipo_precio' => 'regalo',
        'items' => [['variante_id' => $variante->id, 'cantidad' => 1]],
    ])->assertCreated();

    $venta = Venta::with('detalles', 'pagos')->first();
    expect($venta->tipo_precio)->toBe('regalo')
        ->and((float) $venta->total)->toBe(0.0)
        ->and($venta->pagos)->toHaveCount(0)
        ->and((float) $venta->detalles->first()->costo_snapshot)->toBe(400.0);
});

it('vende una quintuple como triple + 2 patty extra', function () {
    $carne = Insumo::create(['nombre' => 'Carne', 'unidad' => 'medallón', 'costo_unitario' => 1500, 'stock_actual' => 50, 'descuenta_stock' => true]);
    $categoria = Categoria::create(['nombre' => 'Burgers']);
    $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Volcano']);
    $triple = $producto->variantes()->create(['nombre' => 'Triple', 'precio_venta' => 14000, 'activo' => true]);
    $triple->recetas()->create(['insumo_id' => $carne->id, 'cantidad' => 3]);
    $extra = $producto->variantes()->create(['nombre' => 'Patty extra', 'precio_venta' => 2000, 'activo' => true, 'es_extra' => true]);
    $extra->recetas()->create(['insumo_id' => $carne->id, 'cantidad' => 1]);
    $extra->recalcularCosto();

    abrirCajaYVender([
        'items' => [
            ['variante_id' => $triple->id, 'cantidad' => 1],
            ['variante_id' => $extra->id, 'cantidad' => 2],
        ],
        'pagos' => [['metodo' => 'efectivo', 'monto' => 18000]],
    ])->assertCreated();

    expect((float) Venta::first()->total)->toBe(18000.0)
        ->and((float) $carne->fresh()->stock_actual)->toBe(45.0);
});
