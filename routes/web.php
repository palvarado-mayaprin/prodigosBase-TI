<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

// Todo el grupo `web` pasa por App\Http\Middleware\ControlarAcceso
// (origen y escritura): ver bootstrap/app.php.
Route::redirect('/', '/dashboard')->name('home');

// Autenticación: únicamente inicio y cierre de sesión (Fortify::ignoreRoutes()
// en FortifyServiceProvider evita el resto de sus rutas).
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});
Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});
