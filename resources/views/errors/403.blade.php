@php
    // Página 403 en español con la distribución del inicio de sesión
    // (adm-login-*). Muestra solo el mensaje explícito de abort(), que la
    // aplicación redacta para el usuario; nunca trazas ni datos internos.
    // Sin assets compilados la página se muestra igual, sin estilos.
    try {
        $estilos = app(\Illuminate\Foundation\Vite::class)(['resources/css/app.css'])->toHtml();
    } catch (\Throwable) {
        $estilos = '';
    }
    $nombreApp = trim((string) config('app.name')) ?: 'Administración';
    $mensaje = trim((string) $exception->getMessage()) ?: 'No tiene permiso para acceder a esta página.';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <style>
            html {
                background-color: var(--adm-fondo);
                color-scheme: light;
            }
        </style>
        <link rel="icon" href="data:,">
        {!! $estilos !!}
        <title>Acceso denegado - {{ $nombreApp }}</title>
    </head>
    <body class="font-sans antialiased">
        <main class="adm-login">
            <aside class="adm-login-brand" aria-hidden="true">
                <span class="adm-login-monogram">{{ mb_substr($nombreApp, 0, 1) }}</span>
            </aside>

            <div class="adm-login-content">
                <div class="adm-login-inner">
                    <header class="mb-8">
                        @if (config('app.descripcion'))
                            <p class="adm-eyebrow mb-2">{{ config('app.descripcion') }}</p>
                        @endif
                        <p class="adm-login-title">{{ $nombreApp }}</p>
                    </header>

                    <section class="adm-login-card" aria-labelledby="error-titulo">
                        <p class="adm-eyebrow mb-2">Error 403</p>
                        <h1 id="error-titulo" class="adm-login-card-title">Acceso denegado</h1>
                        <p class="adm-muted mt-1 mb-6 text-sm">{{ $mensaje }}</p>
                        <a href="{{ url('/') }}" class="adm-btn adm-btn-primary">Volver al inicio</a>
                    </section>
                </div>
            </div>
        </main>
    </body>
</html>
