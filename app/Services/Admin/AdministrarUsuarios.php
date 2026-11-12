<?php

namespace App\Services\Admin;

use App\Auditoria\Auditor;
use App\Autorizacion\Autorizacion;
use App\Entorno\DestinoSql;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Administración de cuentas (adm_usuarios) y datos de contacto propios (Mi
 * Perfil). Cada operación es una transacción con su auditoría; las
 * contraseñas solo se guardan como hash y nunca se auditan.
 *
 * - Las cuentas no se eliminan: se habilitan o deshabilitan.
 * - El nombre de usuario se fija al crear: es el identificador de inicio de
 *   sesión y el que conserva la auditoría.
 * - Una contraseña asignada por un administrador obliga a cambiarla.
 */
class AdministrarUsuarios
{
    /** Datos editables por un administrador. */
    private const CAMPOS = ['nombre', 'apellidos', 'email', 'telefono'];

    /** Datos que la propia cuenta puede editar en Mi Perfil. */
    public const CAMPOS_PERFIL = ['email', 'telefono'];

    public function __construct(
        private readonly DestinoSql $destino,
        private readonly Auditor $auditor,
        private readonly Autorizacion $autorizacion,
    ) {}

    /** @param array{usuario: string, nombre: string, apellidos: string, email?: string|null, telefono?: string|null, password: string, activo: bool} $datos */
    public function crear(array $datos): int
    {
        $db = $this->destino->exigirEscritura();

        return $this->unicos(fn (): int => $db->transaction(function () use ($db, $datos): int {
            $this->exigirUnicos($db, $datos['usuario'], $datos['email'] ?? null);

            $fila = ['usuario' => $datos['usuario']];
            foreach (self::CAMPOS as $campo) {
                $fila[$campo] = $this->texto($datos[$campo] ?? null);
            }
            $id = (int) $db->table('adm_usuarios')->insertGetId([
                ...$fila,
                'password' => Hash::make($datos['password']),
                'activo' => $datos['activo'],
                'debe_cambiar_password' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $cambios = array_map(fn ($valor) => [null, $valor], array_filter($fila, fn ($valor) => $valor !== null));
            $this->auditor->registrar($db, 'adm_usuarios', (string) $id, 'CREAR', [
                ...$cambios,
                'password' => [null, Auditor::OCULTO],
                'activo' => [null, $datos['activo']],
                'debe_cambiar_password' => [null, true],
            ]);

            return $id;
        }));
    }

    /**
     * Devuelve false si no había cambios. Una contraseña nueva obliga a
     * cambiarla en el siguiente inicio de sesión.
     *
     * @param  array{nombre: string, apellidos: string, email?: string|null, telefono?: string|null, password?: string|null}  $datos
     */
    public function actualizar(int $id, array $datos): bool
    {
        $db = $this->destino->exigirEscritura();

        return $this->unicos(fn (): bool => $db->transaction(function () use ($db, $id, $datos): bool {
            $usuario = $this->buscar($db, $id);
            $nuevos = [];
            foreach (self::CAMPOS as $campo) {
                $nuevos[$campo] = $this->texto($datos[$campo] ?? null);
            }
            $this->exigirUnicos($db, null, $nuevos['email'], $id);

            [$cambios, $auditoria] = $this->diferencias($usuario, $nuevos);
            if (filled($datos['password'] ?? null)) {
                $cambios['password'] = Hash::make((string) $datos['password']);
                $cambios['debe_cambiar_password'] = true;
                $auditoria['password'] = [Auditor::OCULTO, Auditor::OCULTO];
                $auditoria['debe_cambiar_password'] = [(bool) $usuario->debe_cambiar_password, true];
            }
            if ($cambios === []) {
                return false;
            }

            $db->table('adm_usuarios')->where('id', $id)->update([...$cambios, 'updated_at' => now()]);
            $this->auditor->registrar($db, 'adm_usuarios', (string) $id, 'EDITAR', $auditoria);

            return true;
        }));
    }

    /**
     * Habilita o deshabilita una cuenta. Nadie deshabilita su propia cuenta ni
     * deja el sistema sin un administrador activo. Devuelve false si no cambió.
     */
    public function cambiarEstado(int $id, bool $activo, int $actorId): bool
    {
        $db = $this->destino->exigirEscritura();

        return $db->transaction(function () use ($db, $id, $activo, $actorId): bool {
            $usuario = $this->buscar($db, $id);
            if ((bool) $usuario->activo === $activo) {
                return false;
            }
            if (! $activo) {
                if ($id === $actorId) {
                    throw ValidationException::withMessages(['activo' => 'No puede deshabilitar su propia cuenta.']);
                }
                if ($this->autorizacion->quedariaSinAdministradores($db, $id)) {
                    throw ValidationException::withMessages(['activo' => 'Debe permanecer al menos un administrador activo.']);
                }
            }

            $db->table('adm_usuarios')->where('id', $id)->update(['activo' => $activo, 'updated_at' => now()]);
            $this->auditor->registrar($db, 'adm_usuarios', (string) $id, $activo ? 'HABILITAR' : 'DESHABILITAR', [
                'activo' => [! $activo, $activo],
            ]);

            return true;
        });
    }

    /**
     * Mi Perfil: la cuenta actualiza solo sus datos de contacto.
     *
     * @param  array{email?: string|null, telefono?: string|null}  $datos
     */
    public function actualizarContacto(int $id, array $datos): bool
    {
        $db = $this->destino->exigirEscritura();

        return $this->unicos(fn (): bool => $db->transaction(function () use ($db, $id, $datos): bool {
            $usuario = $this->buscar($db, $id);
            abort_unless((bool) $usuario->activo, 403, 'La cuenta no está activa.');
            $nuevos = [];
            foreach (self::CAMPOS_PERFIL as $campo) {
                $nuevos[$campo] = $this->texto($datos[$campo] ?? null);
            }
            $this->exigirUnicos($db, null, $nuevos['email'], $id);

            [$cambios, $auditoria] = $this->diferencias($usuario, $nuevos);
            if ($cambios === []) {
                return false;
            }

            $db->table('adm_usuarios')->where('id', $id)->update([...$cambios, 'updated_at' => now()]);
            $this->auditor->registrar($db, 'adm_usuarios', (string) $id, 'EDITAR', $auditoria);

            return true;
        }));
    }

    /**
     * @param  array<string, string|null>  $nuevos
     * @return array{0: array<string, string|null>, 1: array<string, array{0: mixed, 1: string|null}>}
     */
    private function diferencias(stdClass $usuario, array $nuevos): array
    {
        $cambios = [];
        $auditoria = [];
        foreach ($nuevos as $campo => $valor) {
            if ($valor !== $usuario->{$campo}) {
                $cambios[$campo] = $valor;
                $auditoria[$campo] = [$usuario->{$campo}, $valor];
            }
        }

        return [$cambios, $auditoria];
    }

    /** Comprobación legible; la restricción única de la base es la garantía final. */
    private function exigirUnicos(Connection $db, ?string $usuario, ?string $email, ?int $excepto = null): void
    {
        $errores = [];
        if ($usuario !== null && $db->table('adm_usuarios')->where('usuario', $usuario)->lockForUpdate()->exists()) {
            $errores['usuario'] = 'El nombre de usuario ya existe.';
        }
        if ($email !== null && $db->table('adm_usuarios')->where('email', $email)
            ->when($excepto !== null, fn ($consulta) => $consulta->where('id', '<>', $excepto))
            ->lockForUpdate()->exists()) {
            $errores['email'] = 'El correo ya está registrado en otra cuenta.';
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Una inserción concurrente puede adelantarse a la comprobación: la
     * restricción única la rechaza y se informa como error de validación.
     *
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     */
    private function unicos(callable $operacion): mixed
    {
        try {
            return $operacion();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['usuario' => 'El usuario o el correo ya están registrados.']);
        }
    }

    private function buscar(Connection $db, int $id): stdClass
    {
        $usuario = $db->table('adm_usuarios')->where('id', $id)->lockForUpdate()
            ->first(['id', 'usuario', 'activo', 'debe_cambiar_password', ...self::CAMPOS]);
        abort_unless($usuario !== null, 404, 'La cuenta no existe.');

        return $usuario;
    }

    private function texto(mixed $valor): ?string
    {
        $valor = is_string($valor) ? trim($valor) : null;

        return $valor === '' ? null : $valor;
    }
}
