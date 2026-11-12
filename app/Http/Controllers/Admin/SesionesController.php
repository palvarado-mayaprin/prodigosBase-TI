<?php

namespace App\Http\Controllers\Admin;

use App\Entorno\DestinoSql;
use App\Http\Controllers\Controller;
use App\Services\Admin\AdministrarSesiones;
use App\Services\Admin\RegistroSesiones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sesiones Activas (administracion.sesiones.activas, soloAdministrador):
 * consulta (VER) y revocación (ELIMINAR).
 */
class SesionesController extends Controller
{
    public function __construct(
        private readonly AdministrarSesiones $servicio,
        private readonly RegistroSesiones $registro,
    ) {}

    public function index(Request $request, DestinoSql $destino): Response
    {
        return Inertia::render('admin/Sesiones', [
            'sesiones' => $this->servicio->listar($this->registro->actual($request)),
            'actorId' => (int) $request->user()?->getAuthIdentifier(),
            'minutosVigencia' => (int) config('session.lifetime'),
            'diasHistorial' => AdministrarSesiones::DIAS_HISTORIAL,
            'soloLectura' => ! $destino->escrituraConfigurada(),
        ]);
    }

    public function revocar(Request $request, string $referencia): RedirectResponse
    {
        $this->servicio->revocar($referencia, $this->actor($request), $this->registro->actual($request));

        return back()->with('status', 'Sesión revocada.');
    }

    public function revocarUsuario(Request $request, int $usuario): RedirectResponse
    {
        $cantidad = $this->servicio->revocarDeUsuario($usuario, $this->actor($request), $this->registro->actual($request));

        return back()->with('status', match ($cantidad) {
            0 => 'No había otras sesiones abiertas.',
            1 => 'Se revocó 1 sesión.',
            default => "Se revocaron $cantidad sesiones.",
        });
    }

    private function actor(Request $request): int
    {
        return (int) $request->user()?->getAuthIdentifier();
    }
}
