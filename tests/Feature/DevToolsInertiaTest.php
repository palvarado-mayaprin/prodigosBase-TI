<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\DevTools\DevTools;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/**
 * Inertia DevTools (incluido en inertiajs/inertia-laravel) graba cada
 * petición en disco y la sirve en /_inertia/devtools; con APP_ENV=local se
 * activaba solo y guardaba contrasena_actual en texto plano. Queda
 * deshabilitado en todos los entornos (config/inertia.php).
 */
class DevToolsInertiaTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private string $capturas;

    protected function setUp(): void
    {
        parent::setUp();
        // Carpeta propia de la prueba: nunca la real de storage.
        $this->capturas = storage_path('framework/testing/inertia-devtools-'.getmypid());
        File::deleteDirectory($this->capturas);
        config(['inertia.devtools.storage.path' => $this->capturas]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->capturas);
        parent::tearDown();
    }

    public function test_esta_deshabilitado_en_todos_los_entornos_tambien_en_local(): void
    {
        $this->assertFalse(config('inertia.devtools.enabled'));

        foreach (['local', 'production', 'testing'] as $entorno) {
            $this->app['env'] = $entorno;
            $this->assertFalse(DevTools::enabled(), "DevTools activo con APP_ENV=$entorno");
        }
    }

    public function test_sus_rutas_no_existen_y_no_entregan_registros(): void
    {
        foreach (Route::getRoutes() as $ruta) {
            $this->assertStringStartsNotWith('_inertia', $ruta->uri(), 'Ruta de DevTools registrada');
        }

        $this->actingAs($this->crearUsuario());
        $this->get('/_inertia/devtools/entries')->assertNotFound();
        $this->get('/_inertia/devtools/entries/01M7C8HN6DYJ1XZBNEB84XEH3S')->assertNotFound();
    }

    public function test_un_cambio_de_contrasena_no_genera_capturas(): void
    {
        $this->app['env'] = 'local';
        $this->actingAs($this->crearUsuario());
        $nueva = 'Clave-Nueva-Segura-26';

        $this->put(route('mi-perfil.contrasena'), [
            'contrasena_actual' => self::CLAVE, 'password' => $nueva, 'password_confirmation' => $nueva,
        ])->assertSessionHasNoErrors();

        $this->assertDirectoryDoesNotExist($this->capturas);
    }
}
