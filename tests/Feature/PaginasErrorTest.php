<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/** Páginas de error en español, con su código HTTP y sin detalles internos. */
class PaginasErrorTest extends TestCase
{
    public function test_404_en_espanol_sin_citar_la_ruta(): void
    {
        $this->get('/ruta-que-no-existe-xyz')->assertNotFound()
            ->assertSee('Página no encontrada')->assertSee('Volver al inicio')
            ->assertDontSee('ruta-que-no-existe-xyz')->assertDontSee('Not Found');
    }

    public function test_500_en_espanol_sin_el_detalle_de_la_excepcion(): void
    {
        config()->set('app.debug', false);
        Route::get('/_prueba/fallo', fn () => throw new RuntimeException('Detalle interno secreto'));

        $this->get('/_prueba/fallo')->assertStatus(500)
            ->assertSee('Error del servidor')->assertDontSee('Detalle interno secreto')->assertDontSee('Server Error');
    }

    public function test_503_en_espanol_con_texto_fijo(): void
    {
        Route::get('/_prueba/no-disponible', fn () => abort(503, 'Detalle interno'));

        $this->get('/_prueba/no-disponible')->assertStatus(503)
            ->assertSee('Servicio no disponible')->assertDontSee('Detalle interno')->assertDontSee('Service Unavailable');
    }

    public function test_las_peticiones_json_reciben_json(): void
    {
        $this->getJson('/ruta-que-no-existe-xyz')->assertNotFound()->assertJsonStructure(['message']);
    }
}
