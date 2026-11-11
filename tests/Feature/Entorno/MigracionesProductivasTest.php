<?php

namespace Tests\Feature\Entorno;

use App\Entorno\DestinoSql;
use App\Entorno\GuardaDdl;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * GuardaDdl ante `php artisan migrate`. Las migraciones se ejecutan de verdad
 * sobre SQLite en memoria; el destino SQL Server se simula con un mock.
 */
class MigracionesProductivasTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(GuardaDdl::AUTORIZACION);
        parent::tearDown();
    }

    private function produccion(bool $escritura): void
    {
        config()->set('database.connections.sqlsrv', [
            'driver' => 'sqlsrv', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'adm_pruebas_prod',
            'username' => 'sin_uso', 'password' => 'sin_uso', 'url' => null, 'prefix' => '',
        ]);
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', $escritura);
        $this->partialMock(DestinoSql::class, fn (MockInterface $mock) => $mock->shouldAllowMockingProtectedMethods()
            ->shouldReceive('consultarDestinoReal')
            ->andReturn(['base' => 'adm_pruebas_prod', 'servidor' => 'SQLPRUEBAS', 'driver' => 'sqlsrv']));
    }

    public function test_produccion_sin_autorizacion_de_despliegue_no_migra_aunque_la_escritura_este_habilitada(): void
    {
        $this->produccion(true);

        try {
            $this->artisan('migrate', ['--force' => true])->run();
            $this->fail('La migración productiva sin autorización debió detenerse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Migración no autorizada', $e->getMessage());
        }
        $this->assertFalse(Schema::hasTable('adm_usuarios'));
    }

    public function test_produccion_con_autorizacion_de_despliegue_migra(): void
    {
        $this->produccion(false);
        putenv(GuardaDdl::AUTORIZACION.'=adm_pruebas_prod');

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('adm_usuarios'));
    }

    public function test_solo_se_migra_sobre_la_conexion_sqlsrv(): void
    {
        $motivo = app(GuardaDdl::class)->motivoBloqueo('mysql');

        $this->assertStringContainsString('conexión sqlsrv', (string) $motivo);
    }
}
