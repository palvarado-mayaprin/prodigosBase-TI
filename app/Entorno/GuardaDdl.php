<?php

namespace App\Entorno;

use RuntimeException;

/**
 * Autorización de migraciones y DDL, separada del interruptor de escritura.
 *
 * Se ejecuta al empezar cualquier migración (evento MigrationsStarted):
 * - el destino real debe coincidir con ADM_ENTORNO (DestinoSql);
 * - en producción, además, la variable de proceso ADM_DESPLIEGUE_DDL debe
 *   valer exactamente la base de producción. Se fija solo en la consola de
 *   despliegue, nunca en `.env`.
 * ADM_PRODUCCION_ESCRITURA nunca habilita DDL.
 */
class GuardaDdl
{
    public const AUTORIZACION = 'ADM_DESPLIEGUE_DDL';

    public function __construct(private readonly DestinoSql $destino) {}

    /**
     * Motivo por el que no se permite DDL; null si se permite.
     *
     * @param  string|null  $conexion  Conexión de la migración (`--database`); null es la predeterminada.
     */
    public function motivoBloqueo(?string $conexion = null): ?string
    {
        $conexion ??= (string) config('database.default');
        if ($conexion !== 'sqlsrv' && ! $this->simuladaEnPruebas($conexion)) {
            return "Las migraciones solo se ejecutan sobre la conexión sqlsrv (se pidió «{$conexion}»).";
        }
        if (($motivo = $this->destino->motivoDestinoReal()) !== null) {
            return $motivo;
        }
        if ($this->destino->esProduccion() && getenv(self::AUTORIZACION) !== $this->destino->catalogoEsperado()) {
            return sprintf('En producción, las migraciones exigen la variable de proceso %s=%s en la consola de despliegue.',
                self::AUTORIZACION, $this->destino->catalogoEsperado());
        }

        return null;
    }

    public function verificar(?string $conexion = null): void
    {
        if (($motivo = $this->motivoBloqueo($conexion)) !== null) {
            throw new RuntimeException('Migración no autorizada: '.$motivo);
        }
    }

    /** PHPUnit migra sobre SQLite en memoria (phpunit.xml); nunca fuera de las pruebas. */
    private function simuladaEnPruebas(string $conexion): bool
    {
        return (app()->runningUnitTests() || defined('PHPUNIT_COMPOSER_INSTALL'))
            && config("database.connections.$conexion.driver") === 'sqlite'
            && config("database.connections.$conexion.database") === ':memory:';
    }
}
