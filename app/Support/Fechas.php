<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Fechas: se guardan en UTC y se presentan y filtran en la zona horaria
 * configurada (app.zona_horaria, ADM_ZONA_HORARIA). Una zona no válida usa UTC.
 */
final class Fechas
{
    public static function zona(): string
    {
        $zona = config('app.zona_horaria');

        return is_string($zona) && in_array($zona, DateTimeZone::listIdentifiers(), true) ? $zona : 'UTC';
    }

    /** Valor guardado en UTC (texto de la base) como fecha UTC. */
    public static function utc(string $valor): CarbonImmutable
    {
        return CarbonImmutable::parse($valor, 'UTC');
    }

    /** ISO 8601 en UTC para la interfaz, que la presenta en la zona configurada. */
    public static function iso(?string $valor): ?string
    {
        return $valor === null ? null : self::utc($valor)->toIso8601ZuluString();
    }

    /** Texto en la zona configurada para exportaciones. */
    public static function local(?string $valor): string
    {
        return $valor === null ? '' : self::utc($valor)->setTimezone(self::zona())->format('Y-m-d H:i:s');
    }

    /** Fecha local (AAAA-MM-DD) de un valor UTC, para agrupar por día. */
    public static function dia(string $valor): string
    {
        return self::utc($valor)->setTimezone(self::zona())->format('Y-m-d');
    }

    /**
     * Días locales (AAAA-MM-DD) como rango UTC [desde, hasta).
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function rango(?string $desde, ?string $hasta): array
    {
        $zona = self::zona();

        return [
            $desde === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $desde, $zona)?->utc(),
            $hasta === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $hasta, $zona)?->addDay()->utc(),
        ];
    }
}
