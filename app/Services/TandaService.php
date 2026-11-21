<?php

namespace App\Services;

use App\Models\Caja;
use App\Models\CajaSeparacion;
use App\Models\Insumo;
use App\Models\MovimientoStock;
use App\Models\Receta;
use App\Models\Tanda;
use App\Models\Venta;
use App\Models\VentaInsumo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tandas de compra de la parrilla.
 *
 * La plata de la carne no sale de los gramos de la receta (que cambian con la
 * cocción y con cuánto se carga cada sanguche) sino de lo que se pagó:
 *
 * - Al cargar la compra se dice cuántos sanguches se esperan sacar. Cada
 *   sanguche aparta total ÷ sanguches. Internamente se guarda por unidad de
 *   receta (kg), así una bandeja que lleva otra cantidad aparta lo suyo.
 * - Las ventas toman la tanda abierta más vieja de ese insumo (primero se
 *   termina la carne que entró antes).
 * - Al cerrarla se sabe cuánto rindió de verdad; la diferencia con lo pagado
 *   se suma (o resta) al sobre de la caja en que se cerró, y la próxima
 *   compra usa ese rendimiento para estimar sola.
 */
class TandaService
{
    /** Tanda abierta más vieja del insumo: la que se está vendiendo. */
    public static function activa(int $insumoId): ?Tanda
    {
        return Tanda::abiertas()
            ->where('insumo_id', $insumoId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Cantidad de receta de "un sanguche" de ese insumo: la mayor entre las
     * variantes activas que lo usan (el sanguche, no un agregado).
     */
    public static function cantidadPorPorcion(int $insumoId): float
    {
        $max = (float) Receta::where('insumo_id', $insumoId)
            ->whereHas('variante', fn ($q) => $q->where('activo', true))
            ->max('cantidad');

        return $max > 0 ? $max : 1.0;
    }

    /**
     * Sanguches por kg (o por unidad) que rindió la última tanda cerrada del
     * insumo, o null si todavía no hay ninguna.
     */
    public static function rendimiento(int $insumoId): ?float
    {
        $ultima = Tanda::cerradas()
            ->where('insumo_id', $insumoId)
            ->where('cantidad', '>', 0)
            ->where('cantidad_vendida', '>', 0)
            ->orderByDesc('cerrada_at')
            ->orderByDesc('id')
            ->first();

        if (! $ultima) {
            return null;
        }

        $porciones = (float) $ultima->cantidad_vendida / self::cantidadPorPorcion($insumoId);

        return round($porciones / (float) $ultima->cantidad, 3);
    }

    /** Lo que aparta cada unidad de receta para que los sanguches sumen el total. */
    private static function costoUnitario(int $insumoId, float $total, int $porciones): float
    {
        return round($total / max(1, $porciones) / self::cantidadPorPorcion($insumoId), 2);
    }

    /**
     * @param  array{insumo_id: int, cantidad: float, total: float, porciones_estimadas: int, observacion?: string|null}  $data
     */
    public static function crear(array $data, ?int $userId): Tanda
    {
        return DB::transaction(function () use ($data, $userId) {
            $insumo = Insumo::findOrFail($data['insumo_id']);

            $tanda = Tanda::create([
                'insumo_id' => $insumo->id,
                'negocio' => 'parrilla',
                'cantidad' => $data['cantidad'],
                'total' => $data['total'],
                'porciones_estimadas' => $data['porciones_estimadas'],
                'costo_unitario' => self::costoUnitario($insumo->id, (float) $data['total'], (int) $data['porciones_estimadas']),
                'user_id' => $userId,
                'observacion' => $data['observacion'] ?? null,
            ]);

            // Entra al stock como cualquier compra.
            $anterior = (float) $insumo->stock_actual;
            $insumo->increment('stock_actual', (float) $data['cantidad']);
            MovimientoStock::create([
                'insumo_id' => $insumo->id,
                'user_id' => $userId,
                'tipo' => 'entrada',
                'cantidad' => $data['cantidad'],
                'stock_anterior' => $anterior,
                'stock_nuevo' => $anterior + (float) $data['cantidad'],
                'motivo' => 'compra parrilla (tanda #'.$tanda->id.')',
            ]);


            // Lo vendido hoy antes de cargar la compra (si no tenía tanda)
            // pasa a esta, siempre que la caja no se haya separado todavía.
            $activa = self::activa($insumo->id);

            if (($caja = Caja::abiertaActual()) && $activa) {
                self::filasEditables($caja->id)
                    ->where('insumo_id', $insumo->id)
                    ->whereNull('tanda_id')
                    ->get()
                    ->each(fn (VentaInsumo $f) => $f->update([
                        'tanda_id' => $activa->id,
                        'costo_unitario' => $activa->costo_unitario,
                        'subtotal' => round((float) $f->cantidad * (float) $activa->costo_unitario, 2),
                    ]));
            }

            self::aplicarCosto($insumo);

            return $tanda->fresh();
        });
    }

    /**
     * Corrige una tanda (un número mal cargado). Lo ya separado no se toca;
     * lo pendiente se recalcula con el costo nuevo.
     *
     * @param  array{cantidad?: float, total?: float, porciones_estimadas?: int, observacion?: string|null}  $data
     */
    public static function actualizar(Tanda $tanda, array $data): Tanda
    {
        return DB::transaction(function () use ($tanda, $data) {
            $tanda->fill($data);
            $tanda->costo_unitario = self::costoUnitario($tanda->insumo_id, (float) $tanda->total, (int) $tanda->porciones_estimadas);
            $tanda->save();

            if ($tanda->abierta) {
                $tanda->ventaInsumos()
                    ->whereNull('reposicion_id')
                    ->whereNotIn('caja_id', CajaSeparacion::select('caja_id'))
                    ->get()
                    ->each(fn (VentaInsumo $f) => $f->update([
                        'costo_unitario' => $tanda->costo_unitario,
                        'subtotal' => round((float) $f->cantidad * (float) $tanda->costo_unitario, 2),
                    ]));
            }

            self::aplicarCosto($tanda->insumo);

            return $tanda->fresh();
        });
    }

    /** "Se terminó": congela lo apartado y deja el ajuste en la caja actual. */
    public static function cerrar(Tanda $tanda): Tanda
    {
        return DB::transaction(function () use ($tanda) {
            $filas = $tanda->ventaInsumos();

            $tanda->update([
                'cerrada_at' => now(),
                'caja_cierre_id' => (Caja::abiertaActual() ?? SeparacionService::cajaDeHoy())?->id,
                'apartado' => round((float) (clone $filas)->sum('subtotal'), 2),
                'cantidad_vendida' => round((float) (clone $filas)->sum('cantidad'), 3),
            ]);

            self::aplicarCosto($tanda->insumo);

            return $tanda->fresh();
        });
    }

    /** Deshace un "se terminó" tocado por error. */
    public static function reabrir(Tanda $tanda): Tanda
    {
        $tanda->update([
            'cerrada_at' => null,
            'caja_cierre_id' => null,
            'apartado' => null,
            'cantidad_vendida' => null,
        ]);

        self::aplicarCosto($tanda->insumo);

        return $tanda->fresh();
    }

    /** Solo se borra una tanda sin ventas (una carga equivocada). */
    public static function eliminar(Tanda $tanda): bool
    {
        if ($tanda->ventaInsumos()->exists()) {
            return false;
        }

        DB::transaction(function () use ($tanda) {
            $insumo = $tanda->insumo;
            $anterior = (float) $insumo->stock_actual;
            $nuevo = max(0, $anterior - (float) $tanda->cantidad);
            $insumo->update(['stock_actual' => $nuevo]);
            MovimientoStock::create([
                'insumo_id' => $insumo->id,
                'user_id' => auth()->id(),
                'tipo' => 'ajuste',
                'cantidad' => abs($anterior - $nuevo),
                'stock_anterior' => $anterior,
                'stock_nuevo' => $nuevo,
                'motivo' => 'compra parrilla borrada (tanda #'.$tanda->id.')',
            ]);

            $tanda->delete();
        });

        self::aplicarCosto($insumo);

        return true;
    }

    /**
     * El costo del insumo pasa a ser el de la tanda que se está vendiendo, así
     * el costo de cada sanguche (y la ganancia de la caja) lo refleja.
     */
    private static function aplicarCosto(Insumo $insumo): void
    {
        $activa = self::activa($insumo->id);

        if (! $activa) {
            return;
        }

        $insumo->update(['costo_unitario' => $activa->costo_unitario]);
        $insumo->recetas()->with('variante')->get()->each(fn ($r) => $r->variante?->recalcularCosto());
    }

    /** Filas de una caja que todavía se pueden recalcular (caja sin separar). */
    private static function filasEditables(int $cajaId)
    {
        return VentaInsumo::where('caja_id', $cajaId)
            ->whereNull('reposicion_id')
            ->whereNotIn('caja_id', CajaSeparacion::select('caja_id'));
    }

    // ── Lectura ──────────────────────────────────────────────────────────────

    /**
     * Ajustes de las tandas cerradas en una caja, para sumar al sobre.
     *
     * @return Collection<int, Tanda>
     */
    public static function cerradasEnCaja(int $cajaId, ?string $negocio = null): Collection
    {
        return Tanda::with('insumo')
            ->where('caja_cierre_id', $cajaId)
            ->when($negocio, fn ($q) => $q->where('negocio', $negocio))
            ->get();
    }

    /**
     * Ventas, sanguches y ganancia de un conjunto de tandas, en una sola pasada.
     *
     * @param  Collection<int, Tanda>  $tandas
     * @return array<int, array{ventas: float, porciones: int, otros_insumos: float, apartado: float, cantidad: float}>
     */
    private static function metricas(Collection $tandas): array
    {
        if ($tandas->isEmpty()) {
            return [];
        }

        $filas = VentaInsumo::whereIn('tanda_id', $tandas->pluck('id'))
            ->get(['venta_id', 'tanda_id', 'insumo_id', 'cantidad', 'subtotal']);

        $variantesPorInsumo = Receta::whereIn('insumo_id', $tandas->pluck('insumo_id')->unique())
            ->get(['insumo_id', 'variante_id'])
            ->groupBy('insumo_id')
            ->map(fn ($g) => $g->pluck('variante_id')->all());

        $ventas = Venta::with('detalles')
            ->whereIn('id', $filas->pluck('venta_id')->unique())
            ->where('estado', '!=', 'anulado')
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($filas->groupBy('tanda_id') as $tandaId => $deTanda) {
            $tanda = $tandas->firstWhere('id', $tandaId);
            $variantes = $variantesPorInsumo->get($tanda->insumo_id, []);
            $m = ['ventas' => 0.0, 'porciones' => 0, 'otros_insumos' => 0.0, 'apartado' => 0.0, 'cantidad' => 0.0];

            foreach ($deTanda as $fila) {
                $m['apartado'] += (float) $fila->subtotal;
                $m['cantidad'] += (float) $fila->cantidad;

                $venta = $ventas->get($fila->venta_id);

                if (! $venta) {
                    continue;
                }

                $bruto = (float) $venta->detalles->sum('subtotal');
                $factor = $bruto > 0 ? (float) $venta->total / $bruto : 0.0;
                $costoDetalles = 0.0;

                foreach ($venta->detalles as $d) {
                    if (! in_array($d->variante_id, $variantes, true)) {
                        continue;
                    }

                    $m['ventas'] += (float) $d->subtotal * $factor;
                    $m['porciones'] += (int) $d->cantidad;
                    $costoDetalles += (float) $d->costo_snapshot * (int) $d->cantidad;
                }

                // Pan y demás: el costo del sanguche menos la carne.
                $m['otros_insumos'] += max(0, $costoDetalles - (float) $fila->subtotal);
            }

            $out[(int) $tandaId] = $m;
        }

        return $out;
    }

    /**
     * @param  Collection<int, Tanda>  $tandas
     * @return array<int, array<string, mixed>>
     */
    public static function presentar(Collection $tandas): array
    {
        $tandas->loadMissing('insumo', 'user:id,name', 'cajaCierre:id,fecha_operativa');
        $metricas = self::metricas($tandas);

        return $tandas->map(function (Tanda $t) use ($metricas) {
            $m = $metricas[$t->id] ?? ['ventas' => 0.0, 'porciones' => 0, 'otros_insumos' => 0.0, 'apartado' => 0.0, 'cantidad' => 0.0];
            $total = (float) $t->total;
            $porPorcion = self::cantidadPorPorcion($t->insumo_id);
            $apartado = $t->abierta ? $m['apartado'] : (float) $t->apartado;
            $carne = $t->abierta ? $apartado : $total;
            $ganancia = $m['ventas'] - $carne - $m['otros_insumos'];
            $porciones = $m['porciones'];

            return [
                'id' => $t->id,
                'insumo_id' => $t->insumo_id,
                'insumo' => $t->insumo?->nombre,
                'unidad' => $t->insumo?->unidad,
                'abierta' => $t->abierta,
                'fecha' => $t->created_at?->toIso8601String(),
                'cerrada_at' => $t->cerrada_at?->toIso8601String(),
                'caja_cierre_fecha' => $t->cajaCierre?->fecha_operativa?->toDateString(),
                'usuario' => $t->user?->name,
                'observacion' => $t->observacion,
                'cantidad' => (float) $t->cantidad,
                'total' => $total,
                'precio_unidad' => (float) $t->cantidad > 0 ? round($total / (float) $t->cantidad, 2) : null,
                'porciones_estimadas' => $t->porciones_estimadas,
                'aparta_por_porcion' => round((float) $t->costo_unitario * $porPorcion, 2),
                'porciones_vendidas' => $porciones,
                'apartado' => round($apartado, 2),
                // Abierta: lo que falta juntar. Cerrada: el ajuste que se hizo.
                'falta' => round($total - $apartado, 2),
                'ventas' => round($m['ventas'], 2),
                'otros_insumos' => round($m['otros_insumos'], 2),
                'ganancia' => round($ganancia, 2),
                'ganancia_por_porcion' => $porciones > 0 ? round($ganancia / $porciones, 2) : null,
                'costo_por_porcion' => $porciones > 0 ? round(($carne + $m['otros_insumos']) / $porciones, 2) : null,
                'costo_pct' => $m['ventas'] > 0 ? round(($carne + $m['otros_insumos']) / $m['ventas'] * 100, 1) : null,
                'rendimiento' => ! $t->abierta && (float) $t->cantidad > 0
                    ? round(((float) $t->cantidad_vendida / $porPorcion) / (float) $t->cantidad, 2)
                    : null,
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    public static function abiertas(string $negocio = 'parrilla'): array
    {
        return self::presentar(
            Tanda::abiertas()->where('negocio', $negocio)->orderBy('created_at')->orderBy('id')->get()
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function historial(string $negocio = 'parrilla', int $limite = 40): array
    {
        return self::presentar(
            Tanda::cerradas()->where('negocio', $negocio)->orderByDesc('cerrada_at')->orderByDesc('id')->limit($limite)->get()
        );
    }

    /**
     * Insumos que se pueden cargar como compra de parrilla, con lo que el
     * sistema ya aprendió de cada uno.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function opciones(): array
    {
        $ids = Receta::whereHas('variante.producto.categoria', fn ($q) => $q->where('negocio', 'parrilla'))
            ->pluck('insumo_id')
            ->unique();

        $orden = ['vacio', 'bondiola', 'chinchulin', 'chorizo', 'pan_sanguche'];

        return Insumo::whereIn('id', $ids)
            ->where('activo', true)
            ->get()
            ->sortBy(fn (Insumo $i) => [array_search($i->grupo_separacion, $orden, true) === false ? 99 : array_search($i->grupo_separacion, $orden, true), $i->nombre])
            ->map(function (Insumo $i) {
                $ultima = Tanda::where('insumo_id', $i->id)->orderByDesc('created_at')->orderByDesc('id')->first();

                return [
                    'id' => $i->id,
                    'nombre' => $i->nombre,
                    'unidad' => $i->unidad,
                    'rendimiento' => self::rendimiento($i->id),
                    'ultima_compra' => $ultima ? [
                        'fecha' => $ultima->created_at?->toIso8601String(),
                        'cantidad' => (float) $ultima->cantidad,
                        'total' => (float) $ultima->total,
                        'porciones_estimadas' => $ultima->porciones_estimadas,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }
}
