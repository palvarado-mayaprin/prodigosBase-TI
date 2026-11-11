<?php

namespace App\Console\Commands;

use App\Entorno\DestinoSql;
use App\Entorno\GuardaDdl;
use Illuminate\Console\Command;

/**
 * Diagnóstico de solo lectura del entorno de datos (App\Entorno\DestinoSql).
 * Como mucho ejecuta SELECT DB_NAME(), @@SERVERNAME. No muestra credenciales
 * ni cadenas de conexión. Termina con error si el destino no es coherente.
 */
class VerificarEntorno extends Command
{
    protected $signature = 'adm:entorno';

    protected $description = 'Muestra el entorno seleccionado, el destino SQL real y si la escritura está habilitada (solo lectura)';

    public function handle(DestinoSql $destino, GuardaDdl $ddl): int
    {
        $configuracion = $destino->motivoConfiguracion();
        // Sin configuración válida no se intenta conectar.
        $real = $configuracion === null ? $destino->destinoReal() : null;
        $motivoDestino = $destino->motivoDestinoReal();
        $motivoBloqueo = $destino->motivoBloqueo();
        $sinRespuesta = $configuracion === null ? 'sin respuesta' : 'no consultado';

        $this->table(['Elemento', 'Valor'], [
            ['Entorno seleccionado (ADM_ENTORNO)', $destino->entorno() ?? 'inválido: '.$this->texto(config('entorno.entorno'))],
            ['APP_ENV', (string) app()->environment()],
            ['APP_DEBUG', config('app.debug') === true ? 'true' : 'false'],
            ['Driver configurado', $this->texto(config('database.connections.sqlsrv.driver'))],
            ['DB_URL', filled(config('database.connections.sqlsrv.url')) ? 'definido (no permitido)' : 'vacío'],
            ['Destino esperado', ($destino->servidorEsperado() ?: '?').' / '.($destino->catalogoEsperado() ?? '?')],
            ['Base configurada (DB_DATABASE)', $destino->catalogoConfigurado() ?? '?'],
            ['Base real (DB_NAME())', $real['base'] ?? $sinRespuesta],
            ['Servidor real (@@SERVERNAME)', $real['servidor'] ?? $sinRespuesta],
            ['ADM_PRODUCCION_ESCRITURA', config('entorno.escritura_produccion') === true ? 'true' : 'false'],
            ['Escritura', $motivoBloqueo === null ? 'HABILITADA' : 'BLOQUEADA'],
            ['Motivo del bloqueo', $motivoBloqueo ?? '—'],
            ['Migraciones (DDL)', $ddl->motivoBloqueo() === null ? 'permitidas' : 'bloqueadas'],
        ]);

        if ($configuracion === null && $real === null && ($error = $destino->errorConexion()) !== null) {
            $this->warn('Error de conexión: '.$error);
        }
        if (getenv(GuardaDdl::AUTORIZACION) !== false) {
            $this->warn(GuardaDdl::AUTORIZACION.' está definida: debe existir solo en la consola de despliegue, nunca en .env.');
        }

        if ($motivoDestino !== null) {
            $this->error('Destino no coherente: '.$motivoDestino);

            return self::FAILURE;
        }
        $this->info('Destino coherente con el entorno seleccionado.');

        return self::SUCCESS;
    }

    private function texto(mixed $valor): string
    {
        return is_scalar($valor) ? (string) $valor : '?';
    }
}
