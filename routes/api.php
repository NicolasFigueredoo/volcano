<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\CajaController;
use App\Http\Controllers\Api\ComboController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {

    // ── POS ──────────────────────────────────────────────────────────────────
    Route::prefix('pos')->group(function () {
        Route::get('menu', [PosController::class, 'menu']);
        Route::post('venta', [PosController::class, 'registrarVenta']);
        Route::get('pedidos-activos', [PosController::class, 'pedidosActivos']);
        Route::put('ventas/{venta}/estado', [PosController::class, 'actualizarEstado']);
    });

    // ── Caja ─────────────────────────────────────────────────────────────────
    Route::prefix('caja')->group(function () {
        Route::get('hoy', [CajaController::class, 'hoy']);
        Route::get('ventas-hoy', [CajaController::class, 'ventasHoy']);
        Route::post('abrir', [CajaController::class, 'abrir']);
        Route::post('cerrar', [CajaController::class, 'cerrar']);
        Route::get('historial', [CajaController::class, 'historial']);
        Route::get('resumen-semanal', [CajaController::class, 'resumenSemanal']);
        Route::get('resumen-rango', [CajaController::class, 'resumenPorRango']);
        Route::get('pedidos-activos', [CajaController::class, 'pedidosActivos']);
        Route::get('alertas-compra', [CajaController::class, 'alertasCompra']);
        Route::get('historial/{caja}', [CajaController::class, 'show']);
        Route::post('{caja}/cerrar-manual', [CajaController::class, 'cerrarManual']);

        // Edición / eliminación de cajas (admin)
        Route::put('{caja}', [CajaController::class, 'update']);
        Route::delete('{caja}', [CajaController::class, 'destroy']);
        Route::post('{caja}/ventas', [CajaController::class, 'agregarVenta']);

        // Ventas (pedidos) dentro de una caja (admin)
        Route::patch('ventas/{venta}/estado', [CajaController::class, 'actualizarEstadoVenta']);
        Route::put('ventas/{venta}', [CajaController::class, 'editarVenta']);
        Route::delete('ventas/{venta}', [CajaController::class, 'anularVenta']);
    });

    // ── Inventario ───────────────────────────────────────────────────────────
    Route::prefix('inventario')->group(function () {
        Route::get('/', [InventarioController::class, 'index']);
        Route::get('alertas', [InventarioController::class, 'alertas']);
        Route::put('{insumo}/ajustar', [InventarioController::class, 'ajustar']);
        Route::post('{insumo}/entrada', [InventarioController::class, 'entrada']);
        Route::get('{insumo}/movimientos', [InventarioController::class, 'movimientos']);
    });

    // ── Combos (lectura pública, escritura solo admin) ────────────────────────
    Route::get('combos', [ComboController::class, 'index']);

    // ── Admin ────────────────────────────────────────────────────────────────
    Route::middleware('es_admin')->prefix('admin')->group(function () {
        Route::get('categorias', [AdminController::class, 'categorias']);
        Route::post('categorias', [AdminController::class, 'crearCategoria']);

        Route::get('insumos', [AdminController::class, 'insumos']);
        Route::post('insumos', [AdminController::class, 'crearInsumo']);
        Route::put('insumos/{insumo}', [AdminController::class, 'actualizarInsumo']);

        Route::get('productos', [AdminController::class, 'productos']);
        Route::post('productos', [AdminController::class, 'crearProducto']);
        Route::put('productos/{producto}', [AdminController::class, 'actualizarProducto']);
        Route::delete('productos/{producto}', [AdminController::class, 'eliminarProducto']);

        Route::post('productos/{producto}/variantes', [AdminController::class, 'crearVariante']);
        Route::put('variantes/{variante}', [AdminController::class, 'actualizarVariante']);
        Route::get('variantes/{variante}/receta', [AdminController::class, 'varianteConReceta']);
        Route::post('variantes/{variante}/receta', [AdminController::class, 'guardarReceta']);

        Route::post('combos', [ComboController::class, 'store']);
        Route::put('combos/{combo}', [ComboController::class, 'update']);
        Route::delete('combos/{combo}', [ComboController::class, 'destroy']);

        // Gastos fijos
        Route::get('gastos-fijos', [AdminController::class, 'gastosFijos']);
        Route::post('gastos-fijos', [AdminController::class, 'crearGastoFijo']);
        Route::put('gastos-fijos/{gasto}', [AdminController::class, 'actualizarGastoFijo']);
    });

    Route::middleware('es_admin')->prefix('usuarios')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::put('{user}', [UserController::class, 'update']);
        Route::delete('{user}', [UserController::class, 'destroy']);
    });
});