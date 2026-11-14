<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Con debe_cambiar_password = 1, la cuenta autenticada solo puede cambiar su
 * contraseña o cerrar la sesión (el registro de uso responde 204 sin registrar
 * nada). Se aplica a todo el grupo `web`; la cuenta
 * se lee de la base en cada petición (proveedor de autenticación).
 */
class ExigirCambioContrasena
{
    public const RUTAS_PERMITIDAS = ['contrasena.editar', 'contrasena.actualizar', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        if ($usuario instanceof Usuario && $usuario->debe_cambiar_password && ! $request->routeIs(...self::RUTAS_PERMITIDAS)) {
            // El registro de uso del navegador no se procesa ni se registra:
            // 204 en lugar de 403 para no dejar errores en la consola.
            if ($request->routeIs('seguimiento.*')) {
                return response()->noContent();
            }
            abort_if($request->expectsJson(), 403, 'Debe cambiar su contraseña antes de continuar.');

            return redirect()->route('contrasena.editar');
        }

        return $next($request);
    }
}
