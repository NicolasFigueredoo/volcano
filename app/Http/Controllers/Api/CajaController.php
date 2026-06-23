<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\DetalleVenta;
use App\Models\GastoFijo;
use App\Models\Variante;
use App\Models\Venta;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CajaController extends Controller
{
    public function hoy(): JsonResponse
    {
        $user = Auth::user();

        $caja = Caja::whereDate('fecha_operativa', Caja::fechaOperativaActual())
            ->with(['abiertaPor:id,name', 'cerradaPor:id,name'])
            ->first();

        $ventas = $caja
            ? Venta::where('caja_id', $caja->id)
                ->with(['detalles', 'pagos', 'user:id,name'])
                ->orderByDesc('created_at')
                ->get()
            : collect();

        $stats = $this->calcularStats($ventas, $user->isAdmin());

        return response()->json([
            'caja' => $caja,
            'stats' => $stats,
            'ventas' => $ventas,
            'es_admin' => $user->isAdmin(),
            'puede_operar_caja' => !$user->isAdmin(),
        ]);
    }

    /**
     * Ventas del día + menú de variantes (para editar ítems si es admin).
     */
    public function ventasHoy(): JsonResponse
    {
        $user = Auth::user();
        $caja = Caja::abiertaActual()
            ?? Caja::whereDate('fecha_operativa', Caja::fechaOperativaActual())->first();

        $ventas = $caja
            ? Venta::where('caja_id', $caja->id)
                ->with(['detalles', 'pagos', 'user:id,name'])
                ->orderByDesc('created_at')
                ->get()
            : collect();

        $menu = $user->isAdmin()
            ? Variante::with('producto:id,nombre,categoria_id')
                ->where('activo', true)
                ->get()
                ->map(fn ($v) => [
                    'id'     => $v->id,
                    'nombre' => $v->producto->nombre . ($v->nombre ? ' — ' . $v->nombre : ''),
                    'precio' => $v->precio_venta,
                    'costo'  => $v->costo_calculado,
                ])
            : [];

        return response()->json([
            'ventas' => $ventas,
            'menu'   => $menu,
        ]);
    }

    public function abrir(): JsonResponse
    {
        $user = Auth::user();

        if ($user->isAdmin()) {
            return response()->json([
                'message' => 'El administrador solo puede visualizar caja.',
            ], 403);
        }

        $fecha = Caja::fechaOperativaActual();

        $cajaExistente = Caja::whereDate('fecha_operativa', $fecha)->first();

        if ($cajaExistente) {
            if ($cajaExistente->estado === 'abierta') {
                return response()->json(
                    $cajaExistente->fresh(['abiertaPor:id,name', 'cerradaPor:id,name'])
                );
            }

            // La caja de hoy ya fue cerrada: la reabrimos en vez de bloquear.
            $cajaExistente->update([
                'estado'      => 'abierta',
                'cerrada_por' => null,
                'cerrada_at'  => null,
            ]);

            return response()->json(
                $cajaExistente->fresh(['abiertaPor:id,name', 'cerradaPor:id,name'])
            );
        }

        $caja = Caja::create([
            'fecha_operativa' => $fecha,
            'estado' => 'abierta',
            'abierta_por' => $user->id,
            'cerrada_por' => null,
            'abierta_at' => now(),
            'cerrada_at' => null,
            'total_ventas' => 0,
            'total_efectivo' => 0,
            'total_transferencia' => 0,
            'costo_insumos' => 0,
            'ganancia_bruta' => 0,
            'gastos_fijos' => 0,
            'ganancia_neta' => 0,
            'cantidad_ventas' => 0,
            'resumen_json' => [],
        ]);

        return response()->json(
            $caja->fresh(['abiertaPor:id,name', 'cerradaPor:id,name']),
            201
        );
    }

    public function cerrar(): JsonResponse
    {
        $user = Auth::user();

        if ($user->isAdmin()) {
            return response()->json([
                'message' => 'El administrador solo puede visualizar caja.',
            ], 403);
        }

        $caja = Caja::abiertaActual();

        if (!$caja) {
            return response()->json([
                'message' => 'No hay una caja abierta.',
            ], 422);
        }

        $this->cerrarConTotales($caja, $user->id);

        return response()->json(
            $caja->fresh(['abiertaPor:id,name', 'cerradaPor:id,name'])
        );
    }

    public function cerrarManual(Caja $caja): JsonResponse
    {
        $user = Auth::user();

        if (!$user->isAdmin()) {
            return response()->json([
                'message' => 'Solo el administrador puede cerrar cajas manualmente.',
            ], 403);
        }

        if ($caja->estado !== 'abierta') {
            return response()->json([
                'message' => 'La caja ya está cerrada.',
            ], 422);
        }

        $this->cerrarConTotales($caja, $user->id, fallbackPorFecha: true);

        return response()->json(
            $caja->fresh(['abiertaPor:id,name', 'cerradaPor:id,name'])
        );
    }

    /**
     * Editar una caja manualmente (totales, estado, fecha operativa). Solo admin.
     */
    public function update(Request $request, Caja $caja): JsonResponse
    {
        $user = Auth::user();

        if (!$user->isAdmin()) {
            return response()->json([
                'message' => 'Solo el administrador puede editar cajas.',
            ], 403);
        }

        $data = $request->validate([
            'fecha_operativa'     => 'sometimes|date',
            'estado'              => 'sometimes|in:abierta,cerrada',
            'total_ventas'        => 'sometimes|numeric',
            'total_efectivo'      => 'sometimes|numeric',
            'total_transferencia' => 'sometimes|numeric',
            'costo_insumos'       => 'sometimes|numeric',
            'ganancia_bruta'      => 'sometimes|numeric',
            'gastos_fijos'        => 'sometimes|numeric',
            'ganancia_neta'       => 'sometimes|numeric',
            'cantidad_ventas'     => 'sometimes|integer',
        ]);

        if (($data['estado'] ?? null) === 'cerrada' && !$caja->cerrada_at) {
            $data['cerrada_at']  = now();
            $data['cerrada_por'] = $user->id;
        }

        if (($data['estado'] ?? null) === 'abierta') {
            $data['cerrada_at']  = null;
            $data['cerrada_por'] = null;
        }

        $caja->update($data);

        return response()->json(
            $caja->fresh(['abiertaPor:id,name', 'cerradaPor:id,name'])
        );
    }

    /**
     * Eliminar una caja. Las ventas asociadas quedan sueltas (caja_id null).
     */
    public function destroy(Caja $caja): JsonResponse
    {
        $user = Auth::user();

        if (!$user->isAdmin()) {
            return response()->json([
                'message' => 'Solo el administrador puede eliminar cajas.',
            ], 403);
        }

        Venta::where('caja_id', $caja->id)->update(['caja_id' => null]);

        $caja->delete();

        return response()->json(['message' => 'Caja eliminada correctamente.']);
    }

    public function historial(Request $request): JsonResponse
    {
        $desde = $request->get('desde');
        $hasta = $request->get('hasta');

        $cajas = Caja::with(['abiertaPor:id,name', 'cerradaPor:id,name'])
            ->when($desde, fn ($q) => $q->whereDate('fecha_operativa', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('fecha_operativa', '<=', $hasta))
            ->orderByDesc('fecha_operativa')
            ->get();

        return response()->json($cajas);
    }

    public function show(Caja $caja): JsonResponse
    {
        $user = Auth::user();

        if (!$user->isAdmin()) {
            return response()->json([
                'message' => 'Solo el administrador puede ver cajas guardadas.',
            ], 403);
        }

        $caja->load(['abiertaPor:id,name', 'cerradaPor:id,name']);

        $ventas = Venta::where('caja_id', $caja->id)
            ->with(['detalles', 'pagos', 'user:id,name'])
            ->orderByDesc('created_at')
            ->get();

        if ($ventas->isEmpty() && (int) $caja->cantidad_ventas > 0) {
            $fechaOperativa = Carbon::parse($caja->fecha_operativa)->toDateString();

            $ventas = Venta::whereDate('created_at', $fechaOperativa)
                ->with(['detalles', 'pagos', 'user:id,name'])
                ->orderByDesc('created_at')
                ->get();
        }

        if ($caja->estado === 'abierta' || ((int) $caja->total_ventas === 0 && $ventas->isNotEmpty())) {
            $statsCalculadas = $this->calcularStats($ventas, true);

            return response()->json([
                'caja' => $caja,
                'ventas' => $ventas,
                'stats_guardadas' => [
                    'cantidad_ventas'     => $statsCalculadas['cantidad_ventas'],
                    'total_monto'         => $statsCalculadas['total_monto'],
                    'total_efectivo'      => $statsCalculadas['total_efectivo'],
                    'total_transferencia' => $statsCalculadas['total_transferencia'],
                    'costo_insumos'       => $statsCalculadas['costo_insumos'] ?? 0,
                    'ganancia_bruta'      => $statsCalculadas['ganancia_bruta'] ?? 0,
                    'gastos_fijos'        => $statsCalculadas['gastos_fijos'] ?? 0,
                    'ganancia_neta'       => $statsCalculadas['ganancia_neta'] ?? 0,
                ],
                'resumen' => $caja->resumen_json ?? [],
            ]);
        }

        return response()->json([
            'caja' => $caja,
            'ventas' => $ventas,
            'stats_guardadas' => [
                'cantidad_ventas'     => $caja->cantidad_ventas,
                'total_monto'         => $caja->total_ventas,
                'total_efectivo'      => $caja->total_efectivo,
                'total_transferencia' => $caja->total_transferencia,
                'costo_insumos'       => $caja->costo_insumos,
                'ganancia_bruta'      => $caja->ganancia_bruta,
                'gastos_fijos'        => $caja->gastos_fijos,
                'ganancia_neta'       => $caja->ganancia_neta,
            ],
            'resumen' => $caja->resumen_json ?? [],
        ]);
    }

    public function resumenSemanal(Request $request): JsonResponse
    {
        $base = $request->get('fecha')
            ? Carbon::parse($request->get('fecha'))
            : now();

        $jueves = $base->copy()->startOfWeek()->addDays(3);
        $domingo = $jueves->copy()->addDays(3);

        if ($base->lt($jueves)) {
            $jueves->subWeek();
            $domingo->subWeek();
        }

        $cajas = Caja::whereBetween('fecha_operativa', [
                $jueves->toDateString(),
                $domingo->toDateString(),
            ])
            ->orderBy('fecha_operativa')
            ->get();

        $totales = $this->totalesDeCajas($cajas);

        return response()->json([
            'desde'               => $jueves->toDateString(),
            'hasta'               => $domingo->toDateString(),
            'total_ventas'        => $totales->sum('total_ventas'),
            'total_efectivo'      => $totales->sum('total_efectivo'),
            'total_transferencia' => $totales->sum('total_transferencia'),
            'costo_insumos'       => $totales->sum('costo_insumos'),
            'ganancia_bruta'      => $totales->sum('ganancia_bruta'),
            'gastos_fijos'        => $totales->sum('gastos_fijos'),
            'ganancia_neta'       => $totales->sum('ganancia_neta'),
            'cantidad_ventas'     => $totales->sum('cantidad_ventas'),
            'cajas'               => $cajas,
        ]);
    }

    public function resumenPorRango(Request $request): JsonResponse
    {
        $desde = $request->get('desde')
            ? Carbon::parse($request->get('desde'))->toDateString()
            : now()->subDays(7)->toDateString();

        $hasta = $request->get('hasta')
            ? Carbon::parse($request->get('hasta'))->toDateString()
            : now()->toDateString();

        $cajas = Caja::whereBetween('fecha_operativa', [$desde, $hasta])
            ->orderBy('fecha_operativa')
            ->get();

        $totales = $this->totalesDeCajas($cajas);

        return response()->json([
            'desde'               => $desde,
            'hasta'               => $hasta,
            'total_ventas'        => $totales->sum('total_ventas'),
            'total_efectivo'      => $totales->sum('total_efectivo'),
            'total_transferencia' => $totales->sum('total_transferencia'),
            'costo_insumos'       => $totales->sum('costo_insumos'),
            'ganancia_bruta'      => $totales->sum('ganancia_bruta'),
            'gastos_fijos'        => $totales->sum('gastos_fijos'),
            'ganancia_neta'       => $totales->sum('ganancia_neta'),
            'cantidad_ventas'     => $totales->sum('cantidad_ventas'),
            'cajas'               => $cajas,
        ]);
    }

    public function pedidosActivos(): JsonResponse
    {
        $caja = Caja::abiertaActual();

        if (!$caja) {
            return response()->json([]);
        }

        $pedidos = Venta::where('caja_id', $caja->id)
            ->activos()
            ->with(['detalles', 'pagos'])
            ->orderBy('created_at')
            ->get();

        return response()->json($pedidos);
    }

    public function alertasCompra(): JsonResponse
    {
        $insumos = \App\Models\Insumo::activo()
            ->bajoStock()
            ->get()
            ->map(fn ($i) => [
                'nombre'       => $i->nombre,
                'unidad'       => $i->unidad,
                'stock_actual' => $i->stock_actual,
                'stock_minimo' => $i->stock_minimo,
                'estado'       => $i->estado_stock,
            ]);

        return response()->json($insumos);
    }

    /**
     * Cambiar solo el estado de una venta. Cajero y admin.
     */
    public function actualizarEstadoVenta(Request $request, Venta $venta): JsonResponse
    {
        $request->validate([
            'estado' => 'required|in:pendiente,preparacion,pagado,entregado,anulado',
        ]);

        $venta->update(['estado' => $request->estado]);

        if ($venta->caja_id) {
            $caja = Caja::find($venta->caja_id);
            if ($caja) {
                $this->recalcularTotalesSolo($caja);
            }
        }

        return response()->json($venta->fresh(['detalles', 'pagos', 'user:id,name']));
    }

    /**
     * Editar ítems, cantidades y nota de una venta. Solo admin.
     *
     * Body esperado:
     * {
     *   "notas": "sin cebolla",
     *   "items": [
     *     { "variante_id": 3, "cantidad": 2 },
     *     { "variante_id": 7, "cantidad": 1 }
     *   ]
     * }
     */
    public function editarVenta(Request $request, Venta $venta): JsonResponse
    {
        $user = Auth::user();

        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Solo el administrador puede editar ventas.'], 403);
        }

        if ($venta->estado === 'anulado') {
            return response()->json(['message' => 'No se puede editar una venta anulada.'], 422);
        }

        $data = $request->validate([
            'notas'                => 'nullable|string|max:500',
            'items'                => 'required|array|min:1',
            'items.*.variante_id'  => 'required|integer|exists:variantes,id',
            'items.*.cantidad'     => 'required|integer|min:1',
        ]);

        \DB::transaction(function () use ($venta, $data) {
            $venta->detalles()->delete();

            $subtotal = 0;

            foreach ($data['items'] as $item) {
                $variante = Variante::with('producto')->find($item['variante_id']);

                $itemSubtotal = $variante->precio_venta * $item['cantidad'];
                $subtotal += $itemSubtotal;

                DetalleVenta::create([
                    'venta_id'        => $venta->id,
                    'variante_id'     => $variante->id,
                    'nombre_snapshot' => $variante->producto->nombre . ($variante->nombre ? ' — ' . $variante->nombre : ''),
                    'precio_snapshot' => $variante->precio_venta,
                    'costo_snapshot'  => $variante->costo_calculado,
                    'cantidad'        => $item['cantidad'],
                    'subtotal'        => $itemSubtotal,
                ]);
            }

            $total = $subtotal - ($venta->descuento ?? 0);

            $venta->update([
                'subtotal' => $subtotal,
                'total'    => $total,
                'notas'    => $data['notas'] ?? $venta->notas,
            ]);
        });

        if ($venta->caja_id) {
            $caja = Caja::find($venta->caja_id);
            if ($caja) {
                $this->recalcularTotalesSolo($caja);
            }
        }

        return response()->json($venta->fresh(['detalles', 'pagos', 'user:id,name']));
    }

    /**
     * Anular una venta (no se borra, queda marcada como 'anulado' y se
     * excluye de todos los totales). Solo admin.
     */
    public function anularVenta(Venta $venta): JsonResponse
    {
        $user = Auth::user();

        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Solo el administrador puede anular ventas.'], 403);
        }

        if ($venta->estado === 'anulado') {
            return response()->json(['message' => 'La venta ya está anulada.'], 422);
        }

        $venta->update(['estado' => 'anulado']);

        if ($venta->caja_id) {
            $caja = Caja::find($venta->caja_id);
            if ($caja) {
                $this->recalcularTotalesSolo($caja);
            }
        }

        return response()->json(['message' => 'Venta anulada correctamente.']);
    }

    /**
     * Cierra una caja recalculando totales desde sus ventas (excluyendo anuladas).
     */
    private function cerrarConTotales(Caja $caja, int $cerradaPor, bool $fallbackPorFecha = false): void
    {
        $ventas = Venta::where('caja_id', $caja->id)
            ->with(['detalles', 'pagos'])
            ->get();

        if ($fallbackPorFecha && $ventas->isEmpty()) {
            $fechaOperativa = Carbon::parse($caja->fecha_operativa)->toDateString();

            $ventas = Venta::whereDate('created_at', $fechaOperativa)
                ->with(['detalles', 'pagos'])
                ->get();
        }

        $stats = $this->calcularStats($ventas, true);

        $productosTop = $ventas->where('estado', '!=', 'anulado')
            ->flatMap->detalles
            ->groupBy('nombre_snapshot')
            ->map(fn ($g) => [
                'nombre'   => $g->first()->nombre_snapshot,
                'cantidad' => $g->sum('cantidad'),
                'monto'    => $g->sum('subtotal'),
            ])
            ->sortByDesc('cantidad')
            ->values()
            ->take(5)
            ->toArray();

        $caja->update([
            'estado'              => 'cerrada',
            'cerrada_por'         => $cerradaPor,
            'cerrada_at'          => now(),
            'total_ventas'        => $stats['total_monto'],
            'total_efectivo'      => $stats['total_efectivo'],
            'total_transferencia' => $stats['total_transferencia'],
            'costo_insumos'       => $stats['costo_insumos'] ?? 0,
            'ganancia_bruta'      => $stats['ganancia_bruta'] ?? 0,
            'gastos_fijos'        => $stats['gastos_fijos'] ?? 0,
            'ganancia_neta'       => $stats['ganancia_neta'] ?? 0,
            'cantidad_ventas'     => $stats['cantidad_ventas'],
            'resumen_json'        => ['productos_top' => $productosTop],
        ]);
    }

    /**
     * Recalcula solo los totales (sin tocar estado/resumen). Se usa después
     * de editar/anular una venta que pertenece a una caja ya existente.
     */
    private function recalcularTotalesSolo(Caja $caja): void
    {
        $ventas = Venta::where('caja_id', $caja->id)
            ->with(['detalles', 'pagos'])
            ->get();

        $stats = $this->calcularStats($ventas, true);

        $caja->update([
            'total_ventas'        => $stats['total_monto'],
            'total_efectivo'      => $stats['total_efectivo'],
            'total_transferencia' => $stats['total_transferencia'],
            'costo_insumos'       => $stats['costo_insumos'] ?? 0,
            'ganancia_bruta'      => $stats['ganancia_bruta'] ?? 0,
            'gastos_fijos'        => $stats['gastos_fijos'] ?? 0,
            'ganancia_neta'       => $stats['ganancia_neta'] ?? 0,
            'cantidad_ventas'     => $stats['cantidad_ventas'],
        ]);
    }

    private function totalesDeCajas($cajas)
    {
        return $cajas->map(function ($caja) {
            if ($caja->estado === 'abierta' || (int) $caja->total_ventas === 0) {
                $ventas = Venta::where('caja_id', $caja->id)->with(['detalles', 'pagos'])->get();
                $stats = $this->calcularStats($ventas, true);
                return [
                    'total_ventas'        => $stats['total_monto'],
                    'total_efectivo'      => $stats['total_efectivo'],
                    'total_transferencia' => $stats['total_transferencia'],
                    'costo_insumos'       => $stats['costo_insumos'] ?? 0,
                    'ganancia_bruta'      => $stats['ganancia_bruta'] ?? 0,
                    'gastos_fijos'        => $stats['gastos_fijos'] ?? 0,
                    'ganancia_neta'       => $stats['ganancia_neta'] ?? 0,
                    'cantidad_ventas'     => $stats['cantidad_ventas'],
                ];
            }
            return [
                'total_ventas'        => $caja->total_ventas,
                'total_efectivo'      => $caja->total_efectivo,
                'total_transferencia' => $caja->total_transferencia,
                'costo_insumos'       => $caja->costo_insumos,
                'ganancia_bruta'      => $caja->ganancia_bruta,
                'gastos_fijos'        => $caja->gastos_fijos,
                'ganancia_neta'       => $caja->ganancia_neta,
                'cantidad_ventas'     => $caja->cantidad_ventas,
            ];
        });
    }

    /**
     * Calcula stats a partir de una colección de ventas, excluyendo
     * siempre las anuladas.
     */
    private function calcularStats($ventas, bool $conAdmin = false): array
    {
        $ventas = $ventas->where('estado', '!=', 'anulado');

        $efectivo = $ventas->flatMap->pagos
            ->where('metodo', 'efectivo')
            ->sum('monto');

        $transferencia = $ventas->flatMap->pagos
            ->where('metodo', 'transferencia')
            ->sum('monto');

        $total = $ventas->sum('total');

        $stats = [
            'cantidad_ventas'     => $ventas->count(),
            'total_monto'         => $total,
            'total_efectivo'      => $efectivo,
            'total_transferencia' => $transferencia,
        ];

        if ($conAdmin) {
            $costoInsumos = $ventas->flatMap->detalles
                ->sum(fn ($d) => $d->costo_snapshot * $d->cantidad);

            $gananciaBruta = $total - $costoInsumos;
            $gastosDia     = GastoFijo::totalDiario();
            $gananciaNeta  = $gananciaBruta - $gastosDia;

            $stats['costo_insumos']  = $costoInsumos;
            $stats['ganancia_bruta'] = $gananciaBruta;
            $stats['gastos_fijos']   = $gastosDia;
            $stats['ganancia_neta']  = $gananciaNeta;
            $stats['separacion']     = [
                'reponer_insumos' => $costoInsumos,
                'ahorro'          => max(0, round($gananciaNeta * 0.10)),
                'retiro'          => max(0, round($gananciaNeta * 0.40)),
                'negocio'         => max(0, round($gananciaNeta * 0.50)),
            ];
        }

        return $stats;
    }
}