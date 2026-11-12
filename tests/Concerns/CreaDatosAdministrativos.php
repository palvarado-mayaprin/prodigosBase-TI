<?php

namespace Tests\Concerns;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Datos ficticios sobre las tablas adm_* (requiere RefreshDatabase). */
trait CreaDatosAdministrativos
{
    protected const CLAVE = 'Clave-Ficticia-2026';

    /** @param array<string, mixed> $atributos */
    protected function crearUsuario(string $usuario = 'usuario.prueba', array $atributos = []): Usuario
    {
        $id = DB::table('adm_usuarios')->insertGetId([
            'usuario' => $usuario, 'nombre' => 'Nombre', 'apellidos' => 'Apellido',
            'password' => Hash::make(self::CLAVE), 'activo' => true, 'debe_cambiar_password' => false,
            'created_at' => now(), 'updated_at' => now(), ...$atributos,
        ]);

        return Usuario::query()->findOrFail($id);
    }

    protected function crearGrupo(string $nombre, bool $administrador = false, bool $activo = true): int
    {
        return (int) DB::table('adm_grupos')->insertGetId([
            'nombre' => $nombre, 'es_administrador' => $administrador, 'activo' => $activo,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function agregarAGrupo(Usuario $usuario, int $grupo): void
    {
        DB::table('adm_usuarios_grupos')->insert(['usuario_id' => $usuario->id, 'grupo_id' => $grupo, 'created_at' => now()]);
    }

    /** Asigna `codigo:ACCION` al grupo; sincroniza antes el catálogo si hace falta. */
    protected function asignarPermiso(int $grupo, string $permiso): void
    {
        if (DB::table('adm_funcionalidades')->doesntExist()) {
            $this->artisan('adm:sincronizar-permisos')->assertSuccessful();
        }
        [$codigo, $accion] = explode(':', $permiso);
        $permisoId = DB::table('adm_permisos as p')
            ->join('adm_funcionalidades as f', 'f.id', '=', 'p.funcionalidad_id')
            ->where('f.codigo', $codigo)->where('p.accion', $accion)->value('p.id');
        DB::table('adm_grupos_permisos')->insert(['grupo_id' => $grupo, 'permiso_id' => $permisoId, 'created_at' => now()]);
    }
}
