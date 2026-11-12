<?php

namespace Tests\Feature\Entorno;

use App\Entorno\DestinoSql;
use App\Entorno\GuardaDdl;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/** Comando adm:entorno y autorización de DDL separada del interruptor de escritura. */
class DiagnosticoYDdlTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(GuardaDdl::AUTORIZACION);
        parent::tearDown();
    }

    /** Destino configurado con el destino real simulado (o un fallo de conexión). */
    private function escenario(string $entorno, string $base, bool $escritura, ?string $baseReal = null, bool $sinConexion = false): void
    {
        config()->set('database.connections.sqlsrv', [
            'driver' => 'sqlsrv', 'host' => '127.0.0.1', 'port' => '1', 'database' => $base,
            'username' => 'usuario_secreto', 'password' => 'clave_secreta', 'url' => null, 'prefix' => '',
        ]);
        config()->set('database.default', 'sqlsrv');
        config()->set('entorno.entorno', $entorno);
        config()->set('entorno.escritura_produccion', $escritura);
        $this->app['request']->attributes->remove('adm_destino_sql');
        $this->partialMock(DestinoSql::class, function (MockInterface $mock) use ($base, $baseReal, $sinConexion) {
            $consulta = $mock->shouldAllowMockingProtectedMethods()->shouldReceive('consultarDestinoReal');
            $sinConexion
                ? $consulta->andThrow(new RuntimeException('Login timeout expired'))
                : $consulta->andReturn(['base' => $baseReal ?? $base, 'servidor' => 'SQLPRUEBAS', 'driver' => 'sqlsrv']);
        });
    }

    public function test_adm_entorno_informa_sin_mostrar_secretos(): void
    {
        $this->escenario('produccion', 'adm_pruebas_prod', false);
        $this->artisan('adm:entorno')
            ->expectsOutputToContain('produccion')
            ->expectsOutputToContain('SQLPRUEBAS / adm_pruebas_prod')
            ->expectsOutputToContain('BLOQUEADA')
            ->expectsOutputToContain('ADM_PRODUCCION_ESCRITURA está apagado')
            ->doesntExpectOutputToContain('usuario_secreto')
            ->doesntExpectOutputToContain('clave_secreta')
            ->assertSuccessful();

        $this->escenario('produccion', 'adm_pruebas_prod', true);
        $this->artisan('adm:entorno')->expectsOutputToContain('HABILITADA')->assertSuccessful();
    }

    public function test_adm_entorno_falla_con_destino_incoherente_o_sin_conexion(): void
    {
        $this->escenario('produccion', 'adm_pruebas_prod', true, baseReal: 'adm_pruebas_dev');
        $this->artisan('adm:entorno')->expectsOutputToContain('Destino no coherente')->assertFailed();

        $this->escenario('desarrollo', 'adm_pruebas_dev', false, sinConexion: true);
        $this->artisan('adm:entorno')
            ->expectsOutputToContain('Error de conexión: Login timeout expired')
            ->expectsOutputToContain('No se pudo consultar el destino real')
            ->assertFailed();
    }

    public function test_adm_entorno_no_conecta_si_la_configuracion_es_invalida(): void
    {
        $this->escenario('desarrollo', 'adm_pruebas_prod', false);
        $this->artisan('adm:entorno')
            ->expectsOutputToContain('no consultado')
            ->expectsOutputToContain('DB_DATABASE (adm_pruebas_prod) no corresponde')
            ->assertFailed();
    }

    public function test_ddl_en_desarrollo_exige_destino_coherente(): void
    {
        $this->escenario('desarrollo', 'adm_pruebas_dev', false);
        $this->assertNull(app(GuardaDdl::class)->motivoBloqueo());

        $this->escenario('desarrollo', 'adm_pruebas_dev', false, baseReal: 'otra');
        $this->assertNotNull(app(GuardaDdl::class)->motivoBloqueo());
    }

    public function test_ddl_en_produccion_exige_la_variable_de_despliegue_y_no_el_interruptor(): void
    {
        // El interruptor de escritura no habilita DDL.
        $this->escenario('produccion', 'adm_pruebas_prod', true);
        $this->assertStringContainsString(GuardaDdl::AUTORIZACION, (string) app(GuardaDdl::class)->motivoBloqueo());

        putenv(GuardaDdl::AUTORIZACION.'=adm_pruebas_dev');
        $this->assertNotNull(app(GuardaDdl::class)->motivoBloqueo());

        // Con la variable de despliegue correcta, aunque la escritura esté apagada.
        $this->escenario('produccion', 'adm_pruebas_prod', false);
        putenv(GuardaDdl::AUTORIZACION.'=adm_pruebas_prod');
        $this->assertNull(app(GuardaDdl::class)->motivoBloqueo());
    }

    public function test_el_inicio_de_una_migracion_no_autorizada_se_detiene(): void
    {
        $this->escenario('produccion', 'adm_pruebas_prod', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migración no autorizada');
        Event::dispatch(new MigrationsStarted('up'));
    }
}
