<?php

namespace App\Services\Admin;

use Illuminate\Validation\ValidationException;

/**
 * Sincronización de relaciones (membresías y permisos de grupo) con control
 * de concurrencia optimista: la pantalla envía la lista que leyó (`base`) y la
 * deseada; si la base ya no coincide con la base de datos, otro cambio se
 * adelantó y no se guarda nada.
 */
final class Relaciones
{
    public const CONFLICTO = 'Las asignaciones cambiaron mientras las editaba. Recargue los datos antes de guardar.';

    /**
     * @param  list<int>  $actuales  Leídos con bloqueo dentro de la transacción.
     * @param  array<int, int>  $base
     * @param  array<int, int>  $deseados
     * @return array{0: list<int>, 1: list<int>} Agregados y retirados.
     */
    public static function diferencias(array $actuales, array $base, array $deseados): array
    {
        if (self::ordenar($actuales) !== self::ordenar($base)) {
            throw ValidationException::withMessages(['conflicto' => self::CONFLICTO]);
        }

        return [
            self::ordenar(array_diff($deseados, $actuales)),
            self::ordenar(array_diff($actuales, $deseados)),
        ];
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    public static function ordenar(array $ids): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
