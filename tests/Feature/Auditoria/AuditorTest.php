<?php

namespace Tests\Feature\Auditoria;

use App\Auditoria\Auditor;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Mockery;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/** App\Auditoria\Auditor: transacción obligatoria y secretos enmascarados. */
class AuditorTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    public function test_exige_una_transaccion_abierta(): void
    {
        // RefreshDatabase ya abre una transacción: se simula una conexión sin ella.
        $conexion = Mockery::mock(Connection::class);
        $conexion->shouldReceive('transactionLevel')->andReturn(0);
        $conexion->shouldNotReceive('table');

        $this->expectException(LogicException::class);
        app(Auditor::class)->registrar($conexion, 'adm_grupos', '1', 'CREAR');
    }

    public function test_rechaza_acciones_fuera_de_la_lista(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::transaction(fn () => app(Auditor::class)->registrar(DB::connection(), 'adm_grupos', '1', 'BORRAR'));
    }

    public function test_registra_usuario_ip_entidad_registro_accion_fecha_y_cambios_sin_secretos(): void
    {
        $usuario = $this->crearUsuario();
        $this->actingAs($usuario);
        $this->app->instance('request', Request::create('/', 'PUT', server: ['REMOTE_ADDR' => '192.0.2.15']));
        $this->app['env'] = 'testing';

        DB::transaction(fn () => app(Auditor::class)->registrar(DB::connection(), 'adm_usuarios', '7', 'EDITAR', [
            'nombre' => ['Ana', 'Ana María'],
            'password' => ['x', 'y'],
            'detalle' => ['api_token' => 'abc', 'Clave_Temporal' => 'def', 'secreto' => 'ghi', 'visible' => 'ok'],
        ]));

        $fila = DB::table('adm_auditoria')->sole();
        $this->assertSame([$usuario->id, 'usuario.prueba', 'adm_usuarios', '7', 'EDITAR'],
            [(int) $fila->usuario_id, $fila->usuario, $fila->entidad, $fila->registro, $fila->accion]);
        $this->assertNotNull($fila->fecha_utc);
        $cambios = json_decode($fila->cambios, true);
        $this->assertSame(['Ana', 'Ana María'], $cambios['nombre']);
        $this->assertSame(Auditor::OCULTO, $cambios['password']);
        $this->assertSame(['api_token' => Auditor::OCULTO, 'Clave_Temporal' => Auditor::OCULTO, 'secreto' => Auditor::OCULTO, 'visible' => 'ok'], $cambios['detalle']);
        $this->assertStringNotContainsString('abc', $fila->cambios);
    }

    public function test_si_se_revierte_la_transaccion_no_queda_auditoria(): void
    {
        try {
            DB::transaction(function () {
                app(Auditor::class)->registrar(DB::connection(), 'adm_grupos', '1', 'CREAR');
                throw new \RuntimeException('fallo posterior');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, DB::table('adm_auditoria')->count());
    }
}
