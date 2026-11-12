<?php

namespace App\Services\Admin;

use App\Autorizacion\Autorizacion;
use App\Autorizacion\CatalogoPermisos;
use App\Entorno\DestinoSql;
use App\Support\Fechas;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Seguimiento de Uso (adm_actividad): una fila por visita a una funcionalidad
 * del catálogo, con sus segundos activos, ligada a la fila de adm_sesiones de
 * la sesión actual (RegistroSesiones). No crea sesiones ni otros
 * identificadores: el navegador nunca indica usuario ni sesión.
 *
 * - Solo cuentan las rutas GET con nombre que el catálogo asocia a una
 *   funcionalidad activa y que la cuenta puede ver (VER), y las páginas
 *   comunes de cualquier cuenta (CatalogoPermisos::PAGINAS_COMUNES, código
 *   `pagina.<ruta>`), que no tienen permisos.
 * - Los segundos de una sesión nunca superan el tiempo real transcurrido
 *   desde su inicio (más una tolerancia), aunque lleguen envíos duplicados.
 * - Escribe solo si DestinoSql lo permite: en solo lectura no hay registro.
 *
 * @phpstan-type FilaHistorial array{id: int, usuario: mixed, ip: mixed, inicioSesion: ?string, modulo: mixed, nombre: mixed, ruta: mixed, visita: ?string, segundosActivos: int}
 */
class SeguimientoUso
{
    /** Prefijo del código de las páginas comunes en adm_actividad.modulo. */
    public const PREFIJO_COMUN = 'pagina.';

    /** Máximo de segundos por envío (el cliente envía cada minuto). */
    public const MAX_SEGUNDOS = 600;

    /** Tolerancia por envío frente al tiempo transcurrido (redondeos y latencia). */
    private const TOLERANCIA = 5;

    private const POR_PAGINA = 25;

    public const ENCABEZADOS = [
        'historial' => ['Usuario', 'IP', 'Inicio de sesión', 'Módulo', 'Código', 'Ruta', 'Fecha y hora de visita', 'Segundos activos'],
        'usuarios' => ['Usuario', 'Sesiones con actividad', 'Visitas', 'Segundos activos'],
        'modulos' => ['Módulo', 'Código', 'Visitas', 'Usuarios', 'Segundos activos', 'Promedio por visita (s)'],
        'diario' => ['Fecha', 'Usuarios', 'Sesiones', 'Visitas', 'Segundos activos'],
    ];

    public function __construct(
        private readonly DestinoSql $destino,
        private readonly RegistroSesiones $registro,
        private readonly Autorizacion $autorizacion,
    ) {}

    /**
     * Funcionalidades del catálogo con pantalla propia: código => nombre.
     *
     * @return array<string, string>
     */
    public static function modulos(): array
    {
        $modulos = [];
        foreach (CatalogoPermisos::PAGINAS_COMUNES as $ruta => $nombre) {
            $modulos[self::PREFIJO_COMUN.$ruta] = $nombre;
        }
        foreach (CatalogoPermisos::funcionalidades() as $codigo => $funcionalidad) {
            if ($funcionalidad['ruta'] !== null) {
                $modulos[$codigo] = $funcionalidad['nombre'];
            }
        }

        return $modulos;
    }

    /** Crea la visita de una ruta conocida; null si no se registra. */
    public function registrarVisita(Request $request, string $ruta): ?int
    {
        $sesion = $this->registro->actual($request);
        $camino = parse_url($ruta, PHP_URL_PATH);
        if ($sesion === null || ! is_string($camino) || $camino === '') {
            return null;
        }

        try {
            $nombre = Route::getRoutes()->match(Request::create($camino, 'GET'))->getName();
        } catch (HttpExceptionInterface) {
            return null;
        }
        if (is_string($nombre) && isset(CatalogoPermisos::PAGINAS_COMUNES[$nombre])) {
            // Página de cualquier cuenta autenticada: no exige permisos.
            $codigo = self::PREFIJO_COMUN.$nombre;
        } else {
            $codigo = $this->codigoDeRuta($nombre);
            if ($codigo === null || ! $this->autorizacion->permite($request, "$codigo:VER")) {
                return null;
            }
        }

        return (int) $this->destino->exigirEscritura()->table('adm_actividad')->insertGetId([
            'sesion_id' => $sesion,
            'modulo' => $codigo,
            'ruta' => mb_substr($camino, 0, 255),
            'visita_utc' => CarbonImmutable::now('UTC'),
            'segundos_activos' => 0,
        ]);
    }

    /** Suma segundos a una visita de la propia sesión, con el tope real. */
    public function sumarActividad(Request $request, int $visita, int $segundos): void
    {
        $sesion = $this->registro->actual($request);
        if ($sesion === null) {
            return;
        }

        $db = $this->destino->exigirEscritura();
        $db->transaction(function () use ($db, $sesion, $visita, $segundos): void {
            $inicio = $db->table('adm_sesiones')->where('id', $sesion)->lockForUpdate()->value('inicio_utc');
            $propia = $db->table('adm_actividad')->where('id', $visita)->where('sesion_id', $sesion)->exists();
            if (! is_string($inicio) || ! $propia) {
                return;
            }

            $transcurrido = (int) max(0, Fechas::utc($inicio)->diffInSeconds(CarbonImmutable::now('UTC')));
            $acumulado = (int) $db->table('adm_actividad')->where('sesion_id', $sesion)->sum('segundos_activos');
            $segundos = min($segundos, self::MAX_SEGUNDOS, $transcurrido + self::TOLERANCIA - $acumulado);
            if ($segundos > 0) {
                $db->table('adm_actividad')->where('id', $visita)->increment('segundos_activos', $segundos);
            }
        });
    }

    /**
     * @param  array<string, string|null>  $filtros
     * @return array{sesiones: int, usuarios: int, visitas: int, segundosActivos: int, promedioPorSesion: int}
     */
    public function indicadores(array $filtros): array
    {
        $fila = $this->consulta($filtros)->selectRaw(
            'COUNT(*) as visitas, COALESCE(SUM(a.segundos_activos), 0) as segundos, COUNT(DISTINCT a.sesion_id) as sesiones, COUNT(DISTINCT s.usuario_id) as usuarios'
        )->first();
        $sesiones = (int) ($fila->sesiones ?? 0);
        $segundos = (int) ($fila->segundos ?? 0);

        return [
            'sesiones' => $sesiones,
            'usuarios' => (int) ($fila->usuarios ?? 0),
            'visitas' => (int) ($fila->visitas ?? 0),
            'segundosActivos' => $segundos,
            'promedioPorSesion' => $sesiones > 0 ? intdiv($segundos, $sesiones) : 0,
        ];
    }

    /**
     * @param  array<string, string|null>  $filtros
     * @return list<array{usuario: string, sesiones: int, visitas: int, segundosActivos: int}>
     */
    public function porUsuario(array $filtros): array
    {
        return array_values($this->consulta($filtros)
            ->groupBy('u.usuario')
            ->selectRaw('u.usuario, COUNT(DISTINCT a.sesion_id) as sesiones, COUNT(*) as visitas, COALESCE(SUM(a.segundos_activos), 0) as segundos')
            ->orderByDesc('segundos')->orderBy('u.usuario')
            ->get()
            ->map(fn ($f) => ['usuario' => $f->usuario, 'sesiones' => (int) $f->sesiones, 'visitas' => (int) $f->visitas, 'segundosActivos' => (int) $f->segundos])
            ->all());
    }

    /**
     * @param  array<string, string|null>  $filtros
     * @return list<array{modulo: string, nombre: string, visitas: int, usuarios: int, segundosActivos: int, promedioPorVisita: int}>
     */
    public function porModulo(array $filtros): array
    {
        $nombres = self::modulos();

        return array_values($this->consulta($filtros)
            ->groupBy('a.modulo')
            ->selectRaw('a.modulo, COUNT(*) as visitas, COUNT(DISTINCT s.usuario_id) as usuarios, COALESCE(SUM(a.segundos_activos), 0) as segundos')
            ->orderByDesc('segundos')->orderBy('a.modulo')
            ->get()
            ->map(fn ($f) => [
                'modulo' => $f->modulo,
                'nombre' => $nombres[$f->modulo] ?? $f->modulo,
                'visitas' => (int) $f->visitas,
                'usuarios' => (int) $f->usuarios,
                'segundosActivos' => (int) $f->segundos,
                'promedioPorVisita' => intdiv((int) $f->segundos, max(1, (int) $f->visitas)),
            ])
            ->all());
    }

    /**
     * Por día de la zona horaria configurada. Se agrega con un cursor: solo
     * los totales por día quedan en memoria.
     *
     * @param  array<string, string|null>  $filtros
     * @return list<array{fecha: string, usuarios: int, sesiones: int, visitas: int, segundosActivos: int}>
     */
    public function diario(array $filtros): array
    {
        $dias = [];
        foreach ($this->consulta($filtros)->orderBy('a.visita_utc')->select(['a.visita_utc', 'a.segundos_activos', 'a.sesion_id', 's.usuario_id'])->cursor() as $fila) {
            $dia = Fechas::dia($fila->visita_utc);
            $dias[$dia] ??= ['usuarios' => [], 'sesiones' => [], 'visitas' => 0, 'segundos' => 0];
            $dias[$dia]['usuarios'][(int) $fila->usuario_id] = true;
            $dias[$dia]['sesiones'][(int) $fila->sesion_id] = true;
            $dias[$dia]['visitas']++;
            $dias[$dia]['segundos'] += (int) $fila->segundos_activos;
        }
        ksort($dias);

        $resultado = [];
        foreach ($dias as $fecha => $d) {
            $resultado[] = ['fecha' => (string) $fecha, 'usuarios' => count($d['usuarios']), 'sesiones' => count($d['sesiones']), 'visitas' => $d['visitas'], 'segundosActivos' => $d['segundos']];
        }

        return $resultado;
    }

    /**
     * @param  array<string, string|null>  $filtros
     * @return LengthAwarePaginator<int|string, FilaHistorial>
     */
    public function historial(array $filtros): LengthAwarePaginator
    {
        $nombres = self::modulos();

        return $this->historialConsulta($filtros)
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (stdClass $f) => [
                'id' => (int) $f->id,
                'usuario' => $f->usuario,
                'ip' => $f->ip,
                'inicioSesion' => Fechas::iso($f->inicio_utc),
                'modulo' => $f->modulo,
                'nombre' => $nombres[$f->modulo] ?? $f->modulo,
                'ruta' => $f->ruta,
                'visita' => Fechas::iso($f->visita_utc),
                'segundosActivos' => (int) $f->segundos_activos,
            ]);
    }

    /**
     * Filas de un reporte para exportar; el historial se recorre con un cursor.
     *
     * @param  array<string, string|null>  $filtros
     * @return iterable<list<string|int|null>>
     */
    public function filasExportacion(string $reporte, array $filtros): iterable
    {
        $nombres = self::modulos();

        if ($reporte === 'historial') {
            foreach ($this->historialConsulta($filtros)->cursor() as $f) {
                yield [$f->usuario, $f->ip, Fechas::local($f->inicio_utc), $nombres[$f->modulo] ?? $f->modulo, $f->modulo, $f->ruta, Fechas::local($f->visita_utc), (int) $f->segundos_activos];
            }

            return;
        }

        $filas = match ($reporte) {
            'usuarios' => array_map(fn ($f) => [$f['usuario'], $f['sesiones'], $f['visitas'], $f['segundosActivos']], $this->porUsuario($filtros)),
            'modulos' => array_map(fn ($f) => [$f['nombre'], $f['modulo'], $f['visitas'], $f['usuarios'], $f['segundosActivos'], $f['promedioPorVisita']], $this->porModulo($filtros)),
            default => array_map(fn ($f) => [$f['fecha'], $f['usuarios'], $f['sesiones'], $f['visitas'], $f['segundosActivos']], $this->diario($filtros)),
        };
        yield from $filas;
    }

    /** @param array<string, string|null> $filtros */
    private function historialConsulta(array $filtros): Builder
    {
        return $this->consulta($filtros)
            ->orderByDesc('a.visita_utc')->orderByDesc('a.id')
            ->select(['a.id', 'u.usuario', 's.ip', 's.inicio_utc', 'a.modulo', 'a.ruta', 'a.visita_utc', 'a.segundos_activos']);
    }

    /** @param array<string, string|null> $filtros */
    private function consulta(array $filtros): Builder
    {
        [$desde, $hasta] = Fechas::rango($filtros['desde'] ?? null, $filtros['hasta'] ?? null);

        return DB::table('adm_actividad as a')
            ->join('adm_sesiones as s', 's.id', '=', 'a.sesion_id')
            ->join('adm_usuarios as u', 'u.id', '=', 's.usuario_id')
            ->when($desde, fn ($q) => $q->where('a.visita_utc', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('a.visita_utc', '<', $hasta))
            ->when($filtros['usuario'] ?? null, fn ($q, $usuario) => $q->where('u.usuario', $usuario))
            ->when($filtros['modulo'] ?? null, fn ($q, $modulo) => $q->where('a.modulo', $modulo));
    }

    private function codigoDeRuta(?string $nombre): ?string
    {
        if ($nombre === null || $nombre === '') {
            return null;
        }
        foreach (CatalogoPermisos::funcionalidades() as $codigo => $funcionalidad) {
            if ($funcionalidad['ruta'] === $nombre && $funcionalidad['activo'] && isset($funcionalidad['acciones']['VER'])) {
                return $codigo;
            }
        }

        return null;
    }
}
