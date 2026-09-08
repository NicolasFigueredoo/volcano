<?php

namespace App\Services;

use App\Models\Insumo;
use App\Models\Reposicion;
use App\Models\SeparacionMovimiento;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

class SeparacionService
{
    /**
     * Estados en los que una venta ya "consumió" insumos y por lo tanto
     * genera plata a apartar.
     */
    private const ESTADOS_COMPUTABLES = ['pagado', 'entregado'];

    /**
     * Recalcula los movimientos de separación de una venta.
     *
     * Es idempotente: llamarla N veces deja siempre el mismo estado. Los
     * movimientos que ya fueron incluidos en una reposición no se tocan
     * (quedan congelados para no romper el histórico).
     */
    public static function sincronizar(Venta $venta): void
    {
        $venta->load('detalles.variante.recetas.insumo');

        $objetivo = self::montosPorGrupo($venta);

        DB::transaction(function () use ($venta, $objetivo) {
            $existentes = SeparacionMovimiento::where('venta_id', $venta->id)->get();

            foreach ($existentes as $movimiento) {
                // Ya liquidado en una reposición: se respeta tal cual.
                if ($movimiento->reposicion_id !== null) {
                    continue;
                }

                $monto = $objetivo[$movimiento->grupo] ?? 0;

                if ($monto <= 0) {
                    $movimiento->delete();

                    continue;
                }

                $movimiento->update(['monto' => $monto]);
            }

            $yaGuardados = $existentes->pluck('grupo')->all();

            foreach ($objetivo as $grupo => $monto) {
                if ($monto <= 0 || in_array($grupo, $yaGuardados, true)) {
                    continue;
                }

                SeparacionMovimiento::create([
                    'venta_id' => $venta->id,
                    'grupo' => $grupo,
                    'monto' => $monto,
                ]);
            }
        });
    }

    /**
     * Expande cada detalle por la receta de su variante y acumula el costo de
     * cada insumo en su grupo. Los insumos con descuenta_stock = false (aceite,
     * garrafa, salsa, descartables) entran igual, prorrateados por su
     * costo_unitario tal como ya lo hace Variante::recalcularCosto().
     *
     * @return array<string, float>
     */
    public static function montosPorGrupo(Venta $venta): array
    {
        $montos = [];

        if (! in_array($venta->estado, self::ESTADOS_COMPUTABLES, true)) {
            return $montos;
        }

        foreach ($venta->detalles as $detalle) {
            $variante = $detalle->variante;

            if (! $variante) {
                continue;
            }

            foreach ($variante->recetas as $receta) {
                $insumo = $receta->insumo;

                if (! $insumo) {
                    continue;
                }

                $grupo = $insumo->grupo_separacion ?: 'varios';
                $monto = (float) $receta->cantidad * (float) $insumo->costo_unitario * (int) $detalle->cantidad;

                $montos[$grupo] = round(($montos[$grupo] ?? 0) + $monto, 2);
            }
        }

        return $montos;
    }

    /**
     * Acumulado vigente de cada grupo (movimientos sin reposición asignada),
     * más la última reposición registrada.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function acumulados(): array
    {
        $pendientes = SeparacionMovimiento::pendientes()
            ->selectRaw('grupo, SUM(monto) as monto, COUNT(DISTINCT venta_id) as ventas')
            ->groupBy('grupo')
            ->get()
            ->keyBy('grupo');

        $ultimas = Reposicion::with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('grupo')
            ->map(fn ($g) => $g->first());

        return collect(Insumo::GRUPOS_SEPARACION)
            ->map(function (string $grupo) use ($pendientes, $ultimas) {
                $fila = $pendientes->get($grupo);
                $ultima = $ultimas->get($grupo);

                return [
                    'grupo' => $grupo,
                    'label' => Insumo::GRUPOS_LABELS[$grupo] ?? $grupo,
                    'monto_acumulado' => round((float) ($fila->monto ?? 0), 2),
                    'cantidad_ventas' => (int) ($fila->ventas ?? 0),
                    'ultima_reposicion' => $ultima ? [
                        'id' => $ultima->id,
                        'fecha' => $ultima->created_at?->toIso8601String(),
                        'monto_acumulado' => (float) $ultima->monto_acumulado,
                        'monto_real' => $ultima->monto_real !== null ? (float) $ultima->monto_real : null,
                        'usuario' => $ultima->user?->name,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Marca un grupo como repuesto: crea la reposición y estampa su id en
     * todos los movimientos huérfanos del grupo, dejando el acumulado en cero
     * sin borrar nada.
     */
    public static function reponer(
        string $grupo,
        ?float $montoReal,
        ?string $observacion,
        int $userId
    ): Reposicion {
        return DB::transaction(function () use ($grupo, $montoReal, $observacion, $userId) {
            $movimientos = SeparacionMovimiento::pendientes()->deGrupo($grupo)->get();

            $reposicion = Reposicion::create([
                'grupo' => $grupo,
                'monto_acumulado' => round((float) $movimientos->sum('monto'), 2),
                'monto_real' => $montoReal,
                'user_id' => $userId,
                'observacion' => $observacion,
            ]);

            if ($movimientos->isNotEmpty()) {
                SeparacionMovimiento::whereIn('id', $movimientos->pluck('id'))
                    ->update(['reposicion_id' => $reposicion->id]);
            }

            return $reposicion;
        });
    }

    /**
     * Historial de reposiciones, más reciente primero.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function historial(int $limite = 50): array
    {
        return Reposicion::with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn (Reposicion $r) => [
                'id' => $r->id,
                'grupo' => $r->grupo,
                'label' => Insumo::GRUPOS_LABELS[$r->grupo] ?? $r->grupo,
                'fecha' => $r->created_at?->toIso8601String(),
                'monto_acumulado' => (float) $r->monto_acumulado,
                'monto_real' => $r->monto_real !== null ? (float) $r->monto_real : null,
                'diferencia' => $r->diferencia,
                'observacion' => $r->observacion,
                'usuario' => $r->user?->name,
            ])
            ->all();
    }
}
