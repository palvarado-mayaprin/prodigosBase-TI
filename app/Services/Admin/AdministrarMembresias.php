<?php

namespace App\Services\Admin;

use App\Auditoria\Auditor;
use App\Autorizacion\Autorizacion;
use App\Entorno\DestinoSql;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Membresías (adm_usuarios_grupos): grupos de una cuenta.
 *
 * - Concurrencia optimista: se guarda solo si las membresías actuales siguen
 *   siendo las que la pantalla leyó (Relaciones::diferencias).
 * - A un grupo inactivo no se agrega a nadie; sí se puede retirar.
 * - Nadie retira su propia capacidad administrativa ni deja el sistema sin
 *   administradores activos.
 * - Cada alta y retiro se audita en la misma transacción.
 */
class AdministrarMembresias
{
    public function __construct(
        private readonly DestinoSql $destino,
        private readonly Auditor $auditor,
        private readonly Autorizacion $autorizacion,
    ) {}

    /**
     * Devuelve false si no había cambios.
     *
     * @param  array<int, int>  $base  Grupos que la pantalla leyó.
     * @param  array<int, int>  $grupos  Grupos deseados.
     */
    public function sincronizarGruposDeUsuario(int $actorId, int $usuarioId, array $base, array $grupos): bool
    {
        $db = $this->destino->exigirEscritura();

        try {
            return $db->transaction(function () use ($db, $actorId, $usuarioId, $base, $grupos): bool {
                $usuario = $db->table('adm_usuarios')->where('id', $usuarioId)->lockForUpdate()->first(['id', 'usuario']);
                abort_unless($usuario !== null, 404, 'La cuenta no existe.');

                $actuales = Relaciones::ordenar($db->table('adm_usuarios_grupos')
                    ->where('usuario_id', $usuarioId)->lockForUpdate()->pluck('grupo_id')->all());
                $deseados = Relaciones::ordenar($grupos);
                [$agregados, $retirados] = Relaciones::diferencias($actuales, $base, $deseados);
                if ($agregados === [] && $retirados === []) {
                    return false;
                }

                $nombres = $this->grupos($db, [...$agregados, ...$retirados]);
                $inactivos = array_filter($agregados, fn ($id) => ! $nombres[$id]->activo);
                if ($inactivos !== []) {
                    throw ValidationException::withMessages(['grupos' => 'No se puede agregar a un grupo inactivo.']);
                }

                $eraAdministrador = $this->autorizacion->gruposAdministradores($db, $actuales) !== [];
                $seraAdministrador = $this->autorizacion->gruposAdministradores($db, $deseados) !== [];
                if ($eraAdministrador && ! $seraAdministrador) {
                    if ($usuarioId === $actorId) {
                        throw ValidationException::withMessages(['grupos' => 'No puede retirar su propia capacidad administrativa.']);
                    }
                    if ($this->autorizacion->quedariaSinAdministradores($db, $usuarioId)) {
                        throw ValidationException::withMessages(['grupos' => 'Debe permanecer al menos un administrador activo.']);
                    }
                }

                foreach ($retirados as $grupoId) {
                    $eliminadas = $db->table('adm_usuarios_grupos')->where('usuario_id', $usuarioId)->where('grupo_id', $grupoId)->delete();
                    if ($eliminadas !== 1) {
                        throw ValidationException::withMessages(['conflicto' => Relaciones::CONFLICTO]);
                    }
                    $this->auditar($db, 'RETIRAR', $usuarioId, $usuario->usuario, $grupoId, $nombres[$grupoId]->nombre);
                }
                foreach ($agregados as $grupoId) {
                    $db->table('adm_usuarios_grupos')->insert(['usuario_id' => $usuarioId, 'grupo_id' => $grupoId, 'created_at' => now()]);
                    $this->auditar($db, 'ASIGNAR', $usuarioId, $usuario->usuario, $grupoId, $nombres[$grupoId]->nombre);
                }

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Otra sesión agregó la misma membresía (clave primaria).
            throw ValidationException::withMessages(['conflicto' => Relaciones::CONFLICTO]);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, stdClass>
     */
    private function grupos(Connection $db, array $ids): array
    {
        $grupos = $db->table('adm_grupos')->whereIn('id', $ids)->lockForUpdate()->get(['id', 'nombre', 'activo'])->keyBy(fn ($g) => (int) $g->id)->all();
        if (count($grupos) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['grupos' => 'Uno o más grupos no existen.']);
        }

        return $grupos;
    }

    private function auditar(Connection $db, string $accion, int $usuarioId, string $usuario, int $grupoId, string $grupo): void
    {
        $this->auditor->registrar($db, 'adm_usuarios_grupos', "$usuarioId:$grupoId", $accion, [
            'usuario' => $usuario,
            'grupo' => $grupo,
        ]);
    }
}
