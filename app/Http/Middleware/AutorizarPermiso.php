<?php

namespace App\Http\Middleware;

use App\Autorizacion\Autorizacion;
use App\Autorizacion\CatalogoPermisos;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige en el servidor un permiso del catálogo (o uno de varios).
 * Uso: ->middleware(AutorizarPermiso::para('administracion.accesos.membresias:VER')).
 */
class AutorizarPermiso
{
    public function __construct(private readonly Autorizacion $autorizacion) {}

    /** Valida los permisos al registrar la ruta y devuelve la declaración del middleware. */
    public static function para(string ...$permisos): string
    {
        foreach ($permisos as $permiso) {
            CatalogoPermisos::exigir($permiso);
        }

        return self::class.':'.implode(',', $permisos);
    }

    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        try {
            $permitido = $this->autorizacion->permite($request, ...$permisos);
        } catch (QueryException) {
            abort(503, 'No se pudo comprobar la autorización.');
        }

        abort_unless($permitido, 403);

        return $next($request);
    }
}
