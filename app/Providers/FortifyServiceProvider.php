<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

/**
 * Fortify solo atiende el inicio y el cierre de sesión: config/fortify.php no
 * habilita registro, recuperación, verificación, 2FA ni passkeys.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Las rutas de Fortify se declaran en routes/web.php: solo login y logout.
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'status' => $request->session()->get('status'),
        ]));

        RateLimiter::for('login', function (Request $request) {
            $clave = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($clave);
        });
    }
}
