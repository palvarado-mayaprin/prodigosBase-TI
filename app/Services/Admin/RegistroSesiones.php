<?php

namespace App\Services\Admin;

use App\Entorno\DestinoSql;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Registro técnico de sesiones en adm_sesiones (la sesión de Laravel sigue en
 * archivos, SESSION_DRIVER=file).
 *
 * - La base no guarda el identificador de sesión ni la cookie: es la sesión
 *   (en el servidor) la que guarda el id de su fila. Al regenerarse la sesión
 *   (inicio de sesión, cambio de contraseña) el vínculo se conserva.
 * - Cada petición autenticada relee la fila: si se cerró, se revocó, no
 *   existe o pertenece a otra cuenta, la sesión se rechaza.
 * - Las escrituras usan DestinoSql. Sin escritura permitida (producción en
 *   solo lectura) la sesión no se registra ni actualiza: funciona, pero no
 *   aparece en Sesiones Activas ni se puede revocar. Se registra en la primera
 *   petición en que la escritura vuelva a estar permitida.
 */
class RegistroSesiones
{
    /** Clave en la sesión de Laravel con el id de su fila en adm_sesiones. */
    public const CLAVE = 'adm_sesion_id';

    /** Segundos mínimos entre actualizaciones de la última actividad. */
    private const INTERVALO_ACTIVIDAD = 60;

    public function __construct(private readonly DestinoSql $destino) {}

    /** Inicio de sesión: crea la fila si la escritura está permitida. */
    public function registrar(Request $request, Usuario $usuario): void
    {
        if (! $this->destino->escrituraPermitida()) {
            $request->session()->forget(self::CLAVE);

            return;
        }

        $ahora = CarbonImmutable::now('UTC');
        $id = (int) $this->destino->exigirEscritura()->table('adm_sesiones')->insertGetId([
            'usuario_id' => $usuario->id,
            'ip' => $request->ip(),
            'agente' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'inicio_utc' => $ahora,
            'ultima_actividad_utc' => $ahora,
        ]);
        $request->session()->put(self::CLAVE, $id);
    }

    /**
     * Petición autenticada: false si la sesión debe rechazarse. Registra la
     * sesión que aún no lo esté y actualiza la última actividad.
     */
    public function vigente(Request $request, Usuario $usuario): bool
    {
        $id = $request->session()->get(self::CLAVE);

        try {
            if (! is_int($id)) {
                $this->registrar($request, $usuario);

                return true;
            }

            $fila = DB::table('adm_sesiones')->where('id', $id)
                ->first(['usuario_id', 'ultima_actividad_utc', 'cierre_utc', 'revocada_utc']);
            if ($fila === null || (int) $fila->usuario_id !== $usuario->id || $fila->cierre_utc !== null || $fila->revocada_utc !== null) {
                return false;
            }

            $ahora = CarbonImmutable::now('UTC');
            if (CarbonImmutable::parse($fila->ultima_actividad_utc, 'UTC')->addSeconds(self::INTERVALO_ACTIVIDAD) <= $ahora
                && $this->destino->escrituraPermitida()) {
                $this->destino->exigirEscritura()->table('adm_sesiones')->where('id', $id)
                    ->whereNull('cierre_utc')->whereNull('revocada_utc')
                    ->update(['ultima_actividad_utc' => $ahora]);
            }
        } catch (QueryException) {
            abort(503, 'No se pudo comprobar la sesión.');
        }

        return true;
    }

    /** Cierre normal (Logout): marca la fila si la escritura está permitida. */
    public function cerrar(Request $request): void
    {
        $id = $request->hasSession() ? $request->session()->pull(self::CLAVE) : null;
        if (! is_int($id) || ! $this->destino->escrituraPermitida()) {
            return;
        }

        $this->destino->exigirEscritura()->table('adm_sesiones')->where('id', $id)
            ->whereNull('cierre_utc')->whereNull('revocada_utc')
            ->update(['cierre_utc' => CarbonImmutable::now('UTC')]);
    }

    /** Id de la fila de la sesión actual (para no revocarla por error). */
    public function actual(Request $request): ?int
    {
        $id = $request->hasSession() ? $request->session()->get(self::CLAVE) : null;

        return is_int($id) ? $id : null;
    }
}
