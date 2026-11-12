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
 * Administración de grupos (adm_grupos). Cada operación es una transacción
 * con su auditoría.
 *
 * - Los grupos no se eliminan: se activan o desactivan.
 * - `es_administrador` se fija al crear el grupo y no se edita después: un
 *   grupo administrador solo deja de serlo al desactivarse, con la protección
 *   del último administrador.
 */
class AdministrarGrupos
{
    public function __construct(
        private readonly DestinoSql $destino,
        private readonly Auditor $auditor,
        private readonly Autorizacion $autorizacion,
    ) {}

    /** @param array{nombre: string, descripcion?: string|null, es_administrador?: bool, activo?: bool} $datos */
    public function crear(array $datos): int
    {
        $db = $this->destino->exigirEscritura();

        return $this->unico(fn (): int => $db->transaction(function () use ($db, $datos): int {
            $fila = [
                'nombre' => $this->texto($datos['nombre']),
                'descripcion' => $this->texto($datos['descripcion'] ?? null),
                'es_administrador' => (bool) ($datos['es_administrador'] ?? false),
                'activo' => (bool) ($datos['activo'] ?? true),
            ];
            $this->exigirNombreUnico($db, (string) $fila['nombre']);

            $id = (int) $db->table('adm_grupos')->insertGetId([...$fila, 'created_at' => now(), 'updated_at' => now()]);
            $this->auditor->registrar($db, 'adm_grupos', (string) $id, 'CREAR',
                array_map(fn ($valor) => [null, $valor], array_filter($fila, fn ($valor) => $valor !== null)));

            return $id;
        }));
    }

    /**
     * Edita nombre y descripción. Devuelve false si no había cambios.
     *
     * @param  array{nombre: string, descripcion?: string|null}  $datos
     */
    public function actualizar(int $id, array $datos): bool
    {
        $db = $this->destino->exigirEscritura();

        return $this->unico(fn (): bool => $db->transaction(function () use ($db, $id, $datos): bool {
            $grupo = $this->buscar($db, $id);
            $nuevos = [
                'nombre' => $this->texto($datos['nombre']),
                'descripcion' => $this->texto($datos['descripcion'] ?? null),
            ];
            $this->exigirNombreUnico($db, (string) $nuevos['nombre'], $id);

            $cambios = [];
            $auditoria = [];
            foreach ($nuevos as $campo => $valor) {
                if ($valor !== $grupo->{$campo}) {
                    $cambios[$campo] = $valor;
                    $auditoria[$campo] = [$grupo->{$campo}, $valor];
                }
            }
            if ($cambios === []) {
                return false;
            }

            $db->table('adm_grupos')->where('id', $id)->update([...$cambios, 'updated_at' => now()]);
            $this->auditor->registrar($db, 'adm_grupos', (string) $id, 'EDITAR', $auditoria);

            return true;
        }));
    }

    /**
     * Activa o desactiva un grupo. Un grupo administrador no se desactiva si
     * con ello quien actúa pierde la administración o el sistema queda sin
     * administradores activos. Devuelve false si no cambió.
     */
    public function cambiarEstado(int $id, bool $activo, int $actorId): bool
    {
        $db = $this->destino->exigirEscritura();

        return $db->transaction(function () use ($db, $id, $activo, $actorId): bool {
            $grupo = $this->buscar($db, $id);
            if ((bool) $grupo->activo === $activo) {
                return false;
            }
            if (! $activo && $grupo->es_administrador) {
                $gruposActor = Relaciones::ordenar($db->table('adm_usuarios_grupos')->where('usuario_id', $actorId)->pluck('grupo_id')->all());
                $propios = $this->autorizacion->gruposAdministradores($db, $gruposActor);
                if ($propios === [$id]) {
                    throw ValidationException::withMessages(['activo' => 'No puede desactivar el grupo que le otorga la administración.']);
                }
                if ($this->autorizacion->quedariaSinAdministradores($db, grupoId: $id)) {
                    throw ValidationException::withMessages(['activo' => 'Debe permanecer al menos un administrador activo.']);
                }
            }

            $db->table('adm_grupos')->where('id', $id)->update(['activo' => $activo, 'updated_at' => now()]);
            $this->auditor->registrar($db, 'adm_grupos', (string) $id, $activo ? 'HABILITAR' : 'DESHABILITAR', [
                'activo' => [! $activo, $activo],
            ]);

            return true;
        });
    }

    /** Comprobación legible; la restricción única de la base es la garantía final. */
    private function exigirNombreUnico(Connection $db, string $nombre, ?int $excepto = null): void
    {
        $existe = $db->table('adm_grupos')->where('nombre', $nombre)
            ->when($excepto !== null, fn ($consulta) => $consulta->where('id', '<>', $excepto))
            ->lockForUpdate()->exists();
        if ($existe) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe un grupo con ese nombre.']);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     */
    private function unico(callable $operacion): mixed
    {
        try {
            return $operacion();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe un grupo con ese nombre.']);
        }
    }

    private function buscar(Connection $db, int $id): stdClass
    {
        $grupo = $db->table('adm_grupos')->where('id', $id)->lockForUpdate()
            ->first(['id', 'nombre', 'descripcion', 'es_administrador', 'activo']);
        abort_unless($grupo !== null, 404, 'El grupo no existe.');

        return $grupo;
    }

    private function texto(mixed $valor): ?string
    {
        $valor = is_string($valor) ? trim($valor) : null;

        return $valor === '' ? null : $valor;
    }
}
