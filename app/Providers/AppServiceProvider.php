<?php

namespace App\Providers;

use App\Entorno\GuardaDdl;
use App\Models\Usuario;
use App\Services\Admin\RegistroSesiones;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // DDL separado del interruptor de escritura: ver App\Entorno\GuardaDdl.
        Event::listen(MigrationsStarted::class, fn () => app(GuardaDdl::class)->verificar(app('migrator')->getConnection()));

        // Registro de sesiones (adm_sesiones): inicio y cierre normales.
        Event::listen(Login::class, function (Login $evento): void {
            if ($evento->guard === 'web' && $evento->user instanceof Usuario) {
                app(RegistroSesiones::class)->registrar(request(), $evento->user);
            }
        });
        Event::listen(Logout::class, function (Logout $evento): void {
            if ($evento->guard === 'web') {
                app(RegistroSesiones::class)->cerrar(request());
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Política única de contraseñas, en todos los entornos (sin
        // uncompromised(), que exigiría salida a internet).
        Password::defaults(fn (): Password => Password::min(12)
            ->max(72) // bcrypt solo usa los primeros 72 bytes.
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols());
    }
}
