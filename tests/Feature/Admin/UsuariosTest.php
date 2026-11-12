<?php

namespace Tests\Feature\Admin;

use App\Entorno\DestinoSql;
use App\Models\Usuario;
use App\Services\Admin\AdministrarUsuarios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/** Administración de Usuarios (administracion.accesos.usuarios). */
class UsuariosTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private const NUEVA = 'Clave-Nueva-Segura-26';

    private const ALTA = [
        'usuario' => 'ana.lopez', 'nombre' => 'Ana', 'apellidos' => 'López',
        'email' => 'ana@example.com', 'telefono' => '5555-0000',
        'password' => self::NUEVA, 'password_confirmation' => self::NUEVA, 'activo' => true,
    ];

    private Usuario $admin;

    private int $grupoAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('admin');
        $this->grupoAdmin = $this->crearGrupo('Administradores', administrador: true);
        $this->agregarAGrupo($this->admin, $this->grupoAdmin);
        $this->artisan('adm:sincronizar-permisos')->assertSuccessful();
        DB::table('adm_auditoria')->delete();
        $this->actingAs($this->admin);
    }

    /** @param array<string, mixed> $datos */
    private function actualizar(Usuario $usuario, array $datos): TestResponse
    {
        return $this->put(route('admin.usuarios.actualizar', $usuario->id), [
            'nombre' => $usuario->nombre, 'apellidos' => $usuario->apellidos, ...$datos,
        ]);
    }

    private function auditorias(): array
    {
        return DB::table('adm_auditoria')->orderBy('id')->get()->all();
    }

    public function test_solo_los_administradores_acceden(): void
    {
        $comun = $this->crearUsuario('comun');
        $grupo = $this->crearGrupo('Soporte');
        $this->agregarAGrupo($comun, $grupo);
        // Asignar la funcionalidad no basta: es soloAdministrador.
        $this->asignarPermiso($grupo, 'administracion.accesos.usuarios:VER');

        $this->actingAs($comun)->get(route('admin.usuarios'))->assertForbidden();
        $this->post(route('admin.usuarios.crear'), self::ALTA)->assertForbidden();
        $this->assertNull(DB::table('adm_usuarios')->where('usuario', 'ana.lopez')->first());

        $this->post(route('logout'));
        $this->get(route('admin.usuarios'))->assertRedirect(route('login'));
    }

    public function test_lista_las_cuentas_sin_contrasenas(): void
    {
        $this->crearUsuario('beto', ['activo' => false, 'debe_cambiar_password' => true]);

        $this->get(route('admin.usuarios'))->assertOk()->assertInertia(fn ($pagina) => $pagina
            ->component('admin/Usuarios')
            ->where('actorId', $this->admin->id)
            ->has('usuarios', 2)
            ->where('usuarios.1.usuario', 'beto')
            ->where('usuarios.1.activo', false)
            ->where('usuarios.1.debeCambiarPassword', true)
            ->missing('usuarios.0.password'));
    }

    public function test_crea_cuentas_con_hash_cambio_obligatorio_y_auditoria_sin_contrasena(): void
    {
        $this->post(route('admin.usuarios.crear'), self::ALTA)->assertSessionHasNoErrors()->assertRedirect();

        $fila = DB::table('adm_usuarios')->where('usuario', 'ana.lopez')->first();
        $this->assertTrue(Hash::check(self::NUEVA, $fila->password));
        $this->assertSame([1, 1], [(int) $fila->activo, (int) $fila->debe_cambiar_password]);

        [$auditoria] = $this->auditorias();
        $this->assertSame(['adm_usuarios', (string) $fila->id, 'CREAR', $this->admin->id],
            [$auditoria->entidad, $auditoria->registro, $auditoria->accion, (int) $auditoria->usuario_id]);
        $this->assertStringNotContainsString(self::NUEVA, $auditoria->cambios);
        $this->assertStringNotContainsString('$2y$', $auditoria->cambios);

        // La cuenta nueva inicia sesión y debe cambiar la contraseña.
        $this->post(route('logout'));
        $this->post(route('login.store'), ['usuario' => 'ana.lopez', 'password' => self::NUEVA]);
        $this->get(route('dashboard'))->assertRedirect(route('contrasena.editar'));
    }

    public function test_valida_el_alta(): void
    {
        $this->post(route('admin.usuarios.crear'), [])
            ->assertSessionHasErrors(['usuario', 'nombre', 'apellidos', 'password']);
        $this->post(route('admin.usuarios.crear'), [...self::ALTA, 'usuario' => 'con espacios'])->assertSessionHasErrors('usuario');
        $this->post(route('admin.usuarios.crear'), [...self::ALTA, 'email' => 'no-es-correo'])->assertSessionHasErrors('email');
        $this->post(route('admin.usuarios.crear'), [...self::ALTA, 'password' => 'debil', 'password_confirmation' => 'debil'])
            ->assertSessionHasErrors('password');
        $this->post(route('admin.usuarios.crear'), [...self::ALTA, 'password_confirmation' => 'Otra-Clave-Distinta-1'])
            ->assertSessionHasErrors('password');

        $this->assertSame(1, DB::table('adm_usuarios')->count());
        $this->assertSame([], $this->auditorias());
    }

    public function test_usuario_y_correo_son_unicos(): void
    {
        $this->crearUsuario('existente', ['email' => 'ocupado@example.com']);

        $this->post(route('admin.usuarios.crear'), [...self::ALTA, 'usuario' => 'existente'])
            ->assertSessionHasErrors(['usuario' => 'El nombre de usuario ya existe.']);
        $this->post(route('admin.usuarios.crear'), [...self::ALTA, 'email' => 'ocupado@example.com'])
            ->assertSessionHasErrors(['email' => 'El correo ya está registrado en otra cuenta.']);
        $this->actualizar($this->admin, ['email' => 'ocupado@example.com'])->assertSessionHasErrors('email');

        // Varias cuentas pueden no tener correo.
        $this->post(route('admin.usuarios.crear'), [...self::ALTA, 'email' => ''])->assertSessionHasNoErrors();
        $this->assertSame([], array_filter($this->auditorias(), fn ($a) => $a->accion !== 'CREAR'));
    }

    public function test_edita_datos_audita_las_diferencias_y_no_cambia_el_usuario(): void
    {
        $ana = $this->crearUsuario('ana');

        $this->actualizar($ana, ['nombre' => 'Ana María', 'email' => 'ana@example.com'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Usuario actualizado.');
        $this->actualizar($ana->fresh(), ['email' => 'ana@example.com'])->assertSessionHas('status', 'Sin cambios.');
        $this->actualizar($ana, ['usuario' => 'otro'])->assertSessionHasErrors('usuario');

        $fila = DB::table('adm_usuarios')->find($ana->id);
        $this->assertSame(['ana', 'Ana María', 'ana@example.com'], [$fila->usuario, $fila->nombre, $fila->email]);
        [$auditoria] = $this->auditorias();
        $this->assertSame(['nombre' => ['Nombre', 'Ana María'], 'email' => [null, 'ana@example.com']], json_decode($auditoria->cambios, true));
        $this->assertCount(1, $this->auditorias());
    }

    public function test_restablecer_la_contrasena_obliga_a_cambiarla(): void
    {
        $ana = $this->crearUsuario('ana');

        // Vacía: se conserva la actual.
        $this->actualizar($ana, ['password' => '', 'password_confirmation' => ''])->assertSessionHas('status', 'Sin cambios.');
        $this->assertTrue(Hash::check(self::CLAVE, (string) DB::table('adm_usuarios')->where('id', $ana->id)->value('password')));

        $this->actualizar($ana, ['password' => self::NUEVA, 'password_confirmation' => self::NUEVA])->assertSessionHasNoErrors();
        $fila = DB::table('adm_usuarios')->find($ana->id);
        $this->assertTrue(Hash::check(self::NUEVA, $fila->password));
        $this->assertSame(1, (int) $fila->debe_cambiar_password);
        $cambios = json_decode($this->auditorias()[0]->cambios, true);
        $this->assertSame('********', $cambios['password']);
    }

    public function test_habilita_y_deshabilita_con_auditoria_y_bloquea_el_inicio_de_sesion(): void
    {
        $ana = $this->crearUsuario('ana');

        $this->put(route('admin.usuarios.estado', $ana->id), ['activo' => false])->assertSessionHasNoErrors();
        $this->assertSame(0, (int) DB::table('adm_usuarios')->where('id', $ana->id)->value('activo'));
        $this->put(route('admin.usuarios.estado', $ana->id), ['activo' => true])->assertSessionHasNoErrors();
        $this->assertSame(['DESHABILITAR', 'HABILITAR'], array_column($this->auditorias(), 'accion'));

        $this->put(route('admin.usuarios.estado', $ana->id), ['activo' => false]);
        $this->post(route('logout'));
        $this->post(route('login.store'), ['usuario' => 'ana', 'password' => self::CLAVE])->assertSessionHasErrors('usuario');
        $this->assertGuest();
    }

    public function test_nadie_deshabilita_su_propia_cuenta(): void
    {
        $this->put(route('admin.usuarios.estado', $this->admin->id), ['activo' => false])
            ->assertSessionHasErrors(['activo' => 'No puede deshabilitar su propia cuenta.']);
        $this->assertSame(1, (int) DB::table('adm_usuarios')->where('id', $this->admin->id)->value('activo'));
    }

    public function test_el_servicio_nunca_deja_el_sistema_sin_un_administrador_activo(): void
    {
        // Por HTTP quien actúa ya es un administrador activo; la protección vive
        // en el servicio (también la usarán las membresías y la consola).
        $servicio = app(AdministrarUsuarios::class);
        try {
            $servicio->cambiarEstado($this->admin->id, false, actorId: 0);
            $this->fail('Debió impedirse dejar el sistema sin administradores.');
        } catch (ValidationException $error) {
            $this->assertSame('Debe permanecer al menos un administrador activo.', $error->errors()['activo'][0]);
        }
        $this->assertSame([], $this->auditorias());

        // Un administrador inactivo o de un grupo inactivo no cuenta.
        $inactivo = $this->crearUsuario('admin.inactivo', ['activo' => false]);
        $this->agregarAGrupo($inactivo, $this->grupoAdmin);
        $deGrupoInactivo = $this->crearUsuario('admin.otro.grupo');
        $this->agregarAGrupo($deGrupoInactivo, $this->crearGrupo('Administración B', administrador: true, activo: false));
        $this->expectException(ValidationException::class);
        $servicio->cambiarEstado($this->admin->id, false, actorId: 0);
    }

    public function test_con_otro_administrador_activo_se_puede_deshabilitar(): void
    {
        $otro = $this->crearUsuario('admin.dos');
        $this->agregarAGrupo($otro, $this->grupoAdmin);

        $this->put(route('admin.usuarios.estado', $otro->id), ['activo' => false])->assertSessionHasNoErrors();
        $this->assertSame(0, (int) DB::table('adm_usuarios')->where('id', $otro->id)->value('activo'));
    }

    public function test_cuenta_inexistente_y_produccion_en_solo_lectura(): void
    {
        $this->put(route('admin.usuarios.estado', 99999), ['activo' => false])->assertNotFound();

        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', false);
        $this->post(route('admin.usuarios.crear'), self::ALTA)->assertForbidden()->assertSee(DestinoSql::MENSAJE_SOLO_LECTURA);
        $this->get(route('admin.usuarios'))->assertOk();
        $this->assertNull(DB::table('adm_usuarios')->where('usuario', 'ana.lopez')->first());
    }
}
