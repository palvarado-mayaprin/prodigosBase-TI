<?php

namespace Tests;

use App\Services\Admin\RegistroSesiones;
use Illuminate\Contracts\Auth\Authenticatable;
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
