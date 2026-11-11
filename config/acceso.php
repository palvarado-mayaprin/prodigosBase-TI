<?php

/*
 * Control de origen (App\Http\Middleware\ControlarAcceso).
 *
 * Con APP_ENV=production solo se admiten direcciones dentro de estas redes
 * (CIDR separadas por comas); vacío equivale a rechazar todo. En local solo
 * se admite el propio equipo (127.0.0.1 y ::1).
 */
return [
    'redes' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('ADM_REDES_CORPORATIVAS', ''))
    ))),
];
