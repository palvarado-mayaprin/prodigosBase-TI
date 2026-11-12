<?php

namespace Tests\Feature\Admin;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;
use ZipArchive;

/** Auditoría de Modificaciones (administracion.auditoria.modificaciones). */
class AuditoriaTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private const HASH = '$2y$12$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('admin');
        $this->agregarAGrupo($this->admin, $this->crearGrupo('Administradores', administrador: true));
        $this->artisan('adm:sincronizar-permisos')->assertSuccessful();
        DB::table('adm_auditoria')->delete();
        $this->actingAs($this->admin);
    }

    /** @param array<string, mixed> $atributos */
    private function registro(array $atributos = []): int
    {
        return (int) DB::table('adm_auditoria')->insertGetId([
            'fecha_utc' => '2026-11-10 15:00:00', 'usuario_id' => $this->admin->id, 'usuario' => 'admin', 'ip' => '127.0.0.1',
            'entidad' => 'adm_usuarios', 'registro' => '7', 'accion' => 'EDITAR',
            'cambios' => json_encode(['nombre' => ['Ana', 'Ana María']], JSON_UNESCAPED_UNICODE), ...$atributos,
        ]);
    }

    private function contenidoXlsx(BinaryFileResponse $respuesta): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($respuesta->getFile()->getPathname()));
        $hoja = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        return $hoja;
    }

    public function test_solo_los_administradores(): void
    {
        $comun = $this->crearUsuario('comun');
        $grupo = $this->crearGrupo('Soporte');
        $this->agregarAGrupo($comun, $grupo);
        // Asignar la funcionalidad no basta: es soloAdministrador.
        $this->asignarPermiso($grupo, 'administracion.auditoria.modificaciones:VER');

        $this->actingAs($comun)->get(route('admin.auditoria'))->assertForbidden();
        $this->get(route('admin.auditoria.exportar', 'csv'))->assertForbidden();

        $this->post(route('logout'));
        $this->get(route('admin.auditoria'))->assertRedirect(route('login'));
    }

    public function test_pagina_filtra_y_ordena_sin_modificar_registros(): void
    {
        foreach (range(1, 30) as $i) {
            $this->registro(['registro' => (string) $i, 'fecha_utc' => sprintf('2026-11-10 10:%02d:00', $i)]);
        }
        $this->registro(['usuario' => 'beto', 'accion' => 'REVOCAR', 'entidad' => 'adm_sesiones', 'registro' => 'especial']);

        $this->get(route('admin.auditoria'))->assertOk()->assertInertia(fn ($p) => $p
            ->component('admin/Auditoria')
            ->where('registros.total', 31)
            ->has('registros.data', 25)
            ->where('registros.data.0.registro', 'especial')
            ->where('usuarios', ['admin', 'beto'])
            ->where('entidades', ['adm_sesiones', 'adm_usuarios']));
        $this->get(route('admin.auditoria', ['page' => 2]))->assertInertia(fn ($p) => $p->has('registros.data', 6));

        $this->get(route('admin.auditoria', ['usuario' => 'beto']))->assertInertia(fn ($p) => $p->where('registros.total', 1));
        $this->get(route('admin.auditoria', ['accion' => 'REVOCAR', 'entidad' => 'adm_sesiones']))->assertInertia(fn ($p) => $p->where('registros.total', 1));
        $this->get(route('admin.auditoria', ['texto' => 'Ana María', 'usuario' => 'admin']))->assertInertia(fn ($p) => $p->where('registros.total', 30));
        // Los comodines de LIKE se buscan como texto.
        $this->get(route('admin.auditoria', ['texto' => '%']))->assertInertia(fn ($p) => $p->where('registros.total', 0));
        $this->get(route('admin.auditoria', ['orden' => 'fecha', 'dir' => 'asc']))
            ->assertInertia(fn ($p) => $p->where('registros.data.0.registro', '1')->where('filtros.dir', 'asc'));

        $this->assertSame(31, DB::table('adm_auditoria')->count());
    }

    public function test_muestra_los_cambios_legibles_y_oculta_secretos_anidados(): void
    {
        $this->registro(['cambios' => json_encode([
            'nombre' => ['Ana', 'Ana María'],
            'activo' => [false, true],
            'usuario' => 'ana',
            'password' => 'Secreto-Plano-1',
            'detalle' => ['token' => 'token-plano-123', 'clave_api' => 'abc', 'hash' => self::HASH, 'ok' => 'visible'],
            'valor' => self::HASH,
        ], JSON_UNESCAPED_UNICODE)]);

        $respuesta = $this->get(route('admin.auditoria'))->assertOk()->assertInertia(fn ($p) => $p
            ->where('registros.data.0.campos', [
                ['campo' => 'nombre', 'anterior' => 'Ana', 'nuevo' => 'Ana María', 'cambio' => true],
                ['campo' => 'activo', 'anterior' => 'No', 'nuevo' => 'Sí', 'cambio' => true],
                ['campo' => 'usuario', 'anterior' => null, 'nuevo' => 'ana', 'cambio' => false],
                ['campo' => 'password', 'anterior' => null, 'nuevo' => '********', 'cambio' => false],
                ['campo' => 'detalle', 'anterior' => null, 'nuevo' => '{"token":"********","clave_api":"********","hash":"********","ok":"visible"}', 'cambio' => false],
                ['campo' => 'valor', 'anterior' => null, 'nuevo' => '********', 'cambio' => false],
            ])
            ->where('registros.data.0.resumen', '2 campos: nombre, activo'));

        foreach (['Secreto-Plano-1', 'token-plano-123', '$2y$'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $respuesta->getContent());
        }
        $csv = $this->get(route('admin.auditoria.exportar', 'csv'))->assertOk()->streamedContent();
        foreach (['Secreto-Plano-1', 'token-plano-123', '$2y$'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $csv);
        }
        $this->assertStringContainsString('nombre: Ana → Ana María', $csv);
    }

    public function test_exporta_csv_y_xlsx_con_formulas_neutralizadas(): void
    {
        $this->registro(['registro' => '=HYPERLINK("http://x")', 'usuario' => '+suma']);
        $this->registro(['registro' => 'normal']);

        $csv = $this->get(route('admin.auditoria.exportar', ['formato' => 'csv', 'texto' => 'HYPERLINK']));
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $contenido = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido);
        $this->assertStringContainsString("'=HYPERLINK", $contenido);
        $this->assertStringContainsString("'+suma", $contenido);
        $this->assertStringNotContainsString('normal', $contenido);

        $xlsx = $this->get(route('admin.auditoria.exportar', 'xlsx'))->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $xlsx->baseResponse);
        $hoja = $this->contenidoXlsx($xlsx->baseResponse);
        // En el XML el apóstrofo de neutralización va escapado.
        $this->assertStringContainsString('&apos;=HYPERLINK', $hoja);
        $this->assertStringContainsString('normal', $hoja);
    }

    public function test_fechas_en_utc_y_zona_horaria_configurada(): void
    {
        config()->set('app.zona_horaria', 'America/Guatemala');
        // 03:00 UTC del 12 de noviembre son las 21:00 del 11 en Guatemala.
        $this->registro(['fecha_utc' => '2026-11-12 03:00:00']);

        $this->get(route('admin.auditoria', ['desde' => '2026-11-11', 'hasta' => '2026-11-11']))->assertInertia(fn ($p) => $p
            ->where('zonaHoraria', 'America/Guatemala')
            ->where('registros.total', 1)
            ->where('registros.data.0.fecha', '2026-11-12T03:00:00Z'));
        $this->get(route('admin.auditoria', ['desde' => '2026-11-12']))->assertInertia(fn ($p) => $p->where('registros.total', 0));
        $this->assertStringContainsString('2026-11-11 21:00:00', $this->get(route('admin.auditoria.exportar', 'csv'))->streamedContent());

        // Una zona no válida usa UTC.
        config()->set('app.zona_horaria', 'Marte/Olympus');
        $this->get(route('admin.auditoria'))->assertInertia(fn ($p) => $p->where('zonaHoraria', 'UTC'));
    }

    public function test_rechaza_parametros_invalidos(): void
    {
        foreach ([['desde' => '12/11/2026'], ['desde' => '2026-11-12', 'hasta' => '2026-11-01'], ['accion' => 'BORRAR'],
            ['orden' => 'password'], ['dir' => 'arriba'], ['texto' => str_repeat('x', 101)], ['texto' => "a\x01b"], ['page' => 'x']] as $filtro) {
            $this->get(route('admin.auditoria', $filtro))->assertRedirect(route('admin.auditoria'))->assertSessionHasErrors();
        }
        $this->get(route('admin.auditoria.exportar', ['formato' => 'csv', 'accion' => 'BORRAR']))->assertRedirect();
        $this->get('/admin/auditoria/exportar/pdf')->assertNotFound();
    }

    public function test_consulta_y_exporta_con_produccion_en_solo_lectura(): void
    {
        $this->registro();
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', false);

        $this->get(route('admin.auditoria'))->assertOk()->assertInertia(fn ($p) => $p->where('registros.total', 1));
        $this->get(route('admin.auditoria.exportar', 'csv'))->assertOk();
        $this->get(route('admin.auditoria.exportar', 'xlsx'))->assertOk();
    }

    public function test_consultas_constantes_sin_importar_el_volumen(): void
    {
        $contar = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $respuesta = $this->get($url)->assertOk();
            if ($respuesta->baseResponse instanceof StreamedResponse) {
                $respuesta->streamedContent();
            }
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 3) as $i) {
            $this->registro();
        }
        $this->get(route('dashboard'));
        $pocos = [$contar(route('admin.auditoria')), $contar(route('admin.auditoria.exportar', 'csv'))];
        foreach (range(1, 60) as $i) {
            $this->registro();
        }
        $muchos = [$contar(route('admin.auditoria')), $contar(route('admin.auditoria.exportar', 'csv'))];

        $this->assertSame($pocos, $muchos);
    }
}
