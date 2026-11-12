<?php

namespace Tests\Feature\Auth;

use App\Entorno\DestinoSql;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/** Cambio de contraseña propio y obligatorio (ExigirCambioContrasena). */
class CambioContrasenaTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private const NUEVA = 'Nueva-Clave-Segura-26';

    private function cambiar(array $datos): TestResponse
    {
        return $this->put(route('contrasena.actualizar'), $datos + [
            'contrasena_actual' => self::CLAVE, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA,
        ]);
    }

    public function test_la_cuenta_con_cambio_obligatorio_solo_puede_cambiar_la_contrasena_o_salir(): void
    {
        $this->actingAs($this->crearUsuario(atributos: ['debe_cambiar_password' => true]));

        $this->get(route('dashboard'))->assertRedirect(route('contrasena.editar'));
        $this->getJson(route('dashboard'))->assertForbidden();
        $this->get(route('contrasena.editar'))->assertOk()
            ->assertInertia(fn ($pagina) => $pagina->component('auth/CambiarContrasena')->where('obligatorio', true));
        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_cambia_la_contrasena_audita_sin_secretos_y_libera_la_cuenta(): void
    {
        $usuario = $this->crearUsuario(atributos: ['debe_cambiar_password' => true]);
        $this->actingAs($usuario);

        $this->cambiar([])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

        $fila = DB::table('adm_usuarios')->find($usuario->id);
        $this->assertTrue(Hash::check(self::NUEVA, $fila->password));
        $this->assertSame(0, (int) $fila->debe_cambiar_password);
        $auditoria = DB::table('adm_auditoria')->sole();
        $this->assertSame(['adm_usuarios', (string) $usuario->id, 'EDITAR', $usuario->id], [$auditoria->entidad, $auditoria->registro, $auditoria->accion, (int) $auditoria->usuario_id]);
        $this->assertStringNotContainsString(self::NUEVA, $auditoria->cambios);
        $this->assertStringNotContainsString(self::CLAVE, $auditoria->cambios);
        $this->assertStringNotContainsString('$2y$', $auditoria->cambios);
        // La aplicación relee la cuenta en cada petición; actingAs conserva el modelo anterior.
        $this->actingAs($usuario->fresh());
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_valida_contrasena_actual_politica_confirmacion_y_cambio_real(): void
    {
        $this->actingAs($this->crearUsuario());

        $this->cambiar(['contrasena_actual' => 'Incorrecta-2026-x'])->assertSessionHasErrors('contrasena_actual');
        $this->cambiar(['password' => 'corta', 'password_confirmation' => 'corta'])->assertSessionHasErrors('password');
        $this->cambiar(['password' => 'sinmayusculas-2026', 'password_confirmation' => 'sinmayusculas-2026'])->assertSessionHasErrors('password');
        $this->cambiar(['password_confirmation' => 'Distinta-Clave-2026'])->assertSessionHasErrors('password');
        $this->cambiar(['password' => self::CLAVE, 'password_confirmation' => self::CLAVE])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::CLAVE, (string) DB::table('adm_usuarios')->value('password')));
        $this->assertSame(0, DB::table('adm_auditoria')->count());
    }

    public function test_produccion_en_solo_lectura_no_permite_cambiarla(): void
    {
        $this->actingAs($this->crearUsuario());
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', false);

        $this->cambiar([])->assertForbidden()->assertSee(DestinoSql::MENSAJE_SOLO_LECTURA);
        $this->assertTrue(Hash::check(self::CLAVE, (string) DB::table('adm_usuarios')->value('password')));
    }

    public function test_si_falla_la_auditoria_no_cambia_la_contrasena(): void
    {
        $this->actingAs($this->crearUsuario());
        DB::statement('DROP TABLE adm_auditoria');

        $this->withoutExceptionHandling();
        try {
            $this->cambiar([]);
            $this->fail('La auditoría fallida debió interrumpir el cambio.');
        } catch (QueryException) {
            $this->assertTrue(Hash::check(self::CLAVE, (string) DB::table('adm_usuarios')->value('password')));
        }
    }
}
