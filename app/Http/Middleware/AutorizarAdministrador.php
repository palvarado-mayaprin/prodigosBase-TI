<?php

namespace App\Http\Middleware;

use App\Autorizacion\Autorizacion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que la cuenta pertenezca a un grupo activo con es_administrador = 1.
 * Para las funcionalidades `soloAdministrador` del catálogo y las acciones
 * reservadas (modificar membresías y permisos).
 */
class AutorizarAdministrador
{
    public function __construct(private readonly Autorizacion $autorizacion) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->autorizacion->esAdministrador($request), 403);

        return $next($request);
    }
}
