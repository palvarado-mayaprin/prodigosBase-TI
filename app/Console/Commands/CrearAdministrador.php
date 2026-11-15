<?php

namespace App\Console\Commands;

use App\Auditoria\Auditor;
use App\Entorno\DestinoSql;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnexpectedValueException;

/**
 * Crea una cuenta administradora, su grupo administrador (si no existe) y la
 * membresía en una sola transacción auditada.
 *
 * La contraseña se pide oculta y dos veces; nunca se acepta como opción (no
 * queda en el historial de la consola) ni se muestra o registra. Para una
 * instalación desatendida, --leer-contrasena la lee de una sola línea de la
 * entrada estándar (en Windows la doble lectura oculta no funciona con la
 * entrada redirigida). La cuenta queda con debe_cambiar_password = 1. No crea
 * duplicados.
 */
class CrearAdministrador extends Command
{
    protected $signature = 'adm:crear-administrador
        {--usuario= : Nombre de usuario (inicio de sesión)}
        {--nombre= : Nombre}
        {--apellidos= : Apellidos}
        {--email= : Correo electrónico (opcional)}
        {--grupo=Administradores : Grupo administrador al que se añade}
        {--leer-contrasena : Lee la contraseña temporal de una línea de la entrada estándar (instalación desatendida)}';

    protected $description = 'Crea una cuenta administradora con contraseña temporal (cambio obligatorio)';

    public function handle(DestinoSql $destino, Auditor $auditor): int
    {
        try {
            $db = $destino->exigirEscritura();
        } catch (HttpException) {
            $this->error('Escritura no permitida: '.$destino->motivoBloqueo());

            return self::FAILURE;
        }

        $clave = $this->option('leer-contrasena') ? $this->leerLinea() : null;
        $datos = [
            'usuario' => $this->option('usuario') ?: $this->ask('Usuario'),
            'nombre' => $this->option('nombre') ?: $this->ask('Nombre'),
            'apellidos' => $this->option('apellidos') ?: $this->ask('Apellidos'),
            'email' => $this->option('email') ?: null,
            'grupo' => $this->option('grupo'),
            'password' => $clave ?? $this->secret('Contraseña temporal'),
            'password_confirmation' => $clave ?? $this->secret('Repita la contraseña'),
        ];

        $validador = Validator::make($datos, [
            'usuario' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'nombre' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'grupo' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);
        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $mensaje) {
                $this->error($mensaje);
            }

            return self::FAILURE;
        }

        try {
            $db->transaction(function () use ($db, $auditor, $datos): void {
                if ($db->table('adm_usuarios')->where('usuario', $datos['usuario'])->lockForUpdate()->exists()) {
                    throw new UnexpectedValueException("Ya existe la cuenta «{$datos['usuario']}».");
                }

                $grupo = $db->table('adm_grupos')->where('nombre', $datos['grupo'])->lockForUpdate()->first();
                if ($grupo === null) {
                    $grupoId = (int) $db->table('adm_grupos')->insertGetId([
                        'nombre' => $datos['grupo'], 'descripcion' => 'Acceso completo a la administración.',
                        'es_administrador' => true, 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $auditor->registrar($db, 'adm_grupos', (string) $grupoId, 'CREAR', [
                        'nombre' => [null, $datos['grupo']], 'es_administrador' => [null, true],
                    ]);
                } elseif (! $grupo->es_administrador || ! $grupo->activo) {
                    throw new UnexpectedValueException("El grupo «{$datos['grupo']}» existe pero no es un grupo administrador activo.");
                } else {
                    $grupoId = (int) $grupo->id;
                }

                $usuarioId = (int) $db->table('adm_usuarios')->insertGetId([
                    'usuario' => $datos['usuario'], 'nombre' => $datos['nombre'], 'apellidos' => $datos['apellidos'],
                    'email' => $datos['email'], 'password' => Hash::make($datos['password']),
                    'activo' => true, 'debe_cambiar_password' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $auditor->registrar($db, 'adm_usuarios', (string) $usuarioId, 'CREAR', [
                    'usuario' => [null, $datos['usuario']], 'nombre' => [null, $datos['nombre']],
                    'apellidos' => [null, $datos['apellidos']], 'email' => [null, $datos['email']],
                    'password' => [null, Auditor::OCULTO], 'debe_cambiar_password' => [null, true],
                ]);

                $db->table('adm_usuarios_grupos')->insert(['usuario_id' => $usuarioId, 'grupo_id' => $grupoId, 'created_at' => now()]);
                $auditor->registrar($db, 'adm_usuarios_grupos', "$usuarioId:$grupoId", 'ASIGNAR');
            });
        } catch (UnexpectedValueException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Cuenta «{$datos['usuario']}» creada en el grupo «{$datos['grupo']}». Deberá cambiar la contraseña al iniciar sesión.");

        return self::SUCCESS;
    }

    /**
     * Una línea de la entrada estándar (o del flujo asignado en las pruebas),
     * sin el salto final ni la marca BOM que antepone PowerShell 5.1 al
     * canalizar texto a un programa.
     */
    private function leerLinea(): string
    {
        $flujo = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $linea = fgets($flujo ?? STDIN);
        if ($linea === false) {
            return '';
        }

        return rtrim(str_starts_with($linea, "\u{FEFF}") ? substr($linea, 3) : $linea, "\r\n");
    }
}
