<?php

namespace App\Http\Middleware;

use App\Entorno\DestinoSql;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Primera barrera de todo el grupo `web` (bootstrap/app.php):
 *
 * 1. Solo APP_ENV local o production (y PHPUnit).
 * 2. Origen: en production, dentro de ADM_REDES_CORPORATIVAS; en local, solo
 *    el propio equipo.
 * 3. Destino SQL coherente con ADM_ENTORNO (DestinoSql, sin consultar la base).
 * 4. Toda petición que no sea GET/HEAD/OPTIONS exige escritura permitida y el
 *    destino real comprobado, salvo RUTAS_SIN_ESCRITURA. Un módulo nuevo queda
 *    cubierto sin modificar este middleware.
 *
 * No sustituye a la autenticación ni a los permisos: solo los precede.
 */
class ControlarAcceso
{
    /**
     * Peticiones no GET que no escriben en SQL Server. Solo se añade aquí una
     * ruta POST de consulta, nunca una que escriba.
     */
    public const RUTAS_SIN_ESCRITURA = ['login.store', 'logout'];

    public static function origenPermitido(Request $request): bool
    {
        $ip = $request->server('REMOTE_ADDR');
        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (app()->environment('production')) {
            $redes = config('acceso.redes', []);

            return is_array($redes) && $redes !== [] && IpUtils::checkIp($ip, $redes);
        }

        return (app()->environment('local') || app()->runningUnitTests())
            && in_array($ip, ['127.0.0.1', '::1'], true);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local', 'production') && ! app()->runningUnitTests()) {
            abort(403, 'Acceso no autorizado.');
        }

        if (! self::origenPermitido($request)) {
            abort(403, 'Origen no autorizado.');
        }

        $destino = app(DestinoSql::class);
        if (! $destino->configuracionValida()) {
            abort(403, 'Base de datos no permitida.');
        }

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && ! $request->routeIs(...self::RUTAS_SIN_ESCRITURA)
            && ! $destino->escrituraPermitida()) {
            abort(403, $destino->mensajeBloqueo());
        }

        return $next($request);
    }
}
