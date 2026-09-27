<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Insumo;
use App\Models\Proveedor;
use App\Models\ProveedorMovimiento;
use App\Services\ProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ProveedorController extends Controller
{
    /**
     * Proveedores con su estado de cuenta calculado.
     */
    public function index(): JsonResponse
    {
        return response()->json(ProveedorService::listado());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'telefono' => 'nullable|string|max:50',
            'notas' => 'nullable|string|max:500',
            'activo' => 'boolean',
        ]);

        $proveedor = Proveedor::create($data);

        return response()->json(ProveedorService::estado($proveedor), 201);
    }

    public function update(Request $request, Proveedor $proveedor): JsonResponse
    {
        $data = $request->validate([
            'nombre' => 'sometimes|string|max:100',
            'telefono' => 'nullable|string|max:50',
            'notas' => 'nullable|string|max:500',
            'activo' => 'boolean',
        ]);

        $proveedor->update($data);

        return response()->json(ProveedorService::estado($proveedor->fresh()));
    }

    /**
     * Asigna insumos al proveedor (y su unidad de compra). Los insumos que
     * estaban asignados y no vienen en la lista quedan sin proveedor.
     *
     * Body: { insumos: [{ id, unidad_compra?, equivalencia_compra? }] }
     */
    public function asignarInsumos(Request $request, Proveedor $proveedor): JsonResponse
    {
        $data = $request->validate([
            'insumos' => 'present|array',
            'insumos.*.id' => 'required|integer|exists:insumos,id',
            'insumos.*.unidad_compra' => 'nullable|string|max:50',
            'insumos.*.equivalencia_compra' => 'nullable|numeric|gt:0',
        ]);

        $ids = collect($data['insumos'])->pluck('id');

        Insumo::where('proveedor_id', $proveedor->id)
            ->whereNotIn('id', $ids)
            ->update(['proveedor_id' => null]);

        foreach ($data['insumos'] as $fila) {
            Insumo::whereKey($fila['id'])->update([
                'proveedor_id' => $proveedor->id,
                'unidad_compra' => $fila['unidad_compra'] ?? null,
                'equivalencia_compra' => $fila['equivalencia_compra'] ?? null,
            ]);
        }

        return response()->json(ProveedorService::estado($proveedor->fresh()));
    }

    /**
     * GET /api/proveedores/{proveedor}/movimientos?tipo=&desde=&hasta=
     */
    public function movimientos(Request $request, Proveedor $proveedor): JsonResponse
    {
        $request->validate([
            'tipo' => ['nullable', Rule::in(ProveedorMovimiento::TIPOS)],
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
        ]);

        $movimientos = $proveedor->movimientos()
            ->with(['user:id,name', 'insumo:id,nombre,unidad'])
            ->when($request->tipo, fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when($request->desde, fn ($q, $desde) => $q->whereDate('fecha', '>=', $desde))
            ->when($request->hasta, fn ($q, $hasta) => $q->whereDate('fecha', '<=', $hasta))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json($movimientos);
    }

    /**
     * POST /api/proveedores/{proveedor}/movimientos
     */
    public function registrarMovimiento(Request $request, Proveedor $proveedor): JsonResponse
    {
        $tipo = $request->input('tipo');

        $data = $request->validate([
            'tipo' => ['required', Rule::in(ProveedorMovimiento::TIPOS)],
            'fecha' => 'nullable|date',
            'monto' => $tipo === 'ajuste'
                ? 'required|numeric|not_in:0'
                : ($tipo === 'saldo_inicial' ? 'required|numeric|min:0' : 'required|numeric|gt:0'),
            'monto_vendido' => 'nullable|numeric|min:0|lte:monto',
            'insumo_id' => 'nullable|required_with:cantidad|integer|exists:insumos,id',
            'cantidad' => 'nullable|required_with:insumo_id|numeric|gt:0',
            'unidad' => 'nullable|string|max:50',
            'actualizar_costo' => 'boolean',
            'caja_id' => 'nullable|integer|exists:cajas,id',
            'observacion' => ($tipo === 'ajuste' ? 'required' : 'nullable').'|string|max:500',
        ]);

        try {
            $resultado = ProveedorService::registrarMovimiento($proveedor, $data, Auth::id());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'movimiento' => $resultado['movimiento'],
            'costo_anterior' => $resultado['costo_anterior'],
            'costo_nuevo' => $resultado['costo_nuevo'],
            'proveedor' => ProveedorService::estado($proveedor->fresh()),
        ], 201);
    }
}
