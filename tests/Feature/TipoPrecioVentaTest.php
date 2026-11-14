<?php

use App\Models\Categoria;
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
