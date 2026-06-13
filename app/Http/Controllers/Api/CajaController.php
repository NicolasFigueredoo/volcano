<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\GastoFijo;
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

            return response()->json([
                'message' => 'La caja de hoy ya fue cerrada.',
            ], 422);
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

        $ventas = Venta::where('caja_id', $caja->id)
            ->with(['detalles', 'pagos'])
            ->get();

        $stats = $this->calcularStats($ventas, true);

        $productosTop = $ventas->flatMap->detalles
            ->groupBy('nombre_snapshot')
            ->map(fn ($g) => [
                'nombre' => $g->first()->nombre_snapshot,
                'cantidad' => $g->sum('cantidad'),
                'monto' => $g->sum('subtotal'),
            ])
            ->sortByDesc('cantidad')
            ->values()
            ->take(5)
            ->toArray();

        $caja->update([
            'estado' => 'cerrada',
            'cerrada_por' => $user->id,
            'cerrada_at' => now(),
            'total_ventas' => $stats['total_monto'],
            'total_efectivo' => $stats['total_efectivo'],
            'total_transferencia' => $stats['total_transferencia'],
            'costo_insumos' => $stats['costo_insumos'] ?? 0,
            'ganancia_bruta' => $stats['ganancia_bruta'] ?? 0,
            'gastos_fijos' => $stats['gastos_fijos'] ?? 0,
            'ganancia_neta' => $stats['ganancia_neta'] ?? 0,
            'cantidad_ventas' => $stats['cantidad_ventas'],
            'resumen_json' => [
                'productos_top' => $productosTop,
            ],
        ]);

        return response()->json(
            $caja->fresh(['abiertaPor:id,name', 'cerradaPor:id,name'])
        );
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

        /*
         * Fallback para cajas viejas:
         * si la caja tiene cantidad_ventas pero las ventas todavía no quedaron asociadas
         * por caja_id, intenta recuperar lo facturado por fecha operativa.
         */
        if ($ventas->isEmpty() && (int) $caja->cantidad_ventas > 0) {
            $fechaOperativa = Carbon::parse($caja->fecha_operativa)->toDateString();

            $ventas = Venta::whereDate('created_at', $fechaOperativa)
                ->with(['detalles', 'pagos', 'user:id,name'])
                ->orderByDesc('created_at')
                ->get();
        }

        return response()->json([
            'caja' => $caja,
            'ventas' => $ventas,
            'stats_guardadas' => [
                'cantidad_ventas' => $caja->cantidad_ventas,
                'total_monto' => $caja->total_ventas,
                'total_efectivo' => $caja->total_efectivo,
                'total_transferencia' => $caja->total_transferencia,
                'costo_insumos' => $caja->costo_insumos,
                'ganancia_bruta' => $caja->ganancia_bruta,
                'gastos_fijos' => $caja->gastos_fijos,
                'ganancia_neta' => $caja->ganancia_neta,
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

        return response()->json([
            'desde' => $jueves->toDateString(),
            'hasta' => $domingo->toDateString(),
            'total_ventas' => $cajas->sum('total_ventas'),
            'total_efectivo' => $cajas->sum('total_efectivo'),
            'total_transferencia' => $cajas->sum('total_transferencia'),
            'costo_insumos' => $cajas->sum('costo_insumos'),
            'ganancia_bruta' => $cajas->sum('ganancia_bruta'),
            'gastos_fijos' => $cajas->sum('gastos_fijos'),
            'ganancia_neta' => $cajas->sum('ganancia_neta'),
            'cantidad_ventas' => $cajas->sum('cantidad_ventas'),
            'cajas' => $cajas,
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
                'nombre' => $i->nombre,
                'unidad' => $i->unidad,
                'stock_actual' => $i->stock_actual,
                'stock_minimo' => $i->stock_minimo,
                'estado' => $i->estado_stock,
            ]);

        return response()->json($insumos);
    }

    private function calcularStats($ventas, bool $conAdmin = false): array
    {
        $efectivo = $ventas->flatMap->pagos
            ->where('metodo', 'efectivo')
            ->sum('monto');

        $transferencia = $ventas->flatMap->pagos
            ->where('metodo', 'transferencia')
            ->sum('monto');

        $total = $ventas->sum('total');

        $stats = [
            'cantidad_ventas' => $ventas->count(),
            'total_monto' => $total,
            'total_efectivo' => $efectivo,
            'total_transferencia' => $transferencia,
        ];

        if ($conAdmin) {
            $costoInsumos = $ventas->flatMap->detalles
                ->sum(fn ($d) => $d->costo_snapshot * $d->cantidad);

            $gananciaBruta = $total - $costoInsumos;
            $gastosDia = GastoFijo::totalDiario();
            $gananciaNeta = $gananciaBruta - $gastosDia;

            $stats['costo_insumos'] = $costoInsumos;
            $stats['ganancia_bruta'] = $gananciaBruta;
            $stats['gastos_fijos'] = $gastosDia;
            $stats['ganancia_neta'] = $gananciaNeta;
            $stats['separacion'] = [
                'reponer_insumos' => $costoInsumos,
                'ahorro' => max(0, round($gananciaNeta * 0.10)),
                'retiro' => max(0, round($gananciaNeta * 0.40)),
                'negocio' => max(0, round($gananciaNeta * 0.50)),
            ];
        }

        return $stats;
    }

     public function ventasHoy(): JsonResponse
    {
        $user = Auth::user();
        $caja = Caja::abiertaActual();
 
        // Si no hay caja abierta, buscar la del día operativo actual de todos modos
        $cajaHoy = $caja ?? Caja::whereDate('fecha_operativa', Caja::fechaOperativaActual())->first();
 
        $ventas = $cajaHoy
            ? Venta::where('caja_id', $cajaHoy->id)
                ->with(['detalles.variante', 'pagos', 'user:id,name'])
                ->orderByDesc('created_at')
                ->get()
            : collect();
 
        // Menú para el modal de edición (solo admin puede editar ítems)
        $menu = $user->isAdmin()
            ? \App\Models\Variante::with('producto:id,nombre,categoria_id')
                ->where('activo', true)
                ->get()
                ->map(fn ($v) => [
                    'id'     => $v->id,
                    'nombre' => $v->producto->nombre . ($v->nombre ? ' — ' . $v->nombre : ''),
                    'precio' => $v->precio,
                    'costo'  => $v->costo,
                ])
            : [];
 
        return response()->json([
            'ventas' => $ventas,
            'menu'   => $menu,
        ]);
    }
 
    /**
     * Cambiar solo el estado de una venta.
     * Accesible por cajero y admin.
     */
    public function actualizarEstadoVenta(Request $request, Venta $venta): JsonResponse
    {
        $request->validate([
            'estado' => 'required|in:pendiente,preparacion,pagado,entregado,anulado',
        ]);
 
        $venta->update(['estado' => $request->estado]);
 
        return response()->json($venta->fresh(['detalles', 'pagos', 'user:id,name']));
    }
 
    /**
     * Editar ítems, cantidades y nota de una venta.
     * Solo admin.
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
 
        $request->validate([
            'notas'              => 'nullable|string|max:500',
            'items'              => 'required|array|min:1',
            'items.*.variante_id' => 'required|integer|exists:variantes,id',
            'items.*.cantidad'   => 'required|integer|min:1',
        ]);
 
        // Reconstruir detalles
        $venta->detalles()->delete();
 
        $total = 0;
 
        foreach ($request->items as $item) {
            $variante = \App\Models\Variante::with('producto')->find($item['variante_id']);
 
            $subtotal = $variante->precio * $item['cantidad'];
            $total   += $subtotal;
 
            DetalleVenta::create([
                'venta_id'         => $venta->id,
                'variante_id'      => $variante->id,
                'nombre_snapshot'  => $variante->producto->nombre . ($variante->nombre ? ' — ' . $variante->nombre : ''),
                'precio_snapshot'  => $variante->precio,
                'costo_snapshot'   => $variante->costo,
                'cantidad'         => $item['cantidad'],
                'subtotal'         => $subtotal,
            ]);
        }
 
        $venta->update([
            'total' => $total,
            'notas' => $request->notas,
        ]);
 
        return response()->json($venta->fresh(['detalles', 'pagos', 'user:id,name']));
    }
 
    /**
     * Anular una venta (soft-delete lógico via estado).
     * Solo admin.
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
 
        return response()->json(['message' => 'Venta anulada correctamente.']);
    }
}