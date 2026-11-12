<?php

namespace Tests\Feature\Autorizacion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/** adm:crear-administrador: transacción única, sin duplicados ni contraseñas visibles. */
class CrearAdministradorTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private const TEMPORAL = 'Temporal-Segura-2026';

    private function crear(string $usuario = 'admin.inicial', string $clave = self::TEMPORAL, ?string $confirmacion = null, string $grupo = 'Administradores'): PendingCommand
    {
        return $this->artisan('adm:crear-administrador', [
            '--usuario' => $usuario, '--nombre' => 'Ana', '--apellidos' => 'Pérez', '--grupo' => $grupo,
        ])
            ->expectsQuestion('Contraseña temporal', $clave)
            ->expectsQuestion('Repita la contraseña', $confirmacion ?? $clave);
    }

    public function test_crea_grupo_usuario_y_membresia_auditados_con_cambio_obligatorio(): void
    {
        $this->crear()->doesntExpectOutputToContain(self::TEMPORAL)->assertSuccessful();

        $grupo = DB::table('adm_grupos')->sole();
        $usuario = DB::table('adm_usuarios')->sole();
        $this->assertSame(['Administradores', 1, 1], [$grupo->nombre, (int) $grupo->es_administrador, (int) $grupo->activo]);
        $this->assertTrue(Hash::check(self::TEMPORAL, $usuario->password));
        $this->assertSame([1, 1], [(int) $usuario->activo, (int) $usuario->debe_cambiar_password]);
        $this->assertSame(1, DB::table('adm_usuarios_grupos')->where(['usuario_id' => $usuario->id, 'grupo_id' => $grupo->id])->count());

        $auditoria = DB::table('adm_auditoria')->orderBy('id')->pluck('accion', 'entidad')->all();
        $this->assertSame(['adm_grupos' => 'CREAR', 'adm_usuarios' => 'CREAR', 'adm_usuarios_grupos' => 'ASIGNAR'], $auditoria);
        $this->assertStringNotContainsString(self::TEMPORAL, (string) DB::table('adm_auditoria')->pluck('cambios')->implode(''));
    }

    public function test_el_administrador_creado_inicia_sesion_y_debe_cambiar_la_contrasena(): void
    {
        $this->crear()->assertSuccessful();

        $this->post(route('login.store'), ['usuario' => 'admin.inicial', 'password' => self::TEMPORAL]);
        $this->assertAuthenticated();
        $this->get(route('dashboard'))->assertRedirect(route('contrasena.editar'));
    }

    public function test_reutiliza_el_grupo_administrador_existente_y_no_duplica_cuentas(): void
    {
        $this->crear()->assertSuccessful();
        $this->crear('admin.segundo')->assertSuccessful();
        $this->assertSame(1, DB::table('adm_grupos')->count());
        $this->assertSame(2, DB::table('adm_usuarios_grupos')->count());

        $auditorias = DB::table('adm_auditoria')->count();
        $this->crear('admin.segundo')->expectsOutputToContain('Ya existe la cuenta')->assertFailed();
        $this->assertSame(2, DB::table('adm_usuarios')->count());
        $this->assertSame($auditorias, DB::table('adm_auditoria')->count());
    }

    public function test_no_usa_un_grupo_existente_que_no_es_administrador_y_no_deja_nada_parcial(): void
    {
        $this->crearGrupo('Consulta');

        $this->crear(grupo: 'Consulta')->expectsOutputToContain('no es un grupo administrador')->assertFailed();
        $this->assertSame(0, DB::table('adm_usuarios')->count());
        $this->assertSame(0, DB::table('adm_auditoria')->count());
    }

    public function test_exige_contrasena_segura_y_confirmada(): void
    {
        $this->crear(clave: 'debil')->assertFailed();
        $this->crear(confirmacion: 'Otra-Distinta-2026')->assertFailed();
        $this->assertSame(0, DB::table('adm_usuarios')->count());
        $this->assertSame(0, DB::table('adm_grupos')->count());
    }

    public function test_no_escribe_con_produccion_en_solo_lectura(): void
    {
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', false);

        $this->artisan('adm:crear-administrador', ['--usuario' => 'x'])
            ->expectsOutputToContain('Escritura no permitida')->assertFailed();
        $this->assertSame(0, DB::table('adm_usuarios')->count());
    }
}
