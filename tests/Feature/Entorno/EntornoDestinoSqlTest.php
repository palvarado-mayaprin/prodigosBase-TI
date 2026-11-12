<?php

namespace Tests\Feature\Entorno;

use App\Entorno\DestinoSql;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Matriz de entornos (DestinoSql + ControlarAcceso). Nunca abre SQL Server:
 * la conexión apunta a un puerto inalcanzable y el destino real
 * (DB_NAME(), @@SERVERNAME) se simula con un mock.
 */
class EntornoDestinoSqlTest extends TestCase
{
    private const RUTA = '/_prueba/escritura';

    private const IP_CORPORATIVA = '192.0.2.10';

    private const SERVIDOR = 'SQLPRUEBAS';

    private const DEV = 'adm_pruebas_dev';

    private const PROD = 'adm_pruebas_prod';

    private int $consultas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Ruta de escritura ficticia, protegida igual que cualquier módulo nuevo.
        Route::middleware('web')->group(function () {
            Route::get(self::RUTA, fn () => 'lectura');
            Route::post(self::RUTA, function () {
                app(DestinoSql::class)->exigirEscritura();

                return 'escrito';
            });
        });
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /** Configura entorno, base, interruptor y el destino real simulado. */
    private function escenario(string $appEnv, string $entorno, string $base, bool $escritura,
        ?string $baseReal = null, string $servidorReal = self::SERVIDOR, bool $debug = true): void
    {
        $this->app['env'] = $appEnv;
        config()->set('app.debug', $debug);
        config()->set('acceso.redes', ['192.0.2.0/28']);
        config()->set('database.connections.sqlsrv', [
            'driver' => 'sqlsrv', 'host' => '127.0.0.1', 'port' => '1', 'database' => $base,
            'username' => 'sin_uso', 'password' => 'sin_uso', 'url' => null, 'prefix' => '',
        ]);
        config()->set('database.default', 'sqlsrv');
        config()->set('entorno.entorno', $entorno);
        config()->set('entorno.escritura_produccion', $escritura);
        // La consola reutiliza la misma petición: se descarta el destino memorizado.
        $this->app['request']->attributes->remove('adm_destino_sql');
        $this->partialMock(DestinoSql::class, function (MockInterface $mock) use ($base, $baseReal, $servidorReal) {
            $mock->shouldAllowMockingProtectedMethods()->shouldReceive('consultarDestinoReal')
                ->andReturnUsing(function () use ($base, $baseReal, $servidorReal) {
                    $this->consultas++;

                    return ['base' => $baseReal ?? $base, 'servidor' => $servidorReal, 'driver' => 'sqlsrv'];
                });
        });
    }

    private function ip(string $appEnv): string
    {
        return $appEnv === 'production' ? self::IP_CORPORATIVA : '127.0.0.1';
    }

    private function escribir(string $appEnv = 'local'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $this->ip($appEnv)])->postJson(self::RUTA);
    }

    public function test_desarrollo_local_escribe_sin_interruptor(): void
    {
        $this->escenario('local', 'desarrollo', self::DEV, false);

        $this->escribir()->assertOk()->assertSee('escrito');
        $this->assertTrue(app(DestinoSql::class)->escrituraPermitida());
    }

    public function test_local_contra_produccion_con_escritura_apagada_solo_lee(): void
    {
        $this->escenario('local', 'produccion', self::PROD, false);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(self::RUTA)->assertOk();
        $this->escribir()->assertForbidden()->assertJsonPath('message', DestinoSql::MENSAJE_SOLO_LECTURA);
        $this->assertSame(0, $this->consultas, 'Sin escritura no se consulta el destino real.');
    }

    public function test_local_contra_produccion_con_escritura_encendida_escribe(): void
    {
        // APP_ENV=local no impide escribir; APP_DEBUG=true tampoco fuera de IIS.
        $this->escenario('local', 'produccion', self::PROD, true, debug: true);

        $this->escribir()->assertOk()->assertSee('escrito');
    }

    public function test_iis_produccion_con_escritura_apagada_solo_lee(): void
    {
        $this->escenario('production', 'produccion', self::PROD, false, debug: false);

        $this->withServerVariables(['REMOTE_ADDR' => self::IP_CORPORATIVA])->get(self::RUTA)->assertOk();
        $this->escribir('production')->assertForbidden()->assertJsonPath('message', DestinoSql::MENSAJE_SOLO_LECTURA);
    }

    public function test_iis_produccion_con_escritura_encendida_escribe_y_exige_debug_apagado(): void
    {
        $this->escenario('production', 'produccion', self::PROD, true, debug: false);
        $this->escribir('production')->assertOk()->assertSee('escrito');

        config()->set('app.debug', true);
        $this->escribir('production')->assertForbidden()->assertJsonPath('message', DestinoSql::MENSAJE_DESTINO);
    }

    public function test_control_de_origen_en_local_e_iis(): void
    {
        $this->escenario('production', 'produccion', self::PROD, true, debug: false);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->getJson(self::RUTA)
            ->assertForbidden()->assertJsonPath('message', 'Origen no autorizado.');

        config()->set('acceso.redes', []);
        $this->withServerVariables(['REMOTE_ADDR' => self::IP_CORPORATIVA])->getJson(self::RUTA)->assertForbidden();

        $this->escenario('local', 'desarrollo', self::DEV, false);
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])->getJson(self::RUTA)->assertForbidden();
    }

    public function test_app_env_no_admitido_se_rechaza(): void
    {
        $this->escenario('staging', 'desarrollo', self::DEV, false);
        // runningUnitTests() deja de ser cierto al cambiar APP_ENV: debe rechazarse.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->getJson(self::RUTA)
            ->assertForbidden()->assertJsonPath('message', 'Acceso no autorizado.');
    }

    public function test_configuracion_invalida_bloquea_todo(): void
    {
        // El entorno no coincide con la base configurada.
        $this->escenario('local', 'desarrollo', self::PROD, true);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->getJson(self::RUTA)
            ->assertForbidden()->assertJsonPath('message', 'Base de datos no permitida.');

        // ADM_ENTORNO no reconocido.
        $this->escenario('local', 'otro', self::DEV, true);
        $this->escribir()->assertForbidden();
        $this->assertStringContainsString('ADM_ENTORNO', (string) app(DestinoSql::class)->motivoBloqueo());

        // Destino de producción sin configurar.
        $this->escenario('local', 'produccion', self::PROD, true);
        config()->set('entorno.destinos.produccion.servidor', null);
        $this->escribir()->assertForbidden();
        $this->assertStringContainsString('ADM_PRODUCCION_SERVIDOR', (string) app(DestinoSql::class)->motivoBloqueo());

        // Desarrollo y producción no pueden ser el mismo destino.
        $this->escenario('local', 'desarrollo', self::DEV, false);
        config()->set('entorno.destinos.produccion', ['servidor' => strtolower(self::SERVIDOR), 'base' => strtoupper(self::DEV)]);
        $this->escribir()->assertForbidden();
        $this->assertStringContainsString('no pueden ser el mismo', (string) app(DestinoSql::class)->motivoBloqueo());
    }

    public function test_destino_real_incorrecto_bloquea_la_escritura(): void
    {
        // La configuración coincide, pero DB_NAME() responde otra base.
        $this->escenario('local', 'produccion', self::PROD, true, baseReal: self::DEV);
        $this->escribir()->assertForbidden()->assertJsonPath('message', DestinoSql::MENSAJE_DESTINO);

        // O el servidor no es el autorizado.
        $this->escenario('local', 'produccion', self::PROD, true, servidorReal: 'OTROSERVIDOR');
        $this->escribir()->assertForbidden();
        $this->assertStringContainsString('OTROSERVIDOR', (string) app(DestinoSql::class)->motivoBloqueo());

        // Ni desarrollo escribe si la base real no es la de desarrollo.
        $this->escenario('local', 'desarrollo', self::DEV, false, baseReal: self::PROD);
        $this->escribir()->assertForbidden();
    }

    public function test_servidor_y_base_se_comparan_sin_distinguir_mayusculas(): void
    {
        $this->escenario('local', 'desarrollo', strtoupper(self::DEV), false,
            baseReal: self::DEV, servidorReal: strtolower(self::SERVIDOR));

        $this->escribir()->assertOk();
    }

    public function test_db_url_y_driver_distinto_se_rechazan(): void
    {
        $this->escenario('local', 'desarrollo', self::DEV, false);
        config()->set('database.connections.sqlsrv.url', 'sqlsrv://alternativa');
        $this->escribir()->assertForbidden();

        config()->set('database.connections.sqlsrv.url', null);
        config()->set('database.connections.sqlsrv.driver', 'mysql');
        $this->escribir()->assertForbidden();
    }

    public function test_el_destino_real_se_consulta_una_vez_por_peticion(): void
    {
        $this->escenario('local', 'produccion', self::PROD, true);
        Route::middleware('web')->post('/_prueba/doble', function () {
            $destino = app(DestinoSql::class);
            $destino->exigirEscritura();
            $destino->escrituraPermitida();
            $destino->exigirEscritura();

            return 'ok';
        });

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->postJson('/_prueba/doble')->assertOk();
        $this->assertSame(1, $this->consultas);
    }

    public function test_logout_no_se_bloquea_en_solo_lectura(): void
    {
        $this->escenario('local', 'produccion', self::PROD, false);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->post('/logout')->assertRedirect();
    }

    public function test_head_y_options_no_se_bloquean(): void
    {
        $this->escenario('local', 'produccion', self::PROD, false);
        Route::middleware('web')->match(['GET', 'HEAD', 'OPTIONS'], '/_prueba/metodos', fn () => 'ok');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->call('HEAD', '/_prueba/metodos')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->call('OPTIONS', '/_prueba/metodos')->assertOk();
    }
}
