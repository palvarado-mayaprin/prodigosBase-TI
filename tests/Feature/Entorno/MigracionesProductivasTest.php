<?php

namespace Tests\Feature\Entorno;

use App\Entorno\GuardaDdl;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * GuardaDdl ante `php artisan migrate`. Las migraciones se ejecutan de verdad
 * sobre SQLite en memoria: es la única excepción a «solo SQL Server» y solo
 * existe dentro de PHPUnit (ver docs/ARQUITECTURA_SQL_SERVER.md, sección 5).
 * El destino de producción se simula con ADM_ENTORNO=produccion.
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
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', $escritura);
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

    public function test_solo_se_migra_sobre_la_conexion_predeterminada_y_con_sql_server(): void
    {
        $this->assertStringContainsString('conexión predeterminada', (string) app(GuardaDdl::class)->motivoBloqueo('mysql'));

        // Fuera de la simulación de PHPUnit (SQLite :memory:), solo el driver sqlsrv.
        config()->set('database.connections.sqlite.database', storage_path('framework/testing/prueba.sqlite'));
        $this->assertStringContainsString('SQL Server', (string) app(GuardaDdl::class)->motivoBloqueo());
    }
}
