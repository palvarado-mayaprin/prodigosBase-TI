<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdministrarUsuarios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración de Usuarios (administracion.accesos.usuarios). Las rutas
 * exigen VER, CREAR o EDITAR del catálogo; es una funcionalidad
 * soloAdministrador. No elimina cuentas.
 */
class UsuariosController extends Controller
{
    public function index(Request $request): Response
    {
        $usuarios = DB::table('adm_usuarios')
            ->orderBy('usuario')
            ->get(['id', 'usuario', 'nombre', 'apellidos', 'email', 'telefono', 'activo', 'debe_cambiar_password'])
            ->map(fn ($u) => [
                'id' => (int) $u->id,
                'usuario' => $u->usuario,
                'nombre' => $u->nombre,
                'apellidos' => $u->apellidos,
                'nombreCompleto' => trim($u->nombre.' '.$u->apellidos),
                'email' => $u->email,
                'telefono' => $u->telefono,
                'activo' => (bool) $u->activo,
                'debeCambiarPassword' => (bool) $u->debe_cambiar_password,
            ]);

        return Inertia::render('admin/Usuarios', [
            'usuarios' => $usuarios,
            'actorId' => (int) $request->user()?->getAuthIdentifier(),
        ]);
    }

    public function crear(Request $request, AdministrarUsuarios $servicio): RedirectResponse
    {
        $datos = $request->validate([
            ...$this->reglas(),
            'usuario' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'activo' => ['boolean'],
        ]);
        $datos['activo'] = $request->boolean('activo', true);

        $servicio->crear($datos);

        return back()->with('status', 'Usuario creado.');
    }

    public function actualizar(Request $request, int $usuario, AdministrarUsuarios $servicio): RedirectResponse
    {
        $datos = $request->validate([
            ...$this->reglas(),
            // Identificador de inicio de sesión: se fija al crear la cuenta.
            'usuario' => ['prohibited'],
            // Vacía conserva la actual; si se envía, cumple la política.
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
        ]);

        $cambiado = $servicio->actualizar($usuario, $datos);

        return back()->with('status', $cambiado ? 'Usuario actualizado.' : 'Sin cambios.');
    }

    public function estado(Request $request, int $usuario, AdministrarUsuarios $servicio): RedirectResponse
    {
        $datos = $request->validate(['activo' => ['required', 'boolean']]);

        $servicio->cambiarEstado($usuario, (bool) $datos['activo'], (int) $request->user()?->getAuthIdentifier());

        return back()->with('status', $datos['activo'] ? 'Usuario habilitado.' : 'Usuario deshabilitado.');
    }

    /** @return array<string, list<string>> */
    private function reglas(): array
    {
        // Longitudes de adm_usuarios.
        return [
            'nombre' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
        ];
    }
}
