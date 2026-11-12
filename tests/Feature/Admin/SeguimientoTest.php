<?php

namespace Tests\Feature\Admin;

use App\Entorno\DestinoSql;
use App\Models\Usuario;
use App\Services\Admin\RegistroSesiones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;
use ZipArchive;

/** Seguimiento de Uso: registro de visitas y actividad, y reportes. */
class SeguimientoTest extends TestCase
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
    }

    private function iniciarSesion(string $usuario): int
    {
        $this->post(route('login.store'), ['usuario' => $usuario, 'password' => self::CLAVE])->assertSessionHasNoErrors();

        return (int) session(RegistroSesiones::CLAVE);
    }

    private function visitar(string $ruta): ?int
    {
        $respuesta = $this->postJson(route('seguimiento.visita'), ['ruta' => $ruta]);

        return $respuesta->status() === 200 ? (int) $respuesta->json('visita') : null;
    }

    private function segundos(int $visita): int
    {
        return (int) DB::table('adm_actividad')->where('id', $visita)->value('segundos_activos');
    }

    /** Sesión y visita sembradas para los reportes. */
    private function sembrar(Usuario $usuario, string $modulo, string $visitaUtc, int $segundos, ?int $sesion = null): int
    {
        $sesion ??= (int) DB::table('adm_sesiones')->insertGetId([
            'usuario_id' => $usuario->id, 'ip' => '10.0.0.9', 'inicio_utc' => $visitaUtc, 'ultima_actividad_utc' => $visitaUtc,
        ]);
        DB::table('adm_actividad')->insert([
            'sesion_id' => $sesion, 'modulo' => $modulo, 'ruta' => '/x', 'visita_utc' => $visitaUtc, 'segundos_activos' => $segundos,
        ]);

        return $sesion;
    }

    public function test_registra_visitas_de_funcionalidades_conocidas_en_la_sesion_existente(): void
    {
        $sesion = $this->iniciarSesion('admin');
        $visita = $this->visitar('/admin/usuarios?buscar=x');

        $this->assertNotNull($visita);
        $fila = DB::table('adm_actividad')->find($visita);
        $this->assertSame([$sesion, 'administracion.accesos.usuarios', '/admin/usuarios', 0],
            [(int) $fila->sesion_id, $fila->modulo, $fila->ruta, (int) $fila->segundos_activos]);
        // No crea sesiones adicionales.
        $this->assertSame(1, DB::table('adm_sesiones')->count());
    }

    public function test_no_cuenta_rutas_inexistentes_ajenas_al_catalogo_o_no_autorizadas(): void
    {
        $this->iniciarSesion('ana');
        foreach (['/admin/usuarios', '/no-existe', '/dashboard', '/mi-perfil', '/admin/usuarios/1'] as $ruta) {
            $this->assertNull($this->visitar($ruta), $ruta);
        }

        $grupo = $this->crearGrupo('Consulta');
        $this->agregarAGrupo($this->ana, $grupo);
        $this->asignarPermiso($grupo, 'administracion.accesos.membresias:VER');
        $this->assertNotNull($this->visitar('/admin/membresias'));
        $this->assertSame(1, DB::table('adm_actividad')->count());

        // Solicitudes no válidas.
        $this->postJson(route('seguimiento.visita'), [])->assertUnprocessable();
        $this->postJson(route('seguimiento.visita'), ['ruta' => 'https://otro.sitio/admin'])->assertUnprocessable();
        $this->postJson(route('seguimiento.visita'), ['ruta' => '/'.str_repeat('a', 300)])->assertUnprocessable();

        // Sin sesión autenticada no hay registro.
        $this->post(route('logout'));
        $this->postJson(route('seguimiento.visita'), ['ruta' => '/admin/membresias'])->assertUnauthorized();
        $this->assertSame(1, DB::table('adm_actividad')->count());
    }

    public function test_suma_actividad_con_limites_del_servidor(): void
    {
        $this->iniciarSesion('admin');
        $visita = (int) $this->visitar('/admin/grupos');

        $this->travel(120)->seconds();
        $this->postJson(route('seguimiento.actividad'), ['visita' => $visita, 'segundos' => 60])->assertNoContent();
        $this->assertSame(60, $this->segundos($visita));
        // El total de la sesión no supera el tiempo real transcurrido (+5 s).
        $this->postJson(route('seguimiento.actividad'), ['visita' => $visita, 'segundos' => 600])->assertNoContent();
        $this->assertSame(125, $this->segundos($visita));
        $this->postJson(route('seguimiento.actividad'), ['visita' => $visita, 'segundos' => 30])->assertNoContent();
        $this->assertSame(125, $this->segundos($visita));

        foreach ([['visita' => $visita, 'segundos' => 0], ['visita' => $visita, 'segundos' => 601], ['visita' => 'x', 'segundos' => 5], ['segundos' => 5]] as $datos) {
            $this->postJson(route('seguimiento.actividad'), $datos)->assertUnprocessable();
        }
    }

    public function test_la_actividad_de_otra_sesion_se_ignora(): void
    {
        $ajena = $this->sembrar($this->ana, 'administracion.accesos.grupos', now()->subHour()->format('Y-m-d H:i:s'), 10);
        $visitaAjena = (int) DB::table('adm_actividad')->where('sesion_id', $ajena)->value('id');

        $this->iniciarSesion('admin');
        $this->travel(120)->seconds();
        $this->postJson(route('seguimiento.actividad'), ['visita' => $visitaAjena, 'segundos' => 60])->assertNoContent();
        $this->assertSame(10, $this->segundos($visitaAjena));
    }

    public function test_reportes_por_usuario_modulo_y_dia_en_la_zona_configurada(): void
    {
        config()->set('app.zona_horaria', 'America/Guatemala');
        // 03:00 UTC del 12 es el 11 en Guatemala.
        $sesion = $this->sembrar($this->ana, 'administracion.accesos.grupos', '2026-11-12 03:00:00', 100);
        $this->sembrar($this->ana, 'administracion.accesos.usuarios', '2026-11-12 03:10:00', 20, $sesion);
        $this->sembrar($this->admin, 'administracion.accesos.grupos', '2026-11-12 18:00:00', 50);

        $this->actingAs($this->admin)->get(route('admin.seguimiento'))->assertOk()->assertInertia(fn ($p) => $p
            ->component('admin/Seguimiento')
            ->where('indicadores', ['sesiones' => 2, 'usuarios' => 2, 'visitas' => 3, 'segundosActivos' => 170, 'promedioPorSesion' => 85])
            ->where('porUsuario', [
                ['usuario' => 'ana', 'sesiones' => 1, 'visitas' => 2, 'segundosActivos' => 120],
                ['usuario' => 'admin', 'sesiones' => 1, 'visitas' => 1, 'segundosActivos' => 50],
            ])
            ->where('porModulo.0', ['modulo' => 'administracion.accesos.grupos', 'nombre' => 'Grupos', 'visitas' => 2, 'usuarios' => 2, 'segundosActivos' => 150, 'promedioPorVisita' => 75])
            ->where('diario', [
                ['fecha' => '2026-11-11', 'usuarios' => 1, 'sesiones' => 1, 'visitas' => 2, 'segundosActivos' => 120],
                ['fecha' => '2026-11-12', 'usuarios' => 1, 'sesiones' => 1, 'visitas' => 1, 'segundosActivos' => 50],
            ])
            ->where('historial.data.0.visita', '2026-11-12T18:00:00Z'));

        $this->get(route('admin.seguimiento', ['desde' => '2026-11-11', 'hasta' => '2026-11-11']))
            ->assertInertia(fn ($p) => $p->where('indicadores.visitas', 2));
        $this->get(route('admin.seguimiento', ['usuario' => 'admin', 'modulo' => 'administracion.accesos.grupos']))
            ->assertInertia(fn ($p) => $p->where('indicadores.visitas', 1));
    }

    public function test_pagina_el_historial_y_rechaza_filtros_invalidos(): void
    {
        $sesion = $this->sembrar($this->ana, 'administracion.accesos.grupos', '2026-11-10 10:00:00', 5);
        foreach (range(1, 29) as $i) {
            $this->sembrar($this->ana, 'administracion.accesos.grupos', sprintf('2026-11-10 10:%02d:00', $i), 5, $sesion);
        }

        $this->actingAs($this->admin)->get(route('admin.seguimiento'))->assertInertia(fn ($p) => $p
            ->where('historial.total', 30)->has('historial.data', 25));
        $this->get(route('admin.seguimiento', ['page' => 2]))->assertInertia(fn ($p) => $p->has('historial.data', 5));

        foreach ([['desde' => 'ayer'], ['desde' => '2026-11-12', 'hasta' => '2026-11-01'], ['modulo' => 'otro.modulo.x'], ['usuario' => "a\x01b"], ['page' => 0]] as $filtro) {
            $this->get(route('admin.seguimiento', $filtro))->assertRedirect(route('admin.seguimiento'))->assertSessionHasErrors();
        }
        $this->get('/admin/seguimiento/exportar/secretos/csv')->assertNotFound();
    }

    public function test_exporta_reportes_csv_y_xlsx_con_formulas_neutralizadas(): void
    {
        $malicioso = $this->crearUsuario('=cmd|calc');
        $this->sembrar($malicioso, 'administracion.accesos.grupos', '2026-11-10 10:00:00', 30);

        $this->actingAs($this->admin);
        foreach (['historial', 'usuarios', 'modulos', 'diario'] as $reporte) {
            $csv = $this->get(route('admin.seguimiento.exportar', [$reporte, 'csv']))->assertOk()->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
            if (in_array($reporte, ['historial', 'usuarios'], true)) {
                $this->assertStringContainsString("'=cmd|calc", $csv);
            }
        }
        $this->assertStringContainsString('2026-11-10;1;1;1;30', $this->get(route('admin.seguimiento.exportar', ['diario', 'csv']))->streamedContent());

        $xlsx = $this->get(route('admin.seguimiento.exportar', ['historial', 'xlsx']))->assertOk()->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $xlsx);
        $zip = new ZipArchive;
        $zip->open($xlsx->getFile()->getPathname());
        $hoja = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringContainsString('&apos;=cmd|calc', $hoja);
    }

    public function test_solo_los_administradores_consultan(): void
    {
        $grupo = $this->crearGrupo('Soporte');
        $this->agregarAGrupo($this->ana, $grupo);
        $this->asignarPermiso($grupo, 'administracion.seguimiento.uso:VER');

        $this->actingAs($this->ana)->get(route('admin.seguimiento'))->assertForbidden();
        $this->get(route('admin.seguimiento.exportar', ['historial', 'csv']))->assertForbidden();
    }

    public function test_produccion_en_solo_lectura_consulta_pero_no_registra(): void
    {
        $this->sembrar($this->ana, 'administracion.accesos.grupos', '2026-11-10 10:00:00', 30);
        $this->iniciarSesion('admin');
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', false);

        $this->get(route('admin.seguimiento'))->assertOk()->assertInertia(fn ($p) => $p->where('indicadores.visitas', 1));
        $this->get(route('admin.seguimiento.exportar', ['historial', 'xlsx']))->assertOk();
        $this->postJson(route('seguimiento.visita'), ['ruta' => '/admin/grupos'])->assertForbidden()->assertSee(DestinoSql::MENSAJE_SOLO_LECTURA);
        $this->assertSame(1, DB::table('adm_actividad')->count());
    }

    public function test_consultas_constantes_sin_importar_el_volumen(): void
    {
        $contar = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->sembrar($this->ana, 'administracion.accesos.grupos', '2026-11-10 10:00:00', 5);
        $this->actingAs($this->admin)->get(route('dashboard'));
        $pocos = $contar(route('admin.seguimiento'));
        foreach (range(1, 40) as $i) {
            $this->sembrar($i % 2 ? $this->ana : $this->admin, 'administracion.accesos.usuarios', sprintf('2026-11-%02d 10:00:00', 1 + $i % 9), 5);
        }

        $this->assertSame($pocos, $contar(route('admin.seguimiento')));
    }
}
