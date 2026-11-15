<?php

namespace App\Services\Admin;

use App\Auditoria\Auditor;
use App\Entorno\DestinoSql;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Cambio de la contraseña propia (cambio obligatorio y, en la Etapa 5, Mi
 * Perfil). La política de contraseñas la valida el controlador con
 * Password::defaults(); aquí se comprueban la contraseña actual y que la nueva
 * sea distinta, dentro de la transacción que escribe y audita. En la misma
 * transacción se revocan las demás sesiones abiertas de la cuenta
 * (AdministrarSesiones, con su auditoría); la sesión desde la que se cambia
 * sigue activa. Si el cambio falla, no se revoca nada.
 */
class CambiarContrasena
{
    public function __construct(
        private readonly DestinoSql $destino,
        private readonly Auditor $auditor,
        private readonly AdministrarSesiones $sesiones,
    ) {}

    /** @param  int|null  $sesionActual  Fila de adm_sesiones de la sesión que hace el cambio (RegistroSesiones::actual()). */
    public function cambiar(Usuario $usuario, string $actual, string $nueva, ?int $sesionActual): void
    {
        $db = $this->destino->exigirEscritura();

        $db->transaction(function () use ($db, $usuario, $actual, $nueva, $sesionActual): void {
            $fila = $db->table('adm_usuarios')->where('id', $usuario->id)->lockForUpdate()
                ->first(['password', 'activo', 'debe_cambiar_password']);
            abort_unless($fila !== null && $fila->activo, 403, 'La cuenta no está activa.');

            if (! Hash::check($actual, $fila->password)) {
                throw ValidationException::withMessages(['contrasena_actual' => 'La contraseña actual no es correcta.']);
            }
            if (Hash::check($nueva, $fila->password)) {
                throw ValidationException::withMessages(['password' => 'La nueva contraseña debe ser distinta de la actual.']);
            }

            $db->table('adm_usuarios')->where('id', $usuario->id)->update([
                'password' => Hash::make($nueva),
                'debe_cambiar_password' => false,
                'updated_at' => now(),
            ]);
            $this->auditor->registrar($db, 'adm_usuarios', (string) $usuario->id, 'EDITAR', [
                'password' => [Auditor::OCULTO, Auditor::OCULTO],
                'debe_cambiar_password' => [(bool) $fila->debe_cambiar_password, false],
            ]);
            $this->sesiones->revocarDeUsuario($usuario->id, $usuario->id, $sesionActual);
        });
    }
}
