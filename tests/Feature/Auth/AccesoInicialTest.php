<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

/**
 * Autenticación disponible en la Etapa 2: solo inicio y cierre de sesión.
 * El inicio de sesión con credenciales reales se prueba en la Etapa 4, cuando
 * exista la tabla propia de usuarios.
 */
class AccesoInicialTest extends TestCase
{
    public function test_la_pantalla_de_inicio_de_sesion_se_muestra(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertInertia(fn ($pagina) => $pagina->component('auth/Login'));
    }

    public function test_un_invitado_es_redirigido_al_inicio_de_sesion(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_un_usuario_autenticado_ve_el_inicio(): void
    {
        $usuario = (new User)->forceFill(['id' => 1, 'name' => 'Usuario Ficticio', 'email' => 'ficticio@example.com']);
        $usuario->exists = true;

        $this->actingAs($usuario)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn ($pagina) => $pagina->component('Dashboard'));
    }

    public function test_las_funciones_del_starter_kit_no_existen(): void
    {
        foreach (['/register', '/forgot-password', '/reset-password/token', '/email/verify',
            '/two-factor-challenge', '/user/confirm-password', '/settings/profile', '/settings/security', '/settings/appearance'] as $ruta) {
            $this->get($ruta)->assertNotFound();
        }
        $this->post('/register', [])->assertNotFound();
    }
}
