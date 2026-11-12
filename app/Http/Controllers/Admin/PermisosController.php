<?php

namespace App\Http\Controllers\Admin;

use App\Autorizacion\CatalogoPermisos;
use App\Entorno\DestinoSql;
use App\Http\Controllers\Controller;
use App\Services\Admin\AdministrarPermisos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración de Permisos (administracion.accesos.permisos,
 * soloAdministrador): permisos de cada grupo sobre el catálogo centralizado.
 */
class PermisosController extends Controller
{
    public function index(AdministrarPermisos $servicio, DestinoSql $destino): Response
    {
        // Ids sincronizados por `codigo:ACCION`; lo no sincronizado no se muestra.
        $ids = [];
        foreach ($servicio->catalogo() as $id => $permiso) {
            $ids[$permiso['codigo'].':'.$permiso['accion']] = $id;
        }

        $areas = [];
        foreach (CatalogoPermisos::areas() as $area => $datosArea) {
            $modulos = [];
            foreach ($datosArea['modulos'] as $modulo => $datosModulo) {
                $funcionalidades = [];
                foreach ($datosModulo['funcionalidades'] as $clave => $datos) {
                    $codigo = "$area.$modulo.$clave";
                    $acciones = [];
                    foreach ($datos['acciones'] as $accion => $nombre) {
                        if (isset($ids["$codigo:$accion"])) {
                            $acciones[] = ['permisoId' => $ids["$codigo:$accion"], 'accion' => $accion, 'nombre' => $nombre];
                        }
                    }
                    if ($acciones !== []) {
                        $funcionalidades[] = [
                            'codigo' => $codigo,
                            'nombre' => $datos['nombre'],
                            'descripcion' => $datos['descripcion'],
                            'activo' => $datos['activo'],
                            'soloAdministrador' => $datos['soloAdministrador'] ?? false,
                            'acciones' => $acciones,
                        ];
                    }
                }
                if ($funcionalidades !== []) {
                    $modulos[] = ['modulo' => $modulo, 'nombre' => $datosModulo['nombre'], 'funcionalidades' => $funcionalidades];
                }
            }
            if ($modulos !== []) {
                $areas[] = ['area' => $area, 'nombre' => $datosArea['nombre'], 'modulos' => $modulos];
            }
        }

        $asignados = DB::table('adm_grupos_permisos')->get(['grupo_id', 'permiso_id'])->groupBy('grupo_id');

        return Inertia::render('admin/Permisos', [
            'grupos' => DB::table('adm_grupos')->orderBy('nombre')
                ->get(['id', 'nombre', 'es_administrador', 'activo'])
                ->map(fn ($g) => [
                    'id' => (int) $g->id,
                    'nombre' => $g->nombre,
                    'esAdministrador' => (bool) $g->es_administrador,
                    'activo' => (bool) $g->activo,
                    'permisos' => ($asignados[$g->id] ?? collect())->map(fn ($a) => (int) $a->permiso_id)->sort()->values(),
                ]),
            'areas' => $areas,
            'soloLectura' => ! $destino->escrituraConfigurada(),
        ]);
    }

    public function guardar(Request $request, int $grupo, AdministrarPermisos $servicio): RedirectResponse
    {
        $datos = $request->validate([
            'base' => ['present', 'array', 'list', 'max:1000'],
            'base.*' => ['integer', 'distinct'],
            'permisos' => ['present', 'array', 'list', 'max:1000'],
            'permisos.*' => ['integer', 'distinct'],
        ]);

        $cambiado = $servicio->sincronizar(
            $grupo,
            array_map(intval(...), $datos['base']),
            array_map(intval(...), $datos['permisos']),
        );

        return back()->with('status', $cambiado ? 'Permisos guardados.' : 'Sin cambios.');
    }
}
