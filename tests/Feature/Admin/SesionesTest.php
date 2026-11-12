<?php

namespace Tests\Feature\Admin;

use App\Auditoria\Auditor;
use App\Entorno\DestinoSql;
use App\Http\Middleware\ValidarSesion;
use App\Models\Usuario;
use App\Services\Admin\AdministrarSesiones;
use App\Services\Admin\RegistroSesiones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/** Sesiones Activas (administracion.sesiones.activas) y su registro técnico. */
class SesionesTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private Usuario $admin;

    private Usuario $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('admin');
        $this->agregarAGrupo($this->admin, $this->crearGrupo('Administradores', administrador: true));
        $this->ana = $this->crearUsuario('ana');
        $this->artisan('adm:sincronizar-permisos')->assertSuccessful();
        DB::table('adm_auditoria')->delete();
    }

    private function iniciarSesion(string $usuario): void
    {
        $this->post(route('login.store'), ['usuario' => $usuario, 'password' => self::CLAVE])->assertSessionHasNoErrors();
    }

    /** Sesión abierta de otro equipo, creada directamente en el registro. */
    private function sesionAbierta(Usuario $usuario, array $atributos = []): int
    {
        return (int) DB::table('adm_sesiones')->insertGetId([
            'usuario_id' => $usuario->id, 'ip' => '10.0.0.5', 'agente' => 'Navegador de prueba',
            'inicio_utc' => now(), 'ultima_actividad_utc' => now(), ...$atributos,
        ]);
    }

    private function fila(int $id): object
    {
        return DB::table('adm_sesiones')->find($id);
    }

    public function test_registra_una_sesion_por_inicio_sin_identificadores_sensibles(): void
    {
        $this->iniciarSesion('ana');
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('dashboard'))->assertOk();

        $filas = DB::table('adm_sesiones')->get();
        $this->assertCount(1, $filas);
        $fila = $filas->first();
        $this->assertSame([$this->ana->id, '127.0.0.1', null, null], [(int) $fila->usuario_id, $fila->ip, $fila->cierre_utc, $fila->revocada_utc]);
        $this->assertNotNull($fila->agente);
        $this->assertSame((int) $fila->id, session(RegistroSesiones::CLAVE));

        // Ni el identificador de sesión ni la cookie llegan a la base.
        $volcado = json_encode($filas->all()).json_encode(DB::table('adm_auditoria')->get()->all());
        $this->assertStringNotContainsString(session()->getId(), $volcado);
    }

    public function test_cierre_normal(): void
    {
        $this->iniciarSesion('ana');
        $id = session(RegistroSesiones::CLAVE);

        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
        $fila = $this->fila($id);
        $this->assertNotNull($fila->cierre_utc);
        $this->assertNull($fila->revocada_utc);
    }

    public function test_lista_sesiones_con_referencias_opacas(): void
    {
        $this->iniciarSesion('admin');
        $otra = $this->sesionAbierta($this->ana);
        $vieja = $this->sesionAbierta($this->ana, ['ultima_actividad_utc' => now()->subDays(30), 'cierre_utc' => now()->subDays(30)]);

        $respuesta = $this->get(route('admin.sesiones'))->assertOk()->assertInertia(fn ($pagina) => $pagina
            ->component('admin/Sesiones')
            ->where('soloLectura', false)
            ->has('sesiones', 2)
            ->where('sesiones', fn ($sesiones) => collect($sesiones)->contains(fn ($s) => $s['actual'] && $s['usuario'] === 'admin')
                && collect($sesiones)->contains(fn ($s) => $s['referencia'] === AdministrarSesiones::referencia($otra) && $s['estado'] === 'activa' && $s['ip'] === '10.0.0.5')
                && collect($sesiones)->every(fn ($s) => ! array_key_exists('id', $s) && strlen($s['referencia']) === 32)));

        $this->assertStringNotContainsString(session()->getId(), $respuesta->getContent());
        $this->assertNotContains(AdministrarSesiones::referencia($vieja), array_column($respuesta->viewData('page')['props']['sesiones'], 'referencia'));
    }

    public function test_revoca_una_sesion_con_auditoria_y_conserva_el_historial(): void
    {
        $this->iniciarSesion('admin');
        $otra = $this->sesionAbierta($this->ana);

        $this->put(route('admin.sesiones.revocar', AdministrarSesiones::referencia($otra)))->assertSessionHasNoErrors()->assertRedirect();
        $fila = $this->fila($otra);
        $this->assertNotNull($fila->revocada_utc);
        $this->assertSame($this->admin->id, (int) $fila->revocada_por_id);
        $this->assertSame(1, DB::table('adm_sesiones')->where('id', $otra)->count());

        $auditoria = DB::table('adm_auditoria')->sole();
        $this->assertSame(['adm_sesiones', (string) $otra, 'REVOCAR', $this->admin->id],
            [$auditoria->entidad, $auditoria->registro, $auditoria->accion, (int) $auditoria->usuario_id]);
        $this->assertSame('ana', json_decode($auditoria->cambios, true)['usuario']);
        $this->assertStringNotContainsString('10.0.0.5', $auditoria->cambios);

        // Ya revocada (o inexistente): no hay nada que revocar.
        $this->put(route('admin.sesiones.revocar', AdministrarSesiones::referencia($otra)))->assertNotFound();
        $this->put(route('admin.sesiones.revocar', str_repeat('a', 32)))->assertNotFound();
        $this->put('/admin/sesiones/'.$otra.'/revocar')->assertNotFound();
    }

    public function test_no_revoca_la_propia_sesion(): void
    {
        $this->iniciarSesion('admin');
        $propia = session(RegistroSesiones::CLAVE);

        $this->put(route('admin.sesiones.revocar', AdministrarSesiones::referencia($propia)))
            ->assertSessionHasErrors(['sesion' => 'No puede revocar su propia sesión: use «Cerrar sesión».']);
        $this->assertNull($this->fila($propia)->revocada_utc);

        // Revocar las sesiones de la propia cuenta conserva la actual.
        $otraPropia = $this->sesionAbierta($this->admin);
        $this->put(route('admin.sesiones.revocar-usuario', $this->admin->id))->assertSessionHas('status', 'Se revocó 1 sesión.');
        $this->assertNull($this->fila($propia)->revocada_utc);
        $this->assertNotNull($this->fila($otraPropia)->revocada_utc);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_revoca_las_sesiones_de_una_cuenta(): void
    {
        $this->iniciarSesion('admin');
        $a = $this->sesionAbierta($this->ana);
        $b = $this->sesionAbierta($this->ana);
        $cerrada = $this->sesionAbierta($this->ana, ['cierre_utc' => now()]);

        $this->put(route('admin.sesiones.revocar-usuario', $this->ana->id))->assertSessionHas('status', 'Se revocaron 2 sesiones.');
        $this->assertNotNull($this->fila($a)->revocada_utc);
        $this->assertNotNull($this->fila($b)->revocada_utc);
        $this->assertNull($this->fila($cerrada)->revocada_utc);
        $this->assertSame(2, DB::table('adm_auditoria')->where('accion', 'REVOCAR')->count());

        $this->put(route('admin.sesiones.revocar-usuario', $this->ana->id))->assertSessionHas('status', 'No había otras sesiones abiertas.');
        $this->put(route('admin.sesiones.revocar-usuario', 99999))->assertNotFound();
    }

    public function test_una_sesion_revocada_se_rechaza_en_la_siguiente_peticion(): void
    {
        $this->iniciarSesion('ana');
        $this->get(route('dashboard'))->assertOk();
        $id = session(RegistroSesiones::CLAVE);

        app(AdministrarSesiones::class)->revocar(AdministrarSesiones::referencia($id), $this->admin->id, null);

        $this->get(route('dashboard'))->assertRedirect(route('login'))->assertSessionHas('status', ValidarSesion::MENSAJE);
        $this->assertGuest();
        $this->getJson(route('dashboard'))->assertUnauthorized();
        // El rechazo no se registra como cierre normal.
        $this->assertNull($this->fila($id)->cierre_utc);
    }

    public function test_solo_los_administradores(): void
    {
        $grupo = $this->crearGrupo('Soporte');
        $this->agregarAGrupo($this->ana, $grupo);
        // Asignar la funcionalidad no basta: es soloAdministrador.
        $this->asignarPermiso($grupo, 'administracion.sesiones.activas:VER');
        $this->asignarPermiso($grupo, 'administracion.sesiones.activas:ELIMINAR');
        $otra = $this->sesionAbierta($this->admin);

        $this->iniciarSesion('ana');
        $this->get(route('admin.sesiones'))->assertForbidden();
        $this->put(route('admin.sesiones.revocar', AdministrarSesiones::referencia($otra)))->assertForbidden();
        $this->put(route('admin.sesiones.revocar-usuario', $this->admin->id))->assertForbidden();
        $this->assertNull($this->fila($otra)->revocada_utc);
    }

    public function test_si_falla_la_auditoria_no_se_revoca(): void
    {
        $this->iniciarSesion('admin');
        $otra = $this->sesionAbierta($this->ana);
        $this->mock(Auditor::class)->shouldReceive('registrar')->andThrow(new RuntimeException('Auditoría no disponible'));

        $this->withoutExceptionHandling();
        try {
            $this->put(route('admin.sesiones.revocar', AdministrarSesiones::referencia($otra)));
            $this->fail('Debió propagarse el error de auditoría.');
        } catch (RuntimeException) {
        }
        $this->assertNull($this->fila($otra)->revocada_utc);
    }

    public function test_produccion_en_solo_lectura(): void
    {
        $this->iniciarSesion('admin');
        $otra = $this->sesionAbierta($this->ana);
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', false);

        $this->put(route('admin.sesiones.revocar', AdministrarSesiones::referencia($otra)))
            ->assertForbidden()->assertSee(DestinoSql::MENSAJE_SOLO_LECTURA);
        $this->get(route('admin.sesiones'))->assertOk()->assertInertia(fn ($pagina) => $pagina->where('soloLectura', true));
        $this->assertNull($this->fila($otra)->revocada_utc);

        // Sin escritura, iniciar sesión funciona pero no se registra.
        $this->post(route('logout'));
        $antes = DB::table('adm_sesiones')->count();
        $this->iniciarSesion('ana');
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame($antes, DB::table('adm_sesiones')->count());
        $this->assertNull(session(RegistroSesiones::CLAVE));
    }
}
