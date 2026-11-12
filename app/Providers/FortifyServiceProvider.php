<?php

namespace App\Providers;

use App\Models\Usuario;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

/**
 * Fortify solo atiende el inicio y el cierre de sesión: config/fortify.php no
 * habilita registro, recuperación, verificación, 2FA ni passkeys.
 *
 * El inicio de sesión no escribe en SQL Server: funciona igual con la
 * producción en solo lectura. CSRF, regeneración de la sesión y límite de
 * intentos los aplican Laravel y Fortify.
 */
class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Hash bcrypt de un valor aleatorio ya descartado: no corresponde a ninguna
     * contraseña. Iguala el tiempo de respuesta cuando la cuenta no existe.
     */
    private const HASH_FICTICIO = '$2y$12$Z8dxoKAIJ7HkHoWEC9.Uo.1a0MiXYIi1gktEsU5w6igS8FCY8CmGW';

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

        Fortify::authenticateUsing(function (Request $request): ?Usuario {
            // Sin «recordarme»: la sesión vence con su tiempo de vida.
            if ($request->boolean('remember')) {
                return null;
            }

            $usuario = Usuario::query()->where('usuario', (string) $request->input(Fortify::username()))->first();
            $hash = $usuario === null ? self::HASH_FICTICIO : $usuario->password;
            // Se compara siempre, exista o no la cuenta, para no revelarlo por el tiempo de respuesta.
            $valida = Hash::check((string) $request->input('password'), $hash);

            return $usuario !== null && $usuario->activo && $valida ? $usuario : null;
        });

        RateLimiter::for('login', function (Request $request) {
            $clave = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($clave);
        });
    }
}
