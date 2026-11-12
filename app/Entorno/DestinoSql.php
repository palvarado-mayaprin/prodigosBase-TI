<?php

namespace App\Entorno;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Única autoridad sobre el destino SQL Server de la conexión predeterminada
 * (DB_CONNECTION, la que usan la aplicación y sus servicios) y sobre
 * la escritura (config/entorno.php).
 *
 * - Lectura: ADM_ENTORNO válido, destino del entorno completo y distinto del
 *   otro entorno, DB_URL vacío, driver sqlsrv y DB_DATABASE igual a la base
 *   del entorno. Se decide solo con la configuración.
 * - Escritura: lo anterior; en producción, además ADM_PRODUCCION_ESCRITURA=true
 *   y APP_DEBUG=false si APP_ENV=production; y siempre que DB_NAME() y
 *
 *   @@SERVERNAME reales coincidan. APP_ENV=local no impide escribir.
 *
 * Servidor y base se comparan sin distinguir mayúsculas, como SQL Server.
 * El destino real se consulta una sola vez por petición (y por configuración).
 * No autoriza migraciones ni DDL: eso corresponde a GuardaDdl.
 * En PHPUnit, DB_DATABASE=:memory: simula el destino (ver simulado()).
 */
class DestinoSql
{
    public const DESARROLLO = 'desarrollo';

    public const PRODUCCION = 'produccion';

    public const MENSAJE_SOLO_LECTURA = 'La base productiva solo permite consultas.';

    public const MENSAJE_DESTINO = 'Destino SQL no autorizado para escribir.';

    private const MEMORIA = 'adm_destino_sql';

    /** desarrollo | produccion, o null si ADM_ENTORNO no es válido. */
    public function entorno(): ?string
    {
        $entorno = config('entorno.entorno');

        return in_array($entorno, [self::DESARROLLO, self::PRODUCCION], true) ? $entorno : null;
    }

    public function esProduccion(): bool
    {
        return $this->entorno() === self::PRODUCCION;
    }

    /** Base que exige el entorno seleccionado. */
    public function catalogoEsperado(): ?string
    {
        $entorno = $this->entorno();

        return $entorno === null ? null : $this->texto(config("entorno.destinos.$entorno.base"));
    }

    /** Servidor (@@SERVERNAME) que exige el entorno seleccionado; vacío si no está configurado. */
    public function servidorEsperado(): string
    {
        $entorno = $this->entorno();

        return $entorno === null ? '' : (string) $this->texto(config("entorno.destinos.$entorno.servidor"));
    }

    public function catalogoConfigurado(): ?string
    {
        return $this->texto($this->configuracionConexion()['database'] ?? null);
    }

    /** Motivo por el que la configuración no es un destino autorizado; null si lo es. */
    public function motivoConfiguracion(): ?string
    {
        $config = $this->configuracionConexion();
        $entorno = $this->entorno();
        if ($entorno === null) {
            return 'ADM_ENTORNO debe ser "desarrollo" o "produccion".';
        }
        if ($this->catalogoEsperado() === null || $this->servidorEsperado() === '') {
            $prefijo = $entorno === self::PRODUCCION ? 'ADM_PRODUCCION' : 'ADM_DESARROLLO';

            return "Falta configurar {$prefijo}_SERVIDOR y {$prefijo}_BD.";
        }
        if ($this->destinosIguales()) {
            return 'Los destinos de desarrollo y producción no pueden ser el mismo servidor y base.';
        }
        if (filled($config['url'] ?? null)) {
            return 'DB_URL debe estar vacío.';
        }
        if ($this->simulado()) {
            return null;
        }
        if (($config['driver'] ?? null) !== 'sqlsrv') {
            return 'La conexión predeterminada (DB_CONNECTION) debe usar el driver sqlsrv.';
        }
        if (! $this->igual($this->catalogoConfigurado(), $this->catalogoEsperado())) {
            return sprintf('DB_DATABASE (%s) no corresponde al entorno %s (%s).',
                $this->catalogoConfigurado() ?? '?', $entorno, $this->catalogoEsperado());
        }

        return null;
    }

    public function configuracionValida(): bool
    {
        return $this->motivoConfiguracion() === null;
    }

    /** Motivo de bloqueo según la configuración, sin consultar SQL Server. */
    public function motivoEscrituraConfigurada(): ?string
    {
        if (($motivo = $this->motivoConfiguracion()) !== null) {
            return $motivo;
        }
        if (! $this->esProduccion()) {
            return null;
        }
        if (config('entorno.escritura_produccion') !== true) {
            return 'ADM_PRODUCCION_ESCRITURA está apagado: producción en solo lectura.';
        }
        if (app()->environment('production') && config('app.debug') === true) {
            return 'Con APP_ENV=production, escribir en producción exige APP_DEBUG=false.';
        }

        return null;
    }

    /** Solo configuración: para la interfaz (props) sin abrir SQL Server. */
    public function escrituraConfigurada(): bool
    {
        return $this->motivoEscrituraConfigurada() === null;
    }

    /** Motivo por el que el destino real no coincide; null si coincide. */
    public function motivoDestinoReal(): ?string
    {
        if (($motivo = $this->motivoConfiguracion()) !== null) {
            return $motivo;
        }
        $real = $this->destinoReal();
        if ($real === null) {
            return 'No se pudo consultar el destino real de SQL Server.';
        }
        if ($real['driver'] !== 'sqlsrv' && ! $this->pruebas()) {
            return 'La conexión abierta no usa el driver sqlsrv.';
        }
        if (! $this->igual($real['base'], $this->catalogoEsperado())) {
            return sprintf('La base conectada (%s) no es %s.', $real['base'] ?? '?', $this->catalogoEsperado());
        }
        if (! $this->igual($real['servidor'], $this->servidorEsperado())) {
            return sprintf('El servidor conectado (%s) no es %s.', $real['servidor'] ?? '?', $this->servidorEsperado());
        }

        return null;
    }

    public function destinoCoincide(): bool
    {
        return $this->motivoDestinoReal() === null;
    }

    /** Motivo completo de bloqueo de escritura (configuración y destino real). */
    public function motivoBloqueo(): ?string
    {
        return $this->motivoEscrituraConfigurada() ?? $this->motivoDestinoReal();
    }

    public function escrituraPermitida(): bool
    {
        return $this->motivoBloqueo() === null;
    }

    /** Mensaje para el usuario; el detalle queda para `php artisan adm:entorno`. */
    public function mensajeBloqueo(): string
    {
        return $this->esProduccion() && $this->configuracionValida() && config('entorno.escritura_produccion') !== true
            ? self::MENSAJE_SOLO_LECTURA
            : self::MENSAJE_DESTINO;
    }

    /** Conexión comprobada para escribir; si no, 403. */
    public function exigirEscritura(): Connection
    {
        abort_unless($this->escrituraPermitida(), 403, $this->mensajeBloqueo());

        return DB::connection();
    }

    /**
     * Destino real (DB_NAME(), @@SERVERNAME y driver), consultado una vez por
     * petición mientras no cambie la configuración. null si no se pudo leer.
     *
     * @return array{base: ?string, servidor: ?string, driver: ?string}|null
     */
    public function destinoReal(): ?array
    {
        return $this->consultaMemorizada()['destino'];
    }

    /** Causa breve del último fallo al consultar el destino real (sin credenciales). */
    public function errorConexion(): ?string
    {
        return $this->consultaMemorizada()['error'];
    }

    /** @return array{base: ?string, servidor: ?string, driver: ?string} */
    protected function consultarDestinoReal(): array
    {
        if ($this->simulado()) {
            return ['base' => $this->catalogoEsperado(), 'servidor' => $this->servidorEsperado(), 'driver' => null];
        }
        $db = DB::connection();
        $driver = $db->getDriverName();
        if ($driver === 'sqlite' && $this->pruebas()) {
            // Destino simulado en PHPUnit: DB_NAME() se registra con
            // sqliteCreateFunction; SQLite no tiene @@SERVERNAME.
            $fila = $db->selectOne('SELECT DB_NAME() AS base');

            return ['base' => $this->texto($fila->base ?? null), 'servidor' => $this->servidorEsperado(), 'driver' => $driver];
        }
        $fila = $db->selectOne('SELECT DB_NAME() AS base, @@SERVERNAME AS servidor');

        return [
            'base' => $this->texto($fila->base ?? null),
            'servidor' => $this->texto($fila->servidor ?? null),
            'driver' => $driver,
        ];
    }

    /** @return array{destino: array{base: ?string, servidor: ?string, driver: ?string}|null, error: ?string} */
    private function consultaMemorizada(): array
    {
        // Memoria en los atributos de la petición actual: se descarta con ella.
        $atributos = app('request')->attributes;
        $clave = md5(serialize([config('entorno'), config('database.default'), $this->configuracionConexion()]));
        $memoria = $atributos->get(self::MEMORIA);
        if (is_array($memoria) && ($memoria['clave'] ?? null) === $clave) {
            return ['destino' => $memoria['destino'], 'error' => $memoria['error']];
        }

        $error = null;
        try {
            $destino = $this->consultarDestinoReal();
        } catch (Throwable $e) {
            $destino = null;
            $error = mb_substr(strtok($e->getMessage(), "\r\n") ?: $e::class, 0, 300);
        }
        $atributos->set(self::MEMORIA, ['clave' => $clave, 'destino' => $destino, 'error' => $error]);

        return ['destino' => $destino, 'error' => $error];
    }

    /**
     * Configuración de la conexión predeterminada.
     *
     * @return array<string, mixed>
     */
    public function configuracionConexion(): array
    {
        $config = config('database.connections.'.config('database.default'));

        return is_array($config) ? $config : [];
    }

    private function destinosIguales(): bool
    {
        $desarrollo = config('entorno.destinos.desarrollo');
        $produccion = config('entorno.destinos.produccion');

        return $this->igual($this->texto($desarrollo['servidor'] ?? null), $this->texto($produccion['servidor'] ?? null))
            && $this->igual($this->texto($desarrollo['base'] ?? null), $this->texto($produccion['base'] ?? null));
    }

    /** PHPUnit con DB_DATABASE=:memory: (phpunit.xml): destino simulado, sin consultar. */
    private function simulado(): bool
    {
        return $this->pruebas() && $this->catalogoConfigurado() === ':memory:';
    }

    /**
     * PHPUnit, también cuando la prueba simula APP_ENV=local o production
     * (runningUnitTests() solo mira APP_ENV=testing). Producción se instala
     * sin dependencias de desarrollo, así que PHPUnit nunca está cargado allí.
     */
    private function pruebas(): bool
    {
        return app()->runningUnitTests() || defined('PHPUNIT_COMPOSER_INSTALL');
    }

    private function igual(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && strcasecmp($a, $b) === 0;
    }

    private function texto(mixed $valor): ?string
    {
        return is_scalar($valor) && (string) $valor !== '' ? (string) $valor : null;
    }
}
