<?php

namespace App\Http\Controllers;

use App\Autorizacion\Autorizacion;
use App\Models\Usuario;
use App\Services\Admin\AdministrarUsuarios;
use App\Services\Admin\CambiarContrasena;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mi Perfil: cualquier cuenta activa, sin permiso del catálogo. La cuenta se
 * identifica solo por la sesión; se ignora cualquier identificador enviado.
 * Solo se editan el correo y el teléfono; la contraseña usa CambiarContrasena.
 */
class MiPerfilController extends Controller
{
    public function __construct(private readonly Autorizacion $autorizacion) {}

    public function show(Request $request): Response
    {
        $usuario = $this->usuarioActivo($request);

        return Inertia::render('MiPerfil', [
            'perfil' => [
                'usuario' => $usuario->usuario,
                'nombreCompleto' => $usuario->nombreCompleto(),
                'email' => $usuario->email,
                'telefono' => $usuario->telefono,
            ],
        ]);
    }

    public function actualizar(Request $request, AdministrarUsuarios $servicio): RedirectResponse
    {
        $usuario = $this->usuarioActivo($request);
        $datos = $request->validate([
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
        ]);

        $cambiado = $servicio->actualizarContacto($usuario->id, $datos);

        return back()->with('status', $cambiado ? 'Datos actualizados.' : 'Sin cambios.');
    }

    public function cambiarContrasena(Request $request, CambiarContrasena $servicio): RedirectResponse
    {
        $usuario = $this->usuarioActivo($request);
        $datos = $request->validate([
            'contrasena_actual' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $servicio->cambiar($usuario, $datos['contrasena_actual'], $datos['password']);
        $request->session()->regenerate();

        return back()->with('status', 'Contraseña actualizada.');
    }

    /** Relee la cuenta de la sesión; si ya no está activa, cierra la sesión (403). */
    private function usuarioActivo(Request $request): Usuario
    {
        $this->autorizacion->contexto($request);

        return Usuario::query()->findOrFail((int) $request->user()?->getAuthIdentifier());
    }
}
