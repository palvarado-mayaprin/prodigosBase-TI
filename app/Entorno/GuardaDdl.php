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

    /** Motivo por el que no se permite DDL; null si se permite. */
    public function motivoBloqueo(): ?string
    {
        if (($motivo = $this->destino->motivoDestinoReal()) !== null) {
            return $motivo;
        }
        if ($this->destino->esProduccion() && getenv(self::AUTORIZACION) !== $this->destino->catalogoEsperado()) {
            return sprintf('En producción, las migraciones exigen la variable de proceso %s=%s en la consola de despliegue.',
                self::AUTORIZACION, $this->destino->catalogoEsperado());
        }

        return null;
    }

    public function verificar(): void
    {
        if (($motivo = $this->motivoBloqueo()) !== null) {
            throw new RuntimeException('Migración no autorizada: '.$motivo);
        }
    }
}
