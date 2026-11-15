<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render each initial request made to your application's pages
    | so that server rendered HTML is delivered for the user's browser.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [
        'enabled' => true,
        'url' => 'http://127.0.0.1:13714',
        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | These options configure how Inertia discovers page components on the
    | filesystem. The paths and extensions are used to locate components
    | when rendering responses and during testing assertions.
    |
    */

    'pages' => [

        'paths' => [
            resource_path('js/pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to the paths.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | DevTools (deshabilitado)
    |--------------------------------------------------------------------------
    |
    | Inertia DevTools graba cada petición en storage/inertia-devtools (cuerpo,
    | props y cabeceras) y la sirve en /_inertia/devtools/entries; el paquete
    | lo activa por defecto con APP_ENV=local. Solo oculta campos por nombre
    | exacto en inglés, así que contrasena_actual quedaba en texto plano.
    | Desactivado en todos los entornos y sin variable de entorno: no registra
    | peticiones ni declara sus rutas. Las herramientas de diagnóstico no deben
    | almacenar credenciales (docs/MANUAL_TECNICO.md, sección 9).
    |
    */

    'devtools' => [

        'enabled' => false,

    ],

];
