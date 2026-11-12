<?php

namespace App\Services\Admin;

use App\Auditoria\Auditor;
use App\Autorizacion\CatalogoPermisos;
use App\Entorno\DestinoSql;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Permisos de los grupos (adm_grupos_permisos) sobre el catálogo sincronizado
 * (adm_funcionalidades y adm_permisos, que llena adm:sincronizar-permisos).
 *
 * - Solo se asignan permisos declarados en CatalogoPermisos, de
 *   funcionalidades activas y que no sean `soloAdministrador`. Retirar se
 *   permite siempre (limpia asignaciones antiguas).
 * - Un grupo administrador tiene acceso universal: sus permisos no se editan.
 * - Concurrencia optimista como en las membresías (Relaciones::diferencias).
 */
class AdministrarPermisos
{
    public function __construct(
        private readonly DestinoSql $destino,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Permisos sincronizados con su código del catálogo, por id.
     *
     * @return array<int, array{codigo: string, accion: string, asignable: bool, declarado: bool}>
     */
    public function catalogo(?Connection $db = null): array
    {
        $catalogo = CatalogoPermisos::funcionalidades();
        $permisos = [];
        foreach (($db ?? DB::connection())->table('adm_permisos as p')
            ->join('adm_funcionalidades as f', 'f.id', '=', 'p.funcionalidad_id')
            ->get(['p.id', 'f.codigo', 'p.accion']) as $fila) {
            $funcionalidad = $catalogo[$fila->codigo] ?? null;
            $declarado = isset($funcionalidad['acciones'][$fila->accion]);
            $permisos[(int) $fila->id] = [
                'codigo' => $fila->codigo,
                'accion' => $fila->accion,
                'declarado' => $declarado,
                'asignable' => $declarado && $funcionalidad['activo'] && ! $funcionalidad['soloAdministrador'],
            ];
        }

        return $permisos;
    }

    /**
     * Devuelve false si no había cambios.
     *
     * @param  array<int, int>  $base  Permisos que la pantalla leyó.
     * @param  array<int, int>  $permisos  Permisos deseados.
     */
    public function sincronizar(int $grupoId, array $base, array $permisos): bool
    {
        $db = $this->destino->exigirEscritura();

        try {
            return $db->transaction(function () use ($db, $grupoId, $base, $permisos): bool {
                $grupo = $db->table('adm_grupos')->where('id', $grupoId)->lockForUpdate()->first(['id', 'nombre', 'es_administrador']);
                abort_unless($grupo !== null, 404, 'El grupo no existe.');
                if ($grupo->es_administrador) {
                    throw ValidationException::withMessages(['permisos' => 'El grupo administrador tiene acceso universal: sus permisos no se editan.']);
                }

                $actuales = Relaciones::ordenar($db->table('adm_grupos_permisos')
                    ->where('grupo_id', $grupoId)->lockForUpdate()->pluck('permiso_id')->all());
                [$agregados, $retirados] = Relaciones::diferencias($actuales, $base, Relaciones::ordenar($permisos));
                if ($agregados === [] && $retirados === []) {
                    return false;
                }

                $catalogo = $this->catalogo($db);
                foreach ($agregados as $id) {
                    if (! isset($catalogo[$id]) || ! $catalogo[$id]['declarado']) {
                        throw ValidationException::withMessages(['permisos' => 'Uno o más permisos no existen en el catálogo.']);
                    }
                    if (! $catalogo[$id]['asignable']) {
                        throw ValidationException::withMessages(['permisos' => 'Uno o más permisos no se pueden asignar: la funcionalidad está inactiva o es solo para administradores.']);
                    }
                }

                foreach ($retirados as $id) {
                    $db->table('adm_grupos_permisos')->where('grupo_id', $grupoId)->where('permiso_id', $id)->delete();
                    $this->auditar($db, 'RETIRAR', $grupoId, $grupo->nombre, $id, $catalogo[$id] ?? null);
                }
                foreach ($agregados as $id) {
                    $db->table('adm_grupos_permisos')->insert(['grupo_id' => $grupoId, 'permiso_id' => $id, 'created_at' => now()]);
                    $this->auditar($db, 'ASIGNAR', $grupoId, $grupo->nombre, $id, $catalogo[$id]);
                }

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['conflicto' => Relaciones::CONFLICTO]);
        }
    }

    /** @param array{codigo: string, accion: string}|null $permiso */
    private function auditar(Connection $db, string $accion, int $grupoId, string $grupo, int $permisoId, ?array $permiso): void
    {
        $this->auditor->registrar($db, 'adm_grupos_permisos', "$grupoId:$permisoId", $accion, [
            'grupo' => $grupo,
            'permiso' => $permiso === null ? (string) $permisoId : $permiso['codigo'].':'.$permiso['accion'],
        ]);
    }
}
