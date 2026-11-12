<?php

namespace App\Providers;

use App\Entorno\GuardaDdl;
use Carbon\CarbonImmutable;
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
