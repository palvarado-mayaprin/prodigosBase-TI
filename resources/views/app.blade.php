<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Fondo inicial con la paleta (tokens.css), antes de pintar la aplicación. --}}
        <style>
            html {
                background-color: var(--adm-fondo);
                color-scheme: light;
            }
        </style>

        {{-- Sin icono de marca: evita pedir /favicon.ico. Para usar uno propio,
             colocarlo en public/ y enlazarlo aquí (docs/GUIA_DISENO_UI.md). --}}
        <link rel="icon" href="data:,">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
