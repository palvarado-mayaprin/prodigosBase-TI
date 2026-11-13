<?php

namespace Tests\Feature\Entorno;

use App\Entorno\GuardaDdl;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * GuardaDdl al iniciar los comandos de esquema (CommandStarting): antes de
 * cualquier escritura, incluida dbo.migrations, y con autorización expresa
 * para los destructivos. SQLite en memoria simula el destino (phpunit.xml:
 * desarrollo = adm_pruebas_dev, que no es una base "_pruebas").
 */
class ComandosEsquemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activarEventosDeComandos();
        // Tabla testigo: debe sobrevivir a todo comando rechazado.
        Schema::create('testigo', fn (Blueprint $tabla) => $tabla->id());
    }

    protected function tearDown(): void
    {
        putenv(GuardaDdl::AUTORIZACION);
        putenv(GuardaDdl::AUTORIZACION_DESTRUCTIVA);
        parent::tearDown();
    }

    /** @param array<string, mixed> $opciones */
    private function rechazado(string $comando, array $opciones, string $mensaje): void
    {
        try {
            $this->artisan($comando, $opciones)->run();
            $this->fail("«{$comando}» debió detenerse.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($mensaje, $e->getMessage(), $comando);
        }
        $this->assertFalse(Schema::hasTable('migrations'), "«{$comando}» creó dbo.migrations");
        $this->assertFalse(Schema::hasTable('adm_usuarios'), "«{$comando}» migró");
        $this->assertTrue(Schema::hasTable('testigo'), "«{$comando}» borró tablas");
    }

    public function test_migrate_y_pretend_no_escriben_nada_con_un_destino_no_autorizado(): void
    {
        // Producción sin ADM_DESPLIEGUE_DDL.
        config()->set('entorno.entorno', 'produccion');
        foreach ([['migrate', []], ['migrate', ['--pretend' => true]], ['migrate:install', []]] as [$comando, $opciones]) {
            $this->rechazado($comando, $opciones, 'Migración no autorizada');
        }

        // Configuración incoherente.
        config()->set('entorno.entorno', 'otro');
        $this->rechazado('migrate', ['--pretend' => true], 'ADM_ENTORNO');

        // Otra conexión distinta de la predeterminada.
        config()->set('entorno.entorno', 'desarrollo');
        $this->rechazado('migrate', ['--pretend' => true, '--database' => 'sqlsrv'], 'conexión predeterminada');
    }

    public function test_migrate_ordinario_conserva_su_funcionamiento(): void
    {
        $this->artisan('migrate', ['--pretend' => true])->assertSuccessful();
        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('adm_usuarios'));
    }

    public function test_los_comandos_destructivos_se_rechazan_fuera_de_una_base_de_pruebas_autorizada(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        // Base normal de desarrollo, aun con la variable de autorización.
        putenv(GuardaDdl::AUTORIZACION_DESTRUCTIVA.'=adm_pruebas_dev');
        foreach (GuardaDdl::DESTRUCTIVOS as $comando) {
            $this->rechazadoConEsquema($comando, 'base de pruebas de desarrollo');
        }

        // Producción, aunque la base termine en "_pruebas" y haya autorización DDL.
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.destinos.produccion.base', 'adm_base_pruebas');
        putenv(GuardaDdl::AUTORIZACION.'=adm_base_pruebas');
        putenv(GuardaDdl::AUTORIZACION_DESTRUCTIVA.'=adm_base_pruebas');
        $this->rechazadoConEsquema('db:wipe', 'base de pruebas de desarrollo');

        // Base de pruebas sin autorización o con otra base.
        config()->set('entorno.entorno', 'desarrollo');
        config()->set('entorno.destinos.produccion.base', 'adm_pruebas_prod');
        config()->set('entorno.destinos.desarrollo.base', 'adm_base_pruebas');
        putenv(GuardaDdl::AUTORIZACION_DESTRUCTIVA);
        $this->rechazadoConEsquema('migrate:fresh', GuardaDdl::AUTORIZACION_DESTRUCTIVA.'=adm_base_pruebas');
        putenv(GuardaDdl::AUTORIZACION_DESTRUCTIVA.'=otra_pruebas');
        $this->rechazadoConEsquema('migrate:reset', GuardaDdl::AUTORIZACION_DESTRUCTIVA.'=adm_base_pruebas');
    }

    private function rechazadoConEsquema(string $comando, string $mensaje): void
    {
        try {
            $this->artisan($comando, ['--force' => true])->run();
            $this->fail("«{$comando}» debió detenerse.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Operación destructiva no autorizada', $e->getMessage(), $comando);
            $this->assertStringContainsString($mensaje, $e->getMessage(), $comando);
        }
        $this->assertTrue(Schema::hasTable('adm_usuarios'), "«{$comando}» revirtió migraciones");
        $this->assertTrue(Schema::hasTable('testigo'), "«{$comando}» borró tablas");
    }

    public function test_base_de_pruebas_autorizada_admite_la_limpieza(): void
    {
        config()->set('entorno.destinos.desarrollo.base', 'adm_base_pruebas');
        putenv(GuardaDdl::AUTORIZACION_DESTRUCTIVA.'=adm_base_pruebas');

        $this->artisan('migrate')->assertSuccessful();
        $this->artisan('migrate:reset', ['--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('adm_usuarios'));

        $this->artisan('db:wipe', ['--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('testigo'));
    }

    public function test_el_migrator_hacia_atras_tambien_exige_autorizacion_destructiva(): void
    {
        // Segunda barrera, para usos del Migrator sin pasar por un comando.
        Event::dispatch(new MigrationsStarted('up'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Operación destructiva no autorizada');
        Event::dispatch(new MigrationsStarted('down'));
    }
}
