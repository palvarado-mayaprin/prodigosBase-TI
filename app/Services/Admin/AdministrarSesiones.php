<?php

namespace App\Services\Admin;

use App\Auditoria\Auditor;
use App\Entorno\DestinoSql;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sesiones Activas (adm_sesiones): consulta y revocación.
 *
 * - La interfaz nunca recibe el id de la fila: usa una referencia HMAC
 *   (clave de la aplicación) que solo se resuelve contra sesiones abiertas.
 * - Revocar marca la fila (revocada_utc, revocada_por_id); ValidarSesion
 *   rechaza la sesión en su siguiente petición. Las filas no se eliminan.
 * - Nunca se revoca la sesión desde la que se actúa.
 * - La auditoría registra la cuenta afectada, no la IP ni el agente.
 */
class AdministrarSesiones
{
    /** Días de historial (cerradas y revocadas) que muestra el listado. */
    public const DIAS_HISTORIAL = 7;

    private const LIMITE = 500;

    public function __construct(
        private readonly DestinoSql $destino,
        private readonly Auditor $auditor,
    ) {}

    public static function referencia(int $id): string
    {
        return substr(hash_hmac('sha256', "adm_sesiones:$id", (string) config('app.key')), 0, 32);
    }

    /**
     * Sesiones abiertas y las cerradas o revocadas recientes, de la más
     * reciente a la más antigua.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(?int $actual): array
    {
        $ahora = CarbonImmutable::now('UTC');
        $vencimiento = $ahora->subMinutes((int) config('session.lifetime'));

        return array_values(DB::table('adm_sesiones as s')
            ->join('adm_usuarios as u', 'u.id', '=', 's.usuario_id')
            ->leftJoin('adm_usuarios as r', 'r.id', '=', 's.revocada_por_id')
            ->where(fn ($q) => $q->where(fn ($abiertas) => $abiertas->whereNull('s.cierre_utc')->whereNull('s.revocada_utc'))
                ->orWhere('s.ultima_actividad_utc', '>=', $ahora->subDays(self::DIAS_HISTORIAL)))
            ->orderByDesc('s.ultima_actividad_utc')
            ->limit(self::LIMITE)
            ->get(['s.id', 's.usuario_id', 'u.usuario', 'u.nombre', 'u.apellidos', 's.ip', 's.agente',
                's.inicio_utc', 's.ultima_actividad_utc', 's.cierre_utc', 's.revocada_utc', 'r.usuario as revocada_por'])
            ->map(function ($s) use ($actual, $vencimiento): array {
                $ultima = $this->utc($s->ultima_actividad_utc);
                $estado = match (true) {
                    $s->revocada_utc !== null => 'revocada',
                    $s->cierre_utc !== null => 'cerrada',
                    $ultima < $vencimiento => 'vencida',
                    default => 'activa',
                };

                return [
                    'referencia' => self::referencia((int) $s->id),
                    'usuarioId' => (int) $s->usuario_id,
                    'usuario' => $s->usuario,
                    'nombre' => trim($s->nombre.' '.$s->apellidos),
                    'estado' => $estado,
                    'abierta' => $s->revocada_utc === null && $s->cierre_utc === null,
                    'inicio' => $this->utc($s->inicio_utc)->toIso8601ZuluString(),
                    'ultimaActividad' => $ultima->toIso8601ZuluString(),
                    'fin' => ($s->revocada_utc ?? $s->cierre_utc) === null ? null : $this->utc($s->revocada_utc ?? $s->cierre_utc)->toIso8601ZuluString(),
                    'revocadaPor' => $s->revocada_por,
                    'ip' => $s->ip,
                    'agente' => $s->agente,
                    'actual' => (int) $s->id === $actual,
                ];
            })->all());
    }

    /** Revoca una sesión abierta por su referencia. */
    public function revocar(string $referencia, int $actorId, ?int $actual): void
    {
        $db = $this->destino->exigirEscritura();

        $db->transaction(function () use ($db, $referencia, $actorId, $actual): void {
            $sesion = $this->abiertas($db)
                ->first(fn ($fila) => hash_equals(self::referencia((int) $fila->id), $referencia));
            abort_unless($sesion !== null, 404, 'La sesión ya no está abierta.');
            if ((int) $sesion->id === $actual) {
                throw ValidationException::withMessages(['sesion' => 'No puede revocar su propia sesión: use «Cerrar sesión».']);
            }

            $this->marcar($db, $sesion, $actorId);
        });
    }

    /**
     * Revoca las sesiones abiertas de una cuenta, salvo la sesión desde la
     * que se actúa. Devuelve cuántas revocó.
     */
    public function revocarDeUsuario(int $usuarioId, int $actorId, ?int $actual): int
    {
        $db = $this->destino->exigirEscritura();

        return $db->transaction(function () use ($db, $usuarioId, $actorId, $actual): int {
            abort_unless($db->table('adm_usuarios')->where('id', $usuarioId)->exists(), 404, 'La cuenta no existe.');

            $sesiones = $this->abiertas($db, $usuarioId)->reject(fn ($fila) => (int) $fila->id === $actual);
            foreach ($sesiones as $sesion) {
                $this->marcar($db, $sesion, $actorId);
            }

            return $sesiones->count();
        });
    }

    /** @return Collection<int, \stdClass> */
    private function abiertas(Connection $db, ?int $usuarioId = null)
    {
        return $db->table('adm_sesiones as s')
            ->join('adm_usuarios as u', 'u.id', '=', 's.usuario_id')
            ->whereNull('s.cierre_utc')->whereNull('s.revocada_utc')
            ->when($usuarioId !== null, fn ($q) => $q->where('s.usuario_id', $usuarioId))
            ->lockForUpdate()
            ->get(['s.id', 'u.usuario']);
    }

    private function marcar(Connection $db, \stdClass $sesion, int $actorId): void
    {
        $ahora = CarbonImmutable::now('UTC');
        $db->table('adm_sesiones')->where('id', $sesion->id)
            ->update(['revocada_utc' => $ahora, 'revocada_por_id' => $actorId]);
        $this->auditor->registrar($db, 'adm_sesiones', (string) $sesion->id, 'REVOCAR', [
            'usuario' => $sesion->usuario,
            'revocada_utc' => [null, $ahora->toIso8601ZuluString()],
        ]);
    }

    private function utc(string $valor): CarbonImmutable
    {
        return CarbonImmutable::parse($valor, 'UTC');
    }
}
