<?php

namespace App\Autorizacion;

use App\Models\Usuario;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Autorización centralizada: usuarios, grupos (adm_usuarios_grupos), permisos
 * asignados (adm_grupos_permisos) y catálogo (adm_funcionalidades).
 *
 * - Deniega por defecto.
 * - Solo cuentan los grupos activos; un grupo con es_administrador = 1 tiene
 *   acceso universal. No hay identificadores de grupo especiales.
 * - La cuenta y sus grupos se releen en cada petición (memoria solo en la
 *   petición). Una cuenta inexistente o inactiva cierra la sesión.
 */
class Autorizacion
{
    private const ATRIBUTO = 'adm_autorizacion';

    /**
     * Grupos activos de la cuenta autenticada y si alguno es administrador.
     *
     * @return array{grupos: list<int>, administrador: bool}
     */
    public function contexto(Request $request): array
    {
        $memoria = $request->attributes->get(self::ATRIBUTO);
        if (is_array($memoria) && is_array($memoria['grupos'] ?? null) && is_bool($memoria['administrador'] ?? null)) {
            return ['grupos' => array_values(array_map(intval(...), $memoria['grupos'])), 'administrador' => $memoria['administrador']];
        }

        $identidad = $request->user();
        abort_unless($identidad instanceof Usuario, 403);

        try {
            // La sesión aporta la identidad; el estado y los grupos se releen.
            $activo = DB::table('adm_usuarios')->where('id', $identidad->getAuthIdentifier())->value('activo');
            if (! $activo) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                abort(403, 'La cuenta no está activa.');
            }
            $filas = DB::table('adm_usuarios_grupos as ug')
                ->join('adm_grupos as g', 'g.id', '=', 'ug.grupo_id')
                ->where('ug.usuario_id', $identidad->getAuthIdentifier())
                ->where('g.activo', true)
                ->get(['g.id', 'g.es_administrador']);
        } catch (QueryException) {
            abort(503, 'No se pudo comprobar la autorización.');
        }

        $contexto = [
            'grupos' => array_values($filas->map(fn ($fila) => (int) $fila->id)->all()),
            'administrador' => $filas->contains(fn ($fila) => (bool) $fila->es_administrador),
        ];
        $request->attributes->set(self::ATRIBUTO, $contexto);

        return $contexto;
    }

    /** @return list<int> */
    public function grupos(Request $request): array
    {
        return $this->contexto($request)['grupos'];
    }

    public function esAdministrador(Request $request): bool
    {
        return $this->contexto($request)['administrador'];
    }

    /**
     * Basta con uno de los permisos `codigo:ACCION`. Las funcionalidades
     * `soloAdministrador` solo las concede un grupo administrador.
     */
    public function permite(Request $request, string ...$permisos): bool
    {
        $funcionalidades = CatalogoPermisos::funcionalidades();
        $asignables = [];
        foreach ($permisos as $permiso) {
            [$codigo] = CatalogoPermisos::exigir($permiso);
            if (! $funcionalidades[$codigo]['soloAdministrador']) {
                $asignables[] = $permiso;
            }
        }

        $contexto = $this->contexto($request);
        if ($contexto['administrador']) {
            return true;
        }

        return $asignables !== [] && array_intersect($asignables, $this->permitidos($contexto['grupos'])) !== [];
    }

    /**
     * Permisos acumulados de los grupos, como `codigo:ACCION`, solo de
     * funcionalidades activas.
     *
     * @param  list<int>  $grupos
     * @return list<string>
     */
    public function permitidos(array $grupos): array
    {
        if ($grupos === []) {
            return [];
        }

        return array_values(DB::table('adm_grupos_permisos as gp')
            ->join('adm_permisos as p', 'p.id', '=', 'gp.permiso_id')
            ->join('adm_funcionalidades as f', 'f.id', '=', 'p.funcionalidad_id')
            ->whereIn('gp.grupo_id', $grupos)
            ->where('f.activo', true)
            ->distinct()
            ->get(['f.codigo', 'p.accion'])
            ->map(fn ($fila) => $fila->codigo.':'.$fila->accion)
            ->all());
    }

    /**
     * Rutas de entrada (VER) que la cuenta puede abrir. Solo presentación
     * (menú y Dashboard): no protege rutas.
     *
     * @return list<string>
     */
    public function rutasPermitidas(Request $request): array
    {
        $contexto = $this->contexto($request);
        $permitidos = $contexto['administrador'] ? [] : $this->permitidos($contexto['grupos']);
        $rutas = [];
        foreach (CatalogoPermisos::funcionalidades() as $codigo => $funcionalidad) {
            $ruta = $funcionalidad['ruta'];
            if ($ruta !== null && ! in_array($ruta, $rutas, true) && Route::has($ruta) && ($contexto['administrador']
                || (! $funcionalidad['soloAdministrador'] && in_array("$codigo:VER", $permitidos, true)))) {
                $rutas[] = $ruta;
            }
        }

        return $rutas;
    }

    /**
     * Protección del último administrador: ¿quedaría el sistema sin ninguna
     * cuenta activa en un grupo administrador activo si la cuenta `$usuarioId`
     * dejara de ser administradora o el grupo `$grupoId` se desactivara?
     * Debe llamarse dentro de la transacción que hará el cambio: bloquea las
     * membresías administrativas hasta su fin.
     */
    public function quedariaSinAdministradores(Connection $db, ?int $usuarioId = null, ?int $grupoId = null): bool
    {
        abort_unless($db->transactionLevel() > 0, 500, 'La protección del último administrador exige una transacción.');

        $otros = $db->table('adm_usuarios_grupos as ug')
            ->join('adm_grupos as g', 'g.id', '=', 'ug.grupo_id')
            ->join('adm_usuarios as u', 'u.id', '=', 'ug.usuario_id')
            ->where('g.es_administrador', true)
            ->where('g.activo', true)
            ->where('u.activo', true)
            ->when($usuarioId !== null, fn ($consulta) => $consulta->where('ug.usuario_id', '<>', $usuarioId))
            ->when($grupoId !== null, fn ($consulta) => $consulta->where('ug.grupo_id', '<>', $grupoId))
            ->lockForUpdate()
            ->pluck('ug.usuario_id');

        return $otros->isEmpty();
    }

    /**
     * Cuáles de los grupos indicados son administradores y están activos.
     * Sirve para saber si una cuenta conserva la administración tras un cambio.
     *
     * @param  list<int>  $grupos
     * @return list<int>
     */
    public function gruposAdministradores(Connection $db, array $grupos): array
    {
        if ($grupos === []) {
            return [];
        }

        return array_values($db->table('adm_grupos')
            ->whereIn('id', $grupos)
            ->where('es_administrador', true)
            ->where('activo', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }
}
