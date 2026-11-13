<?php

namespace Tests;

use App\Services\Admin\RegistroSesiones;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Laravel no emite los eventos de consola (CommandStarting) en PHPUnit;
     * GuardaDdl los usa para detener migrate antes de crear dbo.migrations.
     * Llamar antes del primer artisan() de la prueba.
     */
    protected function activarEventosDeComandos(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $this->assertInstanceOf(Kernel::class, $kernel);
        $kernel->rerouteSymfonyCommandEvents();
    }

    /**
     * actingAs() cambia de cuenta sin iniciar sesión: equivale a una sesión
     * nueva, que ValidarSesion registra en la siguiente petición. Sin esto la
     * sesión conservaría el registro de la cuenta anterior y se rechazaría.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        if ($this->app->bound('session.store')) {
            $this->app['session.store']->forget(RegistroSesiones::CLAVE);
        }

        return parent::be($user, $guard);
    }
}
