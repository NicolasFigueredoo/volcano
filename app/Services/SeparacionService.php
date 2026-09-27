<?php

namespace App\Services;

use App\Models\Caja;
use App\Models\CajaSeparacion;
use App\Models\Insumo;
use App\Models\Proveedor;
use App\Models\Reposicion;
use App\Models\Venta;
use App\Models\VentaInsumo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SeparacionService
{
    /**
     * Mantiene el snapshot de insumos (venta_insumos) de una venta.
     *
     * - Venta anulada: se borran sus filas, salvo las que ya se liquidaron en
     *   una reposición (quedan congeladas para no romper el histórico).
     * - Resto de los estados: si todavía no tiene filas se generan con la
     *   receta y el costo vigentes. Si ya las tiene no se tocan, así un cambio
     *   posterior de receta o costo no mueve lo histórico.
     *
     * Es idempotente: llamarla N veces deja siempre el mismo estado.
     */
    public static function sincronizar(Venta $venta): void
    {
        if ($venta->estado === 'anulado') {
            $venta->insumos()->whereNull('reposicion_id')->delete();

            return;
        }

        if ($venta->insumos()->exists()) {
            return;
        }

        self::generar($venta);
    }

    /**
     * Vuelve a armar el snapshot desde los detalles actuales de la venta (se
     * usa al editar sus items o al "des-anularla"). Lo ya liquidado en una
     * reposición se respeta y solo se agrega la diferencia.
     */
    public static function regenerar(Venta $venta): void
    {
        DB::transaction(function () use ($venta) {
            $venta->insumos()->whereNull('reposicion_id')->delete();

            if ($venta->estado === 'anulado') {
                return;
            }

            self::generar($venta);
        });
    }

    /**
     * Expande cada detalle por la receta de su variante. Los insumos con
     * descuenta_stock = false (aceite, garrafa, descartables) entran igual,
     * prorrateados por su costo_unitario como en Variante::recalcularCosto().
     */
    private static function generar(Venta $venta): void
    {
        $venta->load('detalles.variante.recetas.insumo');

        $lineas = [];

        foreach ($venta->detalles as $detalle) {
            foreach ($detalle->variante?->recetas ?? [] as $receta) {
                $insumo = $receta->insumo;

                if (! $insumo) {
                    continue;
                }

                $lineas[$insumo->id] ??= [
                    'insumo' => $insumo,
                    'cantidad' => 0.0,
                ];

                $lineas[$insumo->id]['cantidad'] += (float) $receta->cantidad * (int) $detalle->cantidad;
            }
        }

        // Cantidades ya liquidadas en una reposición (solo al regenerar).
        $liquidadas = $venta->insumos()
            ->whereNotNull('reposicion_id')
            ->selectRaw('insumo_id, SUM(cantidad) as cantidad')
            ->groupBy('insumo_id')
            ->pluck('cantidad', 'insumo_id');

        foreach ($lineas as $insumoId => $linea) {
            $cantidad = round($linea['cantidad'] - (float) ($liquidadas[$insumoId] ?? 0), 3);

            if ($cantidad <= 0) {
                continue;
            }

            $insumo = $linea['insumo'];
            $costo = (float) $insumo->costo_unitario;

            VentaInsumo::create([
                'venta_id' => $venta->id,
                'caja_id' => $venta->caja_id,
                'insumo_id' => $insumo->id,
                'grupo_separacion' => $insumo->grupo_separacion ?: 'varios',
                'cantidad' => $cantidad,
                'costo_unitario' => $costo,
                'subtotal' => round($cantidad * $costo, 2),
            ]);
        }
    }

    /**
     * Filas de venta_insumos que forman el fondo de los rubros sin proveedor:
     * desde el corte, sin reposición asignada y de insumos sin proveedor.
     */
    private static function pendientesSinProveedor(): Builder
    {
        return VentaInsumo::desdeCorte()
            ->pendientes()
            ->whereIn('venta_insumos.insumo_id', Insumo::query()->select('id')->whereNull('proveedor_id'));
    }

    /**
     * Acumulado vigente de cada rubro sin proveedor, más la última reposición
     * registrada. Los rubros cubiertos por completo por proveedores no se
     * listan (se ven en sus tarjetas de proveedor).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function acumulados(): array
    {
        $pendientes = self::pendientesSinProveedor()
            ->selectRaw('grupo_separacion as grupo, SUM(subtotal) as monto, COUNT(DISTINCT venta_id) as ventas')
            ->groupBy('grupo_separacion')
            ->get()
            ->keyBy('grupo');

        $gruposSinProveedor = Insumo::whereNull('proveedor_id')
            ->distinct()
            ->pluck('grupo_separacion')
            ->all();

        $ultimas = Reposicion::with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('grupo')
            ->map(fn ($g) => $g->first());

        return collect(Insumo::GRUPOS_SEPARACION)
            ->filter(fn (string $grupo) => in_array($grupo, $gruposSinProveedor, true) || $pendientes->has($grupo))
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
     * Marca un rubro como repuesto: crea la reposición y estampa su id en
     * todas las filas pendientes del rubro, dejando el acumulado en cero sin
     * borrar nada.
     */
    public static function reponer(
        string $grupo,
        ?float $montoReal,
        ?string $observacion,
        int $userId
    ): Reposicion {
        return DB::transaction(function () use ($grupo, $montoReal, $observacion, $userId) {
            $filas = self::pendientesSinProveedor()
                ->where('grupo_separacion', $grupo)
                ->get(['venta_insumos.id', 'venta_insumos.subtotal']);

            $reposicion = Reposicion::create([
                'grupo' => $grupo,
                'monto_acumulado' => round((float) $filas->sum('subtotal'), 2),
                'monto_real' => $montoReal,
                'user_id' => $userId,
                'observacion' => $observacion,
            ]);

            if ($filas->isNotEmpty()) {
                VentaInsumo::whereIn('id', $filas->pluck('id'))
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

    // ── Vista diaria / por caja ──────────────────────────────────────────────

    /**
     * Caja que se muestra como "Hoy": la abierta, o si no hay, la última.
     */
    public static function cajaDeHoy(): ?Caja
    {
        return Caja::abiertaActual() ?? Caja::orderByDesc('fecha_operativa')->orderByDesc('id')->first();
    }

    /**
     * Unidades y monto por insumo de un conjunto de filas de venta_insumos.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function unidadesPorInsumo(Builder $filas): array
    {
        $totales = (clone $filas)
            ->selectRaw('venta_insumos.insumo_id, SUM(venta_insumos.cantidad) as cantidad, SUM(venta_insumos.subtotal) as monto')
            ->groupBy('venta_insumos.insumo_id')
            ->get();

        $insumos = Insumo::whereIn('id', $totales->pluck('insumo_id'))->get()->keyBy('id');

        return $totales
            ->map(function ($fila) use ($insumos) {
                $insumo = $insumos->get($fila->insumo_id);
                $cantidad = round((float) $fila->cantidad, 3);
                $equivalencia = (float) ($insumo?->equivalencia_compra ?? 0);

                return [
                    'insumo_id' => (int) $fila->insumo_id,
                    'nombre' => $insumo?->nombre,
                    'grupo' => $insumo?->grupo_separacion ?: 'varios',
                    'proveedor_id' => $insumo?->proveedor_id,
                    'unidad' => $insumo?->unidad,
                    'cantidad' => $cantidad,
                    'monto' => round((float) $fila->monto, 2),
                    'unidad_compra' => $equivalencia > 0 ? $insumo->unidad_compra : null,
                    'cantidad_compra' => $equivalencia > 0 ? round($cantidad / $equivalencia, 3) : null,
                ];
            })
            ->sortByDesc('monto')
            ->values()
            ->all();
    }

    /**
     * Unidades vendidas y montos por rubro/insumo de una caja.
     *
     * @return array<string, mixed>
     */
    public static function resumenCaja(Caja $caja): array
    {
        $insumos = collect(self::unidadesPorInsumo(VentaInsumo::where('venta_insumos.caja_id', $caja->id)));

        $grupos = $insumos
            ->groupBy('grupo')
            ->map(fn ($filas, $grupo) => [
                'grupo' => $grupo,
                'label' => Insumo::GRUPOS_LABELS[$grupo] ?? $grupo,
                'monto' => round((float) $filas->sum('monto'), 2),
                'insumos' => $filas->values()->all(),
            ])
            ->sortBy(fn ($g) => array_search($g['grupo'], Insumo::GRUPOS_SEPARACION, true))
            ->values()
            ->all();

        return [
            'caja' => [
                'id' => $caja->id,
                'fecha_operativa' => $caja->fecha_operativa?->toDateString(),
                'estado' => $caja->estado,
            ],
            'grupos' => $grupos,
            'total' => round((float) $insumos->sum('monto'), 2),
        ];
    }

    /**
     * Líneas sugeridas para separar la plata de una caja: una por proveedor
     * (sus insumos) y una por rubro para los insumos sin proveedor.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lineasSeparacion(Caja $caja): array
    {
        $insumos = collect(self::unidadesPorInsumo(VentaInsumo::where('venta_insumos.caja_id', $caja->id)));

        $proveedores = Proveedor::whereIn('id', $insumos->pluck('proveedor_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $conProveedor = $insumos
            ->whereNotNull('proveedor_id')
            ->groupBy('proveedor_id')
            ->map(fn ($filas, $proveedorId) => [
                'tipo' => 'proveedor',
                'proveedor_id' => (int) $proveedorId,
                'grupo' => null,
                'label' => $proveedores->get($proveedorId)?->nombre ?? 'Proveedor',
                'monto' => round((float) $filas->sum('monto'), 2),
                'insumos' => $filas->values()->all(),
            ]);

        $sinProveedor = $insumos
            ->whereNull('proveedor_id')
            ->groupBy('grupo')
            ->map(fn ($filas, $grupo) => [
                'tipo' => 'rubro',
                'proveedor_id' => null,
                'grupo' => $grupo,
                'label' => Insumo::GRUPOS_LABELS[$grupo] ?? $grupo,
                'monto' => round((float) $filas->sum('monto'), 2),
                'insumos' => $filas->values()->all(),
            ])
            ->sortBy(fn ($l) => array_search($l['grupo'], Insumo::GRUPOS_SEPARACION, true));

        return $conProveedor->values()->concat($sinProveedor->values())->all();
    }

    /**
     * Separación ya confirmada de una caja, o null.
     *
     * @return array<string, mixed>|null
     */
    public static function separacionDeCaja(Caja $caja): ?array
    {
        $separacion = CajaSeparacion::with('user:id,name')->where('caja_id', $caja->id)->first();

        if (! $separacion) {
            return null;
        }

        return [
            'id' => $separacion->id,
            'fecha' => $separacion->created_at?->toIso8601String(),
            'total' => (float) $separacion->total,
            'detalle' => $separacion->detalle,
            'usuario' => $separacion->user?->name,
        ];
    }
}
