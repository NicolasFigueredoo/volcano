<?php

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Inversion;
use App\Models\Variante;
use App\Models\Venta;
use Database\Seeders\CartaBebidasSeeder;

beforeEach(function () {
    config(['separacion.fecha_corte' => '2000-01-01']);
});

function cartaBebidas(): Categoria
{
    $bebidas = Categoria::create(['nombre' => 'Bebidas', 'orden' => 3]);

    (new CartaBebidasSeeder)->run();

    return $bebidas;
}

function variantePorProducto(string $producto): Variante
{
    return Variante::whereHas('producto', fn ($q) => $q->where('nombre', $producto))->firstOrFail();
}

function cajaDel(string $fecha, int $userId): Caja
{
    return Caja::create([
        'fecha_operativa' => $fecha,
        'estado' => 'abierta',
        'abierta_por' => $userId,
        'abierta_at' => now(),
    ]);
}

function venderEn(Caja $caja, Variante $variante, int $userId, int $cantidad = 1): Venta
{
    return Venta::registrarEnCaja(
        $caja,
        ['estado' => 'pagado'],
        [['variante_id' => $variante->id, 'cantidad' => $cantidad]],
        [['metodo' => 'efectivo', 'monto' => (float) $variante->precio_venta * $cantidad]],
        $userId
    );
}

it('carga la carta con los costos de la ficha', function () {
    cartaBebidas();

    $costos = [
        'Fernet con Coca' => 5751,
        'Federico + Speed' => 3280,
        'Skyy + Speed' => 4300,
        'Gancia con Sprite' => 2981,
        'Brahma' => 1750,
        'Schneider' => 2317,
        'Speed' => 2000,
    ];

    foreach ($costos as $producto => $costo) {
        expect(round((float) variantePorProducto($producto)->costo_calculado))->toEqual($costo);
    }

    expect(Inversion::sum('monto'))->toEqual(150000);

    // Correrlo de nuevo no duplica nada.
    (new CartaBebidasSeeder)->run();

    expect(Variante::whereHas('producto.categoria', fn ($q) => $q->where('nombre', 'Bebidas'))->count())->toBe(7)
        ->and(Inversion::count())->toBe(1);
});

it('mide el recupero con la ganancia de bebidas, sin anuladas ni ventas previas', function () {
    $bebidas = cartaBebidas();
    Inversion::query()->update(['fecha' => '2026-10-01']);

    $admin = usuarioConRol('admin');
    $fernet = variantePorProducto('Fernet con Coca');

    $caja = cajaDel('2026-10-05', $admin->id);
    venderEn($caja, $fernet, $admin->id, 2);
    venderEn($caja, $fernet, $admin->id)->update(['estado' => 'anulado']);

    // Antes de la inversión: no cuenta.
    venderEn(cajaDel('2026-09-20', $admin->id), $fernet, $admin->id);

    // Otro rubro: no cuenta.
    $burgers = Categoria::create(['nombre' => 'Burgers', 'orden' => 1]);
    $burger = $burgers->productos()->create(['nombre' => 'Simple', 'activo' => true])
        ->variantes()->create(['nombre' => 'Única', 'precio_venta' => 5000, 'costo_calculado' => 0, 'activo' => true]);
    venderEn($caja, $burger, $admin->id);

    $recupero = $this->actingAs($admin)->getJson('/api/admin/bebidas')->assertOk()->json('recupero');

    $ganancia = 2 * (10000 - (float) $fernet->costo_calculado);

    expect((float) $recupero['ganancia'])->toEqual($ganancia)
        ->and($recupero['unidades'])->toBe(2)
        ->and((float) $recupero['falta'])->toEqual(150000 - $ganancia)
        ->and((float) $recupero['pct'])->toEqual(round($ganancia / 150000 * 100, 1));

    expect($bebidas->id)->toBe(Categoria::bebidas()->id);
});

it('topea el progreso en 100%', function () {
    cartaBebidas();
    Inversion::query()->update(['monto' => 1000, 'fecha' => '2026-10-01']);

    $admin = usuarioConRol('admin');
    venderEn(cajaDel('2026-10-05', $admin->id), variantePorProducto('Fernet con Coca'), $admin->id);

    $recupero = $this->actingAs($admin)->getJson('/api/admin/bebidas')->assertOk()->json('recupero');

    expect((float) $recupero['pct'])->toEqual(100.0)
        ->and((float) $recupero['falta'])->toEqual(0.0);
});

it('administra inversiones solo como admin', function () {
    $bebidas = cartaBebidas();
    $admin = usuarioConRol('admin');
    $cajero = usuarioConRol('empleado');

    $this->actingAs($cajero)->getJson('/api/admin/bebidas')->assertForbidden();
    $this->actingAs($cajero)->postJson('/api/admin/inversiones', [])->assertForbidden();

    $id = $this->actingAs($admin)->postJson('/api/admin/inversiones', [
        'categoria_id' => $bebidas->id,
        'monto' => 50000,
        'fecha' => '2026-10-30',
        'descripcion' => 'Reposición',
    ])->assertCreated()->json('id');

    $this->actingAs($admin)->putJson("/api/admin/inversiones/{$id}", ['monto' => 60000])->assertOk();

    expect((float) $this->actingAs($admin)->getJson('/api/admin/bebidas')->json('recupero.invertido'))->toEqual(210000.0);

    $this->actingAs($admin)->deleteJson("/api/admin/inversiones/{$id}")->assertOk();

    expect(Inversion::count())->toBe(1);
});
