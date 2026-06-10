<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', fn () => redirect()->route('pos'))->name('home');

    Route::get('/pos', fn () => Inertia::render('Pos'))->name('pos');
    Route::get('/caja', fn () => Inertia::render('Caja'))->name('caja');
    Route::get('/inventario', fn () => Inertia::render('Inventario'))->name('inventario');

    Route::middleware('es_admin')->group(function () {
        Route::get('/admin', fn () => Inertia::render('Admin'))->name('admin');
        Route::get('/usuarios', fn () => Inertia::render('Usuarios'))->name('usuarios');
    });
});

Route::get('/qrburger', fn () => view('qr.burger'))->name('qrburger');
Route::get('/qrhelados', fn () => view('qr.helados'))->name('qrhelados');


require __DIR__.'/auth.php';