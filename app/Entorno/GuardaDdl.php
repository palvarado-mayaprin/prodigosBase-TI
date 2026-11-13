<?php

namespace App\Entorno;

use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Autorización de migraciones y DDL, separada del interruptor de escritura.
 *
 * Se ejecuta al empezar cualquier migración (evento MigrationsStarted):
 * - el destino real debe coincidir con ADM_ENTORNO (DestinoSql);
 * - en producción, además, la variable de proceso ADM_DESPLIEGUE_DDL debe
 *   valer exactamente la base de producción. Se fija solo en la consola de
 *   despliegue, nunca en `.env`.
 * ADM_PRODUCCION_ESCRITURA nunca habilita DDL.
 *
 * Además se comprueba al iniciar los comandos de esquema (evento
 * CommandStarting, verificarComando()), antes de que `migrate` o
 * `migrate --pretend` creen la tabla dbo.migrations. Los comandos
 * destructivos (DESTRUCTIVOS y cualquier migración hacia atrás) solo se
 * permiten en una base de desarrollo terminada en "_pruebas" y con la
 * variable de proceso ADM_PRUEBAS_DESTRUCTIVAS igual a esa base.
 */
class GuardaDdl
{
    public const AUTORIZACION = 'ADM_DESPLIEGUE_DDL';

    public const AUTORIZACION_DESTRUCTIVA = 'ADM_PRUEBAS_DESTRUCTIVAS';

    public const SUFIJO_PRUEBAS = '_pruebas';

    /** Comandos que crean o modifican el esquema. */
    public const COMANDOS = ['migrate', 'migrate:install', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'];

    /** Comandos que eliminan tablas o datos. */
    public const DESTRUCTIVOS = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'];

    public function __construct(private readonly DestinoSql $destino) {}

    /**
     * Motivo por el que no se permite DDL; null si se permite.
     *
     * @param  string|null  $conexion  Conexión de la migración (`--database`); null es la predeterminada.
     */
    public function motivoBloqueo(?string $conexion = null): ?string
    {
        // La migración debe usar la conexión que valida DestinoSql (la
        // predeterminada); así `--database` no puede desviarla a otro destino.
        $predeterminada = (string) config('database.default');
        $conexion ??= $predeterminada;
        if ($conexion !== $predeterminada) {
            return "Las migraciones solo se ejecutan sobre la conexión predeterminada «{$predeterminada}» (se pidió «{$conexion}»).";
        }
        if (config("database.connections.$conexion.driver") !== 'sqlsrv' && ! $this->simuladaEnPruebas($conexion)) {
            return 'Las migraciones solo se ejecutan sobre SQL Server (driver sqlsrv).';
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

    /** Motivo por el que no se permite una operación destructiva; null si se permite. */
    public function motivoBloqueoDestructivo(?string $conexion = null): ?string
    {
        if (($motivo = $this->motivoBloqueo($conexion)) !== null) {
            return $motivo;
        }
        $base = (string) $this->destino->catalogoEsperado();
        if ($this->destino->esProduccion() || ! str_ends_with(strtolower($base), self::SUFIJO_PRUEBAS)) {
            return sprintf('Las operaciones destructivas solo se permiten en una base de pruebas de desarrollo (nombre terminado en "%s"); %s no lo es.',
                self::SUFIJO_PRUEBAS, $base);
        }
        if (getenv(self::AUTORIZACION_DESTRUCTIVA) !== $base) {
            return sprintf('Las operaciones destructivas exigen la variable de proceso %s=%s.', self::AUTORIZACION_DESTRUCTIVA, $base);
        }

        return null;
    }

    public function verificar(?string $conexion = null, bool $destructiva = false): void
    {
        $motivo = $destructiva ? $this->motivoBloqueoDestructivo($conexion) : $this->motivoBloqueo($conexion);
        if ($motivo !== null) {
            throw new RuntimeException(($destructiva ? 'Operación destructiva no autorizada: ' : 'Migración no autorizada: ').$motivo);
        }
    }

    /** Antes de ejecutar un comando de esquema (evento CommandStarting). */
    public function verificarComando(string $comando, InputInterface $input): void
    {
        if (! in_array($comando, self::COMANDOS, true)) {
            return;
        }
        $conexion = $input->hasOption('database') ? $input->getOption('database') : null;
        $this->verificar(is_string($conexion) && $conexion !== '' ? $conexion : null, in_array($comando, self::DESTRUCTIVOS, true));
    }

    /** PHPUnit migra sobre SQLite en memoria (phpunit.xml); nunca fuera de las pruebas. */
    private function simuladaEnPruebas(string $conexion): bool
    {
        return (app()->runningUnitTests() || defined('PHPUNIT_COMPOSER_INSTALL'))
            && config("database.connections.$conexion.driver") === 'sqlite'
            && config("database.connections.$conexion.database") === ':memory:';
    }
}
