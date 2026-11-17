<?php

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Variante;
use Illuminate\Support\Carbon;

function varianteParaPedidos(): Variante
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

it('no pierde los pedidos activos cuando cambia el día en UTC', function () {
    // 23:30 UTC = 20:30 en Argentina
    Carbon::setTestNow('2026-11-16 23:30:00');

    $cajero = usuarioConRol('empleado');
    $variante = varianteParaPedidos();

    $this->actingAs($cajero)->postJson('/api/caja/abrir', [])->assertSuccessful();
    $this->actingAs($cajero)->postJson('/api/pos/venta', [
        'items' => [['variante_id' => $variante->id, 'cantidad' => 1]],
        'pagos' => [['metodo' => 'efectivo', 'monto' => 1000]],
    ])->assertCreated();

    // 01:00 UTC del día siguiente = 22:00 en Argentina, misma jornada
    Carbon::setTestNow('2026-11-17 01:00:00');

    $this->actingAs($cajero)->getJson('/api/pos/pedidos-activos')
        ->assertOk()
        ->assertJsonCount(1);

    // El número de orden sigue la jornada, no reinicia en 1
    $segunda = $this->actingAs($cajero)->postJson('/api/pos/venta', [
        'items' => [['variante_id' => $variante->id, 'cantidad' => 1]],
        'pagos' => [['metodo' => 'efectivo', 'monto' => 1000]],
    ])->assertCreated();

    expect($segunda->json('numero_orden'))->toBe(2);
});
