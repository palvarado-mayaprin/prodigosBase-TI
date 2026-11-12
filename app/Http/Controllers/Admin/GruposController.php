<?php

namespace App\Http\Controllers\Admin;

use App\Entorno\DestinoSql;
use App\Http\Controllers\Controller;
use App\Services\Admin\AdministrarGrupos;
use App\Services\Admin\AdministrarPermisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración de Grupos (administracion.accesos.grupos, soloAdministrador).
 * Alta, edición, activación e integrantes y permisos de cada grupo (consulta).
 * No elimina grupos.
 */
class GruposController extends Controller
{
    public function index(AdministrarPermisos $permisos, DestinoSql $destino): Response
    {
        $catalogo = $permisos->catalogo();
        $integrantes = DB::table('adm_usuarios_grupos as ug')
            ->join('adm_usuarios as u', 'u.id', '=', 'ug.usuario_id')
            ->orderBy('u.usuario')
            ->get(['ug.grupo_id', 'u.id', 'u.usuario', 'u.nombre', 'u.apellidos', 'u.activo'])
            ->groupBy('grupo_id');
        $asignados = DB::table('adm_grupos_permisos')->get(['grupo_id', 'permiso_id'])->groupBy('grupo_id');

        $grupos = DB::table('adm_grupos')->orderBy('nombre')
            ->get(['id', 'nombre', 'descripcion', 'es_administrador', 'activo'])
            ->map(fn ($g) => [
                'id' => (int) $g->id,
                'nombre' => $g->nombre,
                'descripcion' => $g->descripcion,
                'esAdministrador' => (bool) $g->es_administrador,
                'activo' => (bool) $g->activo,
                'integrantes' => ($integrantes[$g->id] ?? collect())->map(fn ($u) => [
                    'id' => (int) $u->id,
                    'usuario' => $u->usuario,
                    'nombreCompleto' => trim($u->nombre.' '.$u->apellidos),
                    'activo' => (bool) $u->activo,
                ])->values(),
                'permisos' => ($asignados[$g->id] ?? collect())
                    ->map(fn ($a) => isset($catalogo[(int) $a->permiso_id])
                        ? $catalogo[(int) $a->permiso_id]['codigo'].':'.$catalogo[(int) $a->permiso_id]['accion']
                        : null)
                    ->filter()->sort()->values(),
            ]);

        return Inertia::render('admin/Grupos', [
            'grupos' => $grupos,
            'soloLectura' => ! $destino->escrituraConfigurada(),
        ]);
    }

    public function crear(Request $request, AdministrarGrupos $servicio): RedirectResponse
    {
        $datos = $request->validate([
            ...$this->reglas(),
            'es_administrador' => ['boolean'],
            'activo' => ['boolean'],
        ]);
        $datos['es_administrador'] = $request->boolean('es_administrador');
        $datos['activo'] = $request->boolean('activo', true);

        $servicio->crear($datos);

        return back()->with('status', 'Grupo creado.');
    }

    public function actualizar(Request $request, int $grupo, AdministrarGrupos $servicio): RedirectResponse
    {
        $datos = $request->validate([
            ...$this->reglas(),
            // Se fija al crear el grupo.
            'es_administrador' => ['prohibited'],
        ]);

        $cambiado = $servicio->actualizar($grupo, $datos);

        return back()->with('status', $cambiado ? 'Grupo actualizado.' : 'Sin cambios.');
    }

    public function estado(Request $request, int $grupo, AdministrarGrupos $servicio): RedirectResponse
    {
        $datos = $request->validate(['activo' => ['required', 'boolean']]);

        $servicio->cambiarEstado($grupo, (bool) $datos['activo'], (int) $request->user()?->getAuthIdentifier());

        return back()->with('status', $datos['activo'] ? 'Grupo activado.' : 'Grupo desactivado.');
    }

    /** @return array<string, list<string>> */
    private function reglas(): array
    {
        // Longitudes de adm_grupos.
        return [
            'nombre' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:300'],
        ];
    }
}
