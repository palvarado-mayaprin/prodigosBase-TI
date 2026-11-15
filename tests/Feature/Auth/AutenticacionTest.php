<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/** Inicio y cierre de sesión con adm_usuarios (Fortify, solo login y logout). */
class AutenticacionTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private function login(string $usuario, string $clave, array $extra = []): TestResponse
    {
        return $this->post(route('login.store'), ['usuario' => $usuario, 'password' => $clave, ...$extra]);
    }

    public function test_la_pantalla_de_inicio_y_la_redireccion_de_invitados(): void
    {
        $this->get(route('login'))->assertOk()->assertInertia(fn ($pagina) => $pagina->component('auth/Login'));
        $this->get('/')->assertRedirect('/dashboard');
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_inicia_sesion_con_usuario_y_contrasena_y_regenera_la_sesion(): void
    {
        $usuario = $this->crearUsuario();
        $this->get(route('login'));
        $sesionAnterior = session()->getId();

        $this->login('usuario.prueba', self::CLAVE)->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($usuario);
        $this->assertNotSame($sesionAnterior, session()->getId());
        $this->get(route('dashboard'))->assertOk()
            ->assertInertia(fn ($pagina) => $pagina->where('auth.user.usuario', 'usuario.prueba')
                ->where('auth.user.name', 'Nombre Apellido')
                ->missing('auth.user.password'));
    }

    public function test_la_contrasena_se_guarda_como_hash(): void
    {
        $this->crearUsuario();

        $guardada = (string) DB::table('adm_usuarios')->value('password');
        $this->assertNotSame(self::CLAVE, $guardada);
        $this->assertStringStartsWith('$2y$', $guardada);
    }

    public function test_rechaza_credenciales_incorrectas_cuentas_inexistentes_e_inactivas(): void
    {
        $this->crearUsuario();
        $this->crearUsuario('inactivo', ['activo' => false]);

        $this->login('usuario.prueba', 'Otra-Clave-2026')->assertSessionHasErrors('usuario');
        $this->login('no.existe', self::CLAVE)->assertSessionHasErrors('usuario');
        $this->login('inactivo', self::CLAVE)->assertSessionHasErrors('usuario');
        $this->assertGuest();
    }

    public function test_registra_el_ultimo_acceso_solo_tras_un_inicio_de_sesion_correcto(): void
    {
        $this->travelTo(now('UTC')->startOfSecond());
        $usuario = $this->crearUsuario();
        $antes = DB::table('adm_usuarios')->find($usuario->id);

        // Los intentos fallidos no escriben.
        $this->login('usuario.prueba', 'Otra-Clave-2026')->assertSessionHasErrors('usuario');
        $this->assertNull(DB::table('adm_usuarios')->where('id', $usuario->id)->value('ultimo_acceso_utc'));

        $this->travel(5)->minutes();
        $this->login('usuario.prueba', self::CLAVE)->assertRedirect('/dashboard');
        $fila = DB::table('adm_usuarios')->find($usuario->id);
        $sesion = DB::table('adm_sesiones')->sole();
        // UTC, el mismo instante que el inicio de la sesión y sin tocar updated_at.
        $this->assertSame(now('UTC')->format('Y-m-d H:i:s'), substr((string) $fila->ultimo_acceso_utc, 0, 19));
        $this->assertSame($sesion->inicio_utc, $fila->ultimo_acceso_utc);
        $this->assertSame($antes->updated_at, $fila->updated_at);
        $this->assertSame(0, DB::table('adm_auditoria')->count());

        // Las peticiones siguientes no lo vuelven a escribir.
        $this->travel(2)->minutes();
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame($fila->ultimo_acceso_utc, DB::table('adm_usuarios')->where('id', $usuario->id)->value('ultimo_acceso_utc'));
    }

    public function test_una_sesion_registrada_sin_inicio_de_sesion_no_es_un_acceso(): void
    {
        // ValidarSesion registra tarde la sesión que no tenía fila (p. ej. tras
        // volver a permitirse la escritura): no hubo credenciales nuevas.
        $this->actingAs($this->crearUsuario());
        $this->get(route('dashboard'))->assertOk();

        $this->assertSame(1, DB::table('adm_sesiones')->count());
        $this->assertNull(DB::table('adm_usuarios')->value('ultimo_acceso_utc'));
    }

    public function test_no_admite_recordarme(): void
    {
        $this->crearUsuario();

        $this->login('usuario.prueba', self::CLAVE, ['remember' => 'on'])->assertSessionHasErrors('usuario');
        $this->assertGuest();
    }

    public function test_limita_los_intentos_de_inicio_de_sesion(): void
    {
        $this->crearUsuario();
        for ($i = 0; $i < 5; $i++) {
            $this->login('usuario.prueba', 'Incorrecta-2026');
        }

        $this->login('usuario.prueba', self::CLAVE)->assertStatus(429);
        $this->assertGuest();
    }

    public function test_exige_csrf_fuera_de_las_pruebas(): void
    {
        $this->crearUsuario();
        $this->app['env'] = 'local';

        $this->login('usuario.prueba', self::CLAVE)->assertStatus(419);
        $this->assertGuest();
    }

    public function test_cierra_la_sesion(): void
    {
        $this->actingAs($this->crearUsuario());

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_inicia_y_cierra_sesion_con_produccion_en_solo_lectura(): void
    {
        $this->crearUsuario();
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', false);
        $antes = DB::table('adm_usuarios')->first();

        $this->login('usuario.prueba', self::CLAVE)->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
        // El inicio de sesión no escribe en la base.
        $this->assertEquals($antes, DB::table('adm_usuarios')->first());
        $this->assertSame(0, DB::table('adm_auditoria')->count());
    }

    public function test_las_funciones_del_starter_kit_no_existen(): void
    {
        foreach (['/register', '/forgot-password', '/reset-password/token', '/email/verify',
            '/two-factor-challenge', '/user/confirm-password', '/settings/profile'] as $ruta) {
            $this->get($ruta)->assertNotFound();
        }
        $this->withoutMiddleware(PreventRequestForgery::class)->post('/register', [])->assertNotFound();
    }
}
