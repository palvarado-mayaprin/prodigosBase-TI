<?php

namespace App\Auditoria;

use App\Models\Usuario;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use LogicException;

/**
 * Única vía de escritura de adm_auditoria.
 *
 * - Exige una transacción abierta en la misma conexión que el cambio: si la
 *   auditoría falla, la excepción revierte también la operación auditada.
 * - Usuario e IP salen de la sesión y de la petición, nunca de datos del
 *   formulario. Desde la consola quedan vacíos.
 * - Los valores de claves sensibles (contraseñas, tokens, secretos) se
 *   enmascaran antes de guardarse; la fecha la pone SQL Server en UTC.
 */
class Auditor
{
    public const ACCIONES = ['CREAR', 'EDITAR', 'HABILITAR', 'DESHABILITAR', 'ASIGNAR', 'RETIRAR', 'REVOCAR'];

    public const OCULTO = '********';

    /** Fragmentos de nombre de clave cuyo valor nunca se audita. */
    private const SENSIBLES = ['password', 'contrasena', 'clave', 'token', 'secret', 'secreto'];

    /**
     * @param  array<string, mixed>  $cambios  Por campo, normalmente [anterior, nuevo].
     */
    public function registrar(Connection $db, string $entidad, ?string $registro, string $accion, array $cambios = []): void
    {
        if ($db->transactionLevel() === 0) {
            throw new LogicException('La auditoría exige una transacción abierta en la conexión del cambio.');
        }
        if (! in_array($accion, self::ACCIONES, true)) {
            throw new InvalidArgumentException("Acción de auditoría no válida: $accion");
        }

        $usuario = Auth::user();
        $request = app()->runningInConsole() ? null : request();

        $db->table('adm_auditoria')->insert([
            'usuario_id' => $usuario instanceof Usuario ? $usuario->id : null,
            'usuario' => $usuario instanceof Usuario ? $usuario->usuario : null,
            'ip' => $request?->ip(),
            'entidad' => $entidad,
            'registro' => $registro,
            'accion' => $accion,
            'cambios' => $cambios === [] ? null : json_encode($this->enmascarar($cambios), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $datos
     * @return array<array-key, mixed>
     */
    public function enmascarar(array $datos): array
    {
        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && $this->esSensible($clave)) {
                $datos[$clave] = self::OCULTO;
            } elseif (is_array($valor)) {
                $datos[$clave] = $this->enmascarar($valor);
            }
        }

        return $datos;
    }

    private function esSensible(string $clave): bool
    {
        $clave = strtolower($clave);
        foreach (self::SENSIBLES as $fragmento) {
            if (str_contains($clave, $fragmento)) {
                return true;
            }
        }

        return false;
    }
}
