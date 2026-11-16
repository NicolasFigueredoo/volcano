<?php

it('el admin obtiene el resumen en markdown', function () {
    $this->actingAs(usuarioConRol('admin'))
        ->getJson('/api/admin/exportar-resumen')
        ->assertOk()
        ->assertJsonPath('texto', fn ($t) => str_contains($t, '## Carta') && str_contains($t, '## Gastos fijos'));
});

it('un empleado no puede exportar el resumen', function () {
    $this->actingAs(usuarioConRol('empleado'))
        ->getJson('/api/admin/exportar-resumen')
        ->assertForbidden();
});
