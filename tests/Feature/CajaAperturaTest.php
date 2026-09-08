<?php

use App\Models\Caja;

it('abre la caja con la fecha operativa elegida', function () {
    $cajero = usuarioConRol('empleado');

    $this->actingAs($cajero)
        ->postJson('/api/caja/abrir', ['fecha_operativa' => '2026-09-01'])
        ->assertCreated()
        ->assertJsonPath('estado', 'abierta');

    expect(Caja::count())->toBe(1)
        ->and(Caja::first()->fecha_operativa->toDateString())->toBe('2026-09-01');
});

it('permite abrir y cerrar varias cajas el mismo día', function () {
    $cajero = usuarioConRol('empleado');
    $fecha = Caja::fechaOperativaActual();

    $this->actingAs($cajero)->postJson('/api/caja/abrir', ['fecha_operativa' => $fecha])->assertCreated();
    $this->actingAs($cajero)->postJson('/api/caja/cerrar', [])->assertOk();

    $this->actingAs($cajero)->postJson('/api/caja/abrir', ['fecha_operativa' => $fecha])->assertCreated();

    expect(Caja::whereDate('fecha_operativa', $fecha)->count())->toBe(2)
        ->and(Caja::whereDate('fecha_operativa', $fecha)->where('estado', 'abierta')->count())->toBe(1);
});

it('devuelve la misma caja si ya hay una abierta de esa fecha', function () {
    $cajero = usuarioConRol('empleado');
    $fecha = Caja::fechaOperativaActual();

    $primera = $this->actingAs($cajero)->postJson('/api/caja/abrir', ['fecha_operativa' => $fecha])->json('id');
    $segunda = $this->actingAs($cajero)->postJson('/api/caja/abrir', ['fecha_operativa' => $fecha])->assertOk()->json('id');

    expect($segunda)->toBe($primera)
        ->and(Caja::count())->toBe(1);
});

it('no deja abrir una caja de otra fecha si hay una abierta', function () {
    $cajero = usuarioConRol('empleado');

    $this->actingAs($cajero)->postJson('/api/caja/abrir', ['fecha_operativa' => '2026-09-01'])->assertCreated();

    $this->actingAs($cajero)
        ->postJson('/api/caja/abrir', ['fecha_operativa' => '2026-09-02'])
        ->assertStatus(422);

    expect(Caja::count())->toBe(1);
});
