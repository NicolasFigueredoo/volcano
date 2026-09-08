<?php

namespace App\Observers;

use App\Models\Venta;
use App\Services\SeparacionService;

class VentaObserver
{
    /**
     * Cada vez que una venta cambia de estado se recalcula la plata que hay
     * que apartar para reponer insumos. SeparacionService::sincronizar() es
     * idempotente, así que repetir la llamada nunca duplica movimientos.
     */
    public function saved(Venta $venta): void
    {
        if (! $venta->wasRecentlyCreated && ! $venta->wasChanged('estado')) {
            return;
        }

        SeparacionService::sincronizar($venta);
    }
}
