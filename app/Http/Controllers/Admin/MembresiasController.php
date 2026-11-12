<?php

namespace App\Http\Controllers\Admin;

use App\Autorizacion\Autorizacion;
use App\Entorno\DestinoSql;
use App\Http\Controllers\Controller;
use App\Services\Admin\AdministrarMembresias;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usuarios y grupos (administracion.accesos.membresias). Consultar exige VER
 * (asignable a grupos); modificar queda reservado a los administradores
 * (AutorizarAdministrador en la ruta).
 */
class MembresiasController extends Controller
{
    public function index(Request $request, Autorizacion $autorizacion, DestinoSql $destino): Response
    {
        return Inertia::render('admin/Membresias', [
            'usuarios' => DB::table('adm_usuarios')->orderBy('usuario')
                ->get(['id', 'usuario', 'nombre', 'apellidos', 'activo'])
                ->map(fn ($u) => [
                    'id' => (int) $u->id,
                    'usuario' => $u->usuario,
                    'nombreCompleto' => trim($u->nombre.' '.$u->apellidos),
                    'activo' => (bool) $u->activo,
                ]),
            'grupos' => DB::table('adm_grupos')->orderBy('nombre')
                ->get(['id', 'nombre', 'es_administrador', 'activo'])
                ->map(fn ($g) => [
                    'id' => (int) $g->id,
                    'nombre' => $g->nombre,
                    'esAdministrador' => (bool) $g->es_administrador,
                    'activo' => (bool) $g->activo,
                ]),
            'asignaciones' => DB::table('adm_usuarios_grupos')->get(['usuario_id', 'grupo_id'])
                ->map(fn ($a) => ['usuarioId' => (int) $a->usuario_id, 'grupoId' => (int) $a->grupo_id]),
            'actorId' => (int) $request->user()?->getAuthIdentifier(),
            'soloLectura' => ! $autorizacion->esAdministrador($request) || ! $destino->escrituraConfigurada(),
        ]);
    }

    public function guardar(Request $request, int $usuario, AdministrarMembresias $servicio): RedirectResponse
    {
        $datos = $request->validate([
            'base' => ['present', 'array', 'list', 'max:1000'],
            'base.*' => ['integer', 'distinct'],
            'grupos' => ['present', 'array', 'list', 'max:1000'],
            'grupos.*' => ['integer', 'distinct'],
        ]);

        $cambiado = $servicio->sincronizarGruposDeUsuario(
            (int) $request->user()?->getAuthIdentifier(),
            $usuario,
            array_map(intval(...), $datos['base']),
            array_map(intval(...), $datos['grupos']),
        );

        return back()->with('status', $cambiado ? 'Cambios guardados.' : 'Sin cambios.');
    }
}
