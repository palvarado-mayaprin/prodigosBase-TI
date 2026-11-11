<?php

/*
 * Entorno de datos (App\Entorno\DestinoSql).
 *
 * ADM_ENTORNO elige el destino SQL Server, que debe coincidir con DB_DATABASE
 * y con el destino real (DB_NAME() y @@SERVERNAME). APP_ENV sigue gobernando
 * Laravel, pero no autoriza ni impide por sí solo escribir en producción.
 * Nada de este archivo habilita migraciones ni DDL (App\Entorno\GuardaDdl).
 */
return [
    // desarrollo | produccion. Cualquier otro valor bloquea el acceso.
    'entorno' => env('ADM_ENTORNO', 'desarrollo'),

    // Único interruptor de escritura en producción, para todos los módulos.
    // En desarrollo no tiene efecto.
    'escritura_produccion' => env('ADM_PRODUCCION_ESCRITURA', false) === true,

    // Destinos autorizados: servidor (@@SERVERNAME) y base de cada entorno.
    // Sin valor, ese entorno no es un destino válido.
    'destinos' => [
        'desarrollo' => [
            'servidor' => env('ADM_DESARROLLO_SERVIDOR'),
            'base' => env('ADM_DESARROLLO_BD'),
        ],
        'produccion' => [
            'servidor' => env('ADM_PRODUCCION_SERVIDOR'),
            'base' => env('ADM_PRODUCCION_BD'),
        ],
    ],
];
