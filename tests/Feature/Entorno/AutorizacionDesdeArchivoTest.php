<?php

namespace Tests\Feature\Entorno;

use App\Entorno\GuardaDdl;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * ADM_DESPLIEGUE_DDL y ADM_PRUEBAS_DESTRUCTIVAS solo autorizan como variables
 * del proceso. Laravel copia al proceso lo que lee de `.env` (y de
 * `.env.<APP_ENV>`), así que GuardaDdl rechaza la autorización si cualquiera
 * de esos archivos la declara, con cualquier valor, antes de escribir nada.
 * Cada prueba usa una carpeta de entorno temporal propia.
 */
class AutorizacionDesdeArchivoTest extends TestCase
{
    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = storage_path('framework/testing/entorno-'.getmypid());
        File::deleteDirectory($this->carpeta);
        File::ensureDirectoryExists($this->carpeta);
        $this->app->useEnvironmentPath($this->carpeta);

        $this->activarEventosDeComandos();
        Schema::create('testigo', fn (Blueprint $tabla) => $tabla->id());
    }

    protected function tearDown(): void
    {
        putenv(GuardaDdl::AUTORIZACION);
        putenv(GuardaDdl::AUTORIZACION_DESTRUCTIVA);
        File::deleteDirectory($this->carpeta);
        parent::tearDown();
    }

    private function archivo(string $nombre, string $contenido): void
    {
        File::put($this->carpeta.DIRECTORY_SEPARATOR.$nombre, "APP_NAME=Prueba\n".$contenido."\n");
    }

    private function rechazado(string $comando, string $archivo, string $variable): void
    {
        try {
            $this->artisan($comando, ['--force' => true])->run();
            $this->fail("«{$comando}» debió detenerse.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("$variable está declarada en el archivo de entorno «{$archivo}»", $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('testigo'), "«{$comando}» borró tablas");
    }

    public function test_la_autorizacion_destructiva_declarada_en_un_archivo_de_entorno_se_rechaza(): void
    {
        config()->set('entorno.destinos.desarrollo.base', 'adm_base_pruebas');
        $this->artisan('migrate')->assertSuccessful();
        // La variable del proceso es correcta, pero también está en un archivo.
        putenv(GuardaDdl::AUTORIZACION_DESTRUCTIVA.'=adm_base_pruebas');

        foreach (['.env' => 'adm_base_pruebas', '.env.testing' => '', '.env.local' => '"otra"'] as $archivo => $valor) {
            File::cleanDirectory($this->carpeta);
            $this->archivo($archivo, 'export '.GuardaDdl::AUTORIZACION_DESTRUCTIVA.' = '.$valor);
            $this->rechazado('migrate:fresh', $archivo, GuardaDdl::AUTORIZACION_DESTRUCTIVA);
            $this->rechazado('db:wipe', $archivo, GuardaDdl::AUTORIZACION_DESTRUCTIVA);
            $this->assertTrue(Schema::hasTable('adm_usuarios'), "$archivo: se revirtieron migraciones");
        }

        // Comentada no cuenta: la variable del proceso autoriza.
        File::cleanDirectory($this->carpeta);
        $this->archivo('.env', '# '.GuardaDdl::AUTORIZACION_DESTRUCTIVA.'=adm_base_pruebas');
        $this->assertNull(app(GuardaDdl::class)->motivoBloqueoDestructivo());
        $this->artisan('migrate:reset', ['--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('adm_usuarios'));
    }

    public function test_la_autorizacion_de_despliegue_declarada_en_un_archivo_de_entorno_se_rechaza(): void
    {
        config()->set('entorno.entorno', 'produccion');
        putenv(GuardaDdl::AUTORIZACION.'=adm_pruebas_prod');

        $this->archivo('.env.production', GuardaDdl::AUTORIZACION.'=adm_pruebas_prod');
        $this->rechazado('migrate', '.env.production', GuardaDdl::AUTORIZACION);
        $this->assertFalse(Schema::hasTable('migrations'), 'migrate creó dbo.migrations');

        // Solo como variable del proceso, autoriza.
        File::cleanDirectory($this->carpeta);
        $this->archivo('.env', 'ADM_ENTORNO=produccion');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('adm_usuarios'));
    }
}
