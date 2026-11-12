<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use App\Services\Admin\RegistroSesiones;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revocación efectiva: en cada petición autenticada comprueba que la sesión
 * siga abierta en adm_sesiones (RegistroSesiones). Una sesión revocada,
 * cerrada o ajena termina y vuelve al inicio de sesión.
 */
class ValidarSesion
{
    public const MENSAJE = 'Su sesión fue finalizada. Inicie sesión de nuevo.';

    public function __construct(private readonly RegistroSesiones $registro) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        if (! $usuario instanceof Usuario || $this->registro->vigente($request, $usuario)) {
            return $next($request);
        }

        // Sin registrar un cierre normal: la fila conserva su revocación.
        $request->session()->forget(RegistroSesiones::CLAVE);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort_if($request->expectsJson(), 401, self::MENSAJE);

        return redirect()->route('login')->with('status', self::MENSAJE);
    }
}
