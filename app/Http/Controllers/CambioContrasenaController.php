<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\Admin\CambiarContrasena;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Cambio de la contraseña propia; obligatorio si debe_cambiar_password = 1. */
class CambioContrasenaController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('auth/CambiarContrasena', [
            'obligatorio' => $this->usuario($request)->debe_cambiar_password,
            'reglas' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(Request $request, CambiarContrasena $servicio): RedirectResponse
    {
        $datos = $request->validate([
            'contrasena_actual' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $servicio->cambiar($this->usuario($request), $datos['contrasena_actual'], $datos['password']);
        // Nueva credencial: nuevo identificador de sesión.
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Contraseña actualizada.');
    }

    private function usuario(Request $request): Usuario
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof Usuario, 403);

        return $usuario;
    }
}
