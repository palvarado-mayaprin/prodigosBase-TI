<?php

namespace App\Services\Admin;

use App\Auditoria\Auditor;
use App\Support\Fechas;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Auditoría de Modificaciones: consulta y exportación de solo lectura de
 * adm_auditoria. Nunca modifica registros.
 *
 * Los cambios se vuelven a enmascarar al leerlos (claves sensibles a
 * cualquier profundidad y valores con forma de hash), aunque Auditor ya los
 * enmascara al escribir.
 *
 * @phpstan-type Campo array{campo: string, anterior: ?string, nuevo: ?string, cambio: bool}
 * @phpstan-type Fila array{id: int, fecha: ?string, usuario: string, ip: ?string, entidad: string, registro: ?string, accion: string, accionTexto: string, campos: list<Campo>, resumen: string}
 */
class ConsultaAuditoria
{
    public const ACCIONES = [
        'CREAR' => 'Alta',
        'EDITAR' => 'Edición',
        'HABILITAR' => 'Habilitación',
        'DESHABILITAR' => 'Deshabilitación',
        'ASIGNAR' => 'Asignación',
        'RETIRAR' => 'Retiro',
        'REVOCAR' => 'Revocación',
    ];

    /** Columnas por las que se puede ordenar. */
    public const ORDEN = ['fecha' => 'fecha_utc', 'usuario' => 'usuario', 'entidad' => 'entidad', 'accion' => 'accion'];

    public const ENCABEZADOS = ['ID', 'Fecha y hora', 'Usuario', 'IP', 'Entidad', 'Registro', 'Acción', 'Cambios'];

    private const POR_PAGINA = 25;

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array<string, string|null>  $filtros
     * @return LengthAwarePaginator<int|string, Fila>
     */
    public function listado(array $filtros): LengthAwarePaginator
    {
        return $this->consulta($filtros)
            ->paginate(self::POR_PAGINA, ['id', 'fecha_utc', 'usuario', 'ip', 'entidad', 'registro', 'accion', 'cambios'])
            ->withQueryString()
            ->through(fn (stdClass $fila) => $this->fila($fila));
    }

    /**
     * Filas de exportación con un cursor: no carga el historial en memoria.
     *
     * @param  array<string, string|null>  $filtros
     * @return iterable<list<string|int|null>>
     */
    public function filasExportacion(array $filtros): iterable
    {
        foreach ($this->consulta($filtros)->select(['id', 'fecha_utc', 'usuario', 'ip', 'entidad', 'registro', 'accion', 'cambios'])->cursor() as $fila) {
            $datos = $this->fila($fila);
            yield [
                $datos['id'],
                Fechas::local($fila->fecha_utc),
                $datos['usuario'],
                $datos['ip'],
                $datos['entidad'],
                $datos['registro'],
                $datos['accionTexto'],
                $this->texto($datos['campos']),
            ];
        }
    }

    /** @return list<string> */
    public function usuarios(): array
    {
        return array_values(DB::table('adm_auditoria')->whereNotNull('usuario')->distinct()->orderBy('usuario')->pluck('usuario')->all());
    }

    /** @return list<string> */
    public function entidades(): array
    {
        return array_values(DB::table('adm_auditoria')->distinct()->orderBy('entidad')->pluck('entidad')->all());
    }

    /** @param array<string, string|null> $filtros */
    private function consulta(array $filtros): Builder
    {
        [$desde, $hasta] = Fechas::rango($filtros['desde'] ?? null, $filtros['hasta'] ?? null);
        $texto = $filtros['texto'] ?? null;
        $columna = self::ORDEN[$filtros['orden'] ?? 'fecha'] ?? 'fecha_utc';
        $direccion = ($filtros['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return DB::table('adm_auditoria')
            ->when($desde, fn ($q) => $q->where('fecha_utc', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('fecha_utc', '<', $hasta))
            ->when($filtros['usuario'] ?? null, fn ($q, $usuario) => $q->where('usuario', $usuario))
            ->when($filtros['entidad'] ?? null, fn ($q, $entidad) => $q->where('entidad', $entidad))
            ->when($filtros['accion'] ?? null, fn ($q, $accion) => $q->where('accion', $accion))
            ->when($texto, function ($q) use ($texto) {
                $patron = '%'.addcslashes((string) $texto, '\\%_').'%';
                $q->where(fn ($o) => $o->where('usuario', 'like', $patron)->orWhere('entidad', 'like', $patron)
                    ->orWhere('registro', 'like', $patron)->orWhere('cambios', 'like', $patron));
            })
            ->orderBy($columna, $direccion)
            ->orderBy('id', $direccion);
    }

    /** @return Fila */
    private function fila(stdClass $fila): array
    {
        $campos = $this->campos($fila->cambios);
        $modificados = array_values(array_filter($campos, fn ($c) => $c['cambio']));

        return [
            'id' => (int) $fila->id,
            'fecha' => Fechas::iso($fila->fecha_utc),
            'usuario' => $fila->usuario ?? 'Consola',
            'ip' => $fila->ip,
            'entidad' => $fila->entidad,
            'registro' => $fila->registro,
            'accion' => $fila->accion,
            'accionTexto' => self::ACCIONES[$fila->accion] ?? $fila->accion,
            'campos' => $campos,
            'resumen' => $campos === [] ? 'Sin detalle'
                : ($modificados === [] ? implode(', ', array_column($campos, 'campo'))
                    : count($modificados).' '.(count($modificados) === 1 ? 'campo' : 'campos').': '.implode(', ', array_column($modificados, 'campo'))),
        ];
    }

    /**
     * Cada clave del JSON: un par [anterior, nuevo] es un cambio; cualquier
     * otro valor es un dato descriptivo (p. ej. la cuenta afectada).
     *
     * @return list<array{campo: string, anterior: ?string, nuevo: ?string, cambio: bool}>
     */
    private function campos(?string $json): array
    {
        $datos = $json === null ? null : json_decode($json, true);
        if (! is_array($datos)) {
            return [];
        }

        $campos = [];
        foreach ($this->auditor->enmascarar($datos) as $campo => $valor) {
            $par = is_array($valor) && array_is_list($valor) && count($valor) === 2;
            $campos[] = [
                'campo' => (string) $campo,
                'anterior' => $par ? $this->valor($valor[0]) : null,
                'nuevo' => $this->valor($par ? $valor[1] : $valor),
                'cambio' => $par,
            ];
        }

        return $campos;
    }

    private function valor(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }
        if (is_array($valor)) {
            $valor = $this->ocultarHashes($valor);

            return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return $this->esHash((string) $valor) ? Auditor::OCULTO : (string) $valor;
    }

    /**
     * @param  array<array-key, mixed>  $datos
     * @return array<array-key, mixed>
     */
    private function ocultarHashes(array $datos): array
    {
        foreach ($datos as $clave => $valor) {
            $datos[$clave] = is_array($valor) ? $this->ocultarHashes($valor)
                : (is_string($valor) && $this->esHash($valor) ? Auditor::OCULTO : $valor);
        }

        return $datos;
    }

    /** bcrypt o argon: nunca se muestra aunque llegue con una clave no sensible. */
    private function esHash(string $valor): bool
    {
        return preg_match('/^\$(2[abxy]|argon2i|argon2id)\$/', $valor) === 1;
    }

    /** @param list<array{campo: string, anterior: ?string, nuevo: ?string, cambio: bool}> $campos */
    private function texto(array $campos): string
    {
        return implode('; ', array_map(fn ($c) => $c['cambio']
            ? $c['campo'].': '.($c['anterior'] ?? '—').' → '.($c['nuevo'] ?? '—')
            : $c['campo'].': '.($c['nuevo'] ?? '—'), $campos));
    }
}
