<?php

use App\Http\Controllers\Admin\UsuariosController;
use App\Http\Controllers\CambioContrasenaController;
use App\Http\Controllers\MiPerfilController;
use App\Http\Middleware\AutorizarPermiso;
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

    // Cambio de la contraseña propia; obligatorio con debe_cambiar_password = 1
    // (ExigirCambioContrasena). Escribe solo si DestinoSql lo permite.
    Route::get('cambiar-contrasena', [CambioContrasenaController::class, 'edit'])->name('contrasena.editar');
    Route::put('cambiar-contrasena', [CambioContrasenaController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('contrasena.actualizar');

    // Mi Perfil: cualquier cuenta activa; la identidad sale solo de la sesión.
    Route::get('mi-perfil', [MiPerfilController::class, 'show'])->name('mi-perfil');
    Route::put('mi-perfil', [MiPerfilController::class, 'actualizar'])->name('mi-perfil.actualizar');
    Route::put('mi-perfil/contrasena', [MiPerfilController::class, 'cambiarContrasena'])
        // Prefijo propio: no comparte el contador con otras rutas limitadas.
        ->middleware('throttle:6,1,mi-perfil')
        ->name('mi-perfil.contrasena');

    // Administración de Usuarios (administracion.accesos.usuarios).
    Route::get('admin/usuarios', [UsuariosController::class, 'index'])
        ->middleware(AutorizarPermiso::para('administracion.accesos.usuarios:VER'))
        ->name('admin.usuarios');
    Route::post('admin/usuarios', [UsuariosController::class, 'crear'])
        ->middleware(AutorizarPermiso::para('administracion.accesos.usuarios:CREAR'))
        ->name('admin.usuarios.crear');
    Route::put('admin/usuarios/{usuario}', [UsuariosController::class, 'actualizar'])
        ->whereNumber('usuario')
        ->middleware(AutorizarPermiso::para('administracion.accesos.usuarios:EDITAR'))
        ->name('admin.usuarios.actualizar');
    Route::put('admin/usuarios/{usuario}/estado', [UsuariosController::class, 'estado'])
        ->whereNumber('usuario')
        ->middleware(AutorizarPermiso::para('administracion.accesos.usuarios:EDITAR'))
        ->name('admin.usuarios.estado');
});
