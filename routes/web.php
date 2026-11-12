<?php

use App\Http\Controllers\Admin\AuditoriaController;
use App\Http\Controllers\Admin\GruposController;
use App\Http\Controllers\Admin\MembresiasController;
use App\Http\Controllers\Admin\PermisosController;
use App\Http\Controllers\Admin\SeguimientoController;
use App\Http\Controllers\Admin\SesionesController;
use App\Http\Controllers\Admin\UsuariosController;
use App\Http\Controllers\CambioContrasenaController;
use App\Http\Controllers\MiPerfilController;
use App\Http\Controllers\SeguimientoUsoController;
use App\Http\Middleware\AutorizarAdministrador;
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

    // Administración de Grupos (administracion.accesos.grupos).
    Route::get('admin/grupos', [GruposController::class, 'index'])
        ->middleware(AutorizarPermiso::para('administracion.accesos.grupos:VER'))
        ->name('admin.grupos');
    Route::post('admin/grupos', [GruposController::class, 'crear'])
        ->middleware(AutorizarPermiso::para('administracion.accesos.grupos:CREAR'))
        ->name('admin.grupos.crear');
    Route::put('admin/grupos/{grupo}', [GruposController::class, 'actualizar'])
        ->whereNumber('grupo')
        ->middleware(AutorizarPermiso::para('administracion.accesos.grupos:EDITAR'))
        ->name('admin.grupos.actualizar');
    Route::put('admin/grupos/{grupo}/estado', [GruposController::class, 'estado'])
        ->whereNumber('grupo')
        ->middleware(AutorizarPermiso::para('administracion.accesos.grupos:EDITAR'))
        ->name('admin.grupos.estado');

    // Usuarios y grupos (administracion.accesos.membresias): consultar con
    // VER; modificar, solo un grupo administrador.
    Route::get('admin/membresias', [MembresiasController::class, 'index'])
        ->middleware(AutorizarPermiso::para('administracion.accesos.membresias:VER'))
        ->name('admin.membresias');
    Route::put('admin/membresias/{usuario}', [MembresiasController::class, 'guardar'])
        ->whereNumber('usuario')
        ->middleware(AutorizarAdministrador::class)
        ->name('admin.membresias.guardar');

    // Administración de Permisos (administracion.accesos.permisos).
    Route::get('admin/permisos', [PermisosController::class, 'index'])
        ->middleware(AutorizarPermiso::para('administracion.accesos.permisos:VER'))
        ->name('admin.permisos');
    Route::put('admin/permisos/{grupo}', [PermisosController::class, 'guardar'])
        ->whereNumber('grupo')
        ->middleware(AutorizarPermiso::para('administracion.accesos.permisos:EDITAR'))
        ->name('admin.permisos.guardar');

    // Sesiones Activas (administracion.sesiones.activas). La referencia es un
    // HMAC del registro; nunca el identificador de sesión.
    Route::get('admin/sesiones', [SesionesController::class, 'index'])
        ->middleware(AutorizarPermiso::para('administracion.sesiones.activas:VER'))
        ->name('admin.sesiones');
    Route::put('admin/sesiones/{referencia}/revocar', [SesionesController::class, 'revocar'])
        ->where('referencia', '[0-9a-f]{32}')
        ->middleware(AutorizarPermiso::para('administracion.sesiones.activas:ELIMINAR'))
        ->name('admin.sesiones.revocar');
    Route::put('admin/sesiones/usuarios/{usuario}/revocar', [SesionesController::class, 'revocarUsuario'])
        ->whereNumber('usuario')
        ->middleware(AutorizarPermiso::para('administracion.sesiones.activas:ELIMINAR'))
        ->name('admin.sesiones.revocar-usuario');

    // Auditoría de Modificaciones (administracion.auditoria.modificaciones):
    // solo lectura, también con la escritura bloqueada.
    Route::get('admin/auditoria', [AuditoriaController::class, 'index'])
        ->middleware(AutorizarPermiso::para('administracion.auditoria.modificaciones:VER'))
        ->name('admin.auditoria');
    Route::get('admin/auditoria/exportar/{formato}', [AuditoriaController::class, 'exportar'])
        ->whereIn('formato', ['csv', 'xlsx'])
        ->middleware(AutorizarPermiso::para('administracion.auditoria.modificaciones:VER'))
        ->name('admin.auditoria.exportar');

    // Seguimiento de Uso (administracion.seguimiento.uso): reportes de solo lectura.
    Route::get('admin/seguimiento', [SeguimientoController::class, 'index'])
        ->middleware(AutorizarPermiso::para('administracion.seguimiento.uso:VER'))
        ->name('admin.seguimiento');
    Route::get('admin/seguimiento/exportar/{reporte}/{formato}', [SeguimientoController::class, 'exportar'])
        ->whereIn('reporte', ['historial', 'usuarios', 'modulos', 'diario'])
        ->whereIn('formato', ['csv', 'xlsx'])
        ->middleware(AutorizarPermiso::para('administracion.seguimiento.uso:VER'))
        ->name('admin.seguimiento.exportar');

    // Registro de uso desde el navegador (cualquier cuenta autenticada): la
    // sesión y el usuario salen del servidor; la ruta visitada se valida
    // contra el catálogo y los permisos.
    Route::post('seguimiento/visitas', [SeguimientoUsoController::class, 'visita'])
        ->middleware('throttle:120,1,seguimiento')
        ->name('seguimiento.visita');
    Route::post('seguimiento/actividad', [SeguimientoUsoController::class, 'actividad'])
        ->middleware('throttle:120,1,seguimiento')
        ->name('seguimiento.actividad');
});
