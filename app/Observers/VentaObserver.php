<?php

namespace App\Observers;

use App\Models\Venta;
use App\Services\SeparacionService;

class VentaObserver
{
    /**
     * Cada vez que una venta cambia de estado se sincroniza su snapshot de
     * insumos (venta_insumos). SeparacionService::sincronizar() es
     * idempotente, así que repetir la llamada nunca duplica filas.
     */
    public function saved(Venta $venta): void
    {
        if (! $venta->wasRecentlyCreated && ! $venta->wasChanged('estado')) {
            return;
        }

        // Una venta que vuelve de "anulado" perdió sus filas: se rearman.
        if ($venta->wasChanged('estado') && $venta->getOriginal('estado') === 'anulado') {
            SeparacionService::regenerar($venta);

            return;
        }

        SeparacionService::sincronizar($venta);
    }
}
