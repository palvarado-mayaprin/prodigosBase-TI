<?php

namespace Tests\Feature;

use App\Entorno\DestinoSql;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/** Mi Perfil: datos de contacto propios y cambio de la contraseña propia. */
class MiPerfilTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private const NUEVA = 'Clave-Nueva-Segura-26';

    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        // Cualquier cuenta activa, sin grupos ni permisos.
        $this->usuario = $this->crearUsuario('ana', ['email' => 'ana@example.com', 'telefono' => '5555-0000']);
        $this->actingAs($this->usuario);
    }

    public function test_muestra_solo_los_datos_de_la_cuenta_de_la_sesion(): void
    {
        $this->crearUsuario('otra', ['email' => 'otra@example.com']);

        $this->get(route('mi-perfil', ['id' => 2]))->assertOk()->assertInertia(fn ($pagina) => $pagina
            ->component('MiPerfil')
            ->where('perfil', [
                'usuario' => 'ana', 'nombreCompleto' => 'Nombre Apellido',
                'email' => 'ana@example.com', 'telefono' => '5555-0000',
            ]));
    }

    public function test_actualiza_correo_y_telefono_y_audita(): void
    {
        $this->put(route('mi-perfil.actualizar'), ['email' => 'ana.nueva@example.com', 'telefono' => ''])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Datos actualizados.');

        $fila = DB::table('adm_usuarios')->find($this->usuario->id);
        $this->assertSame(['ana.nueva@example.com', null], [$fila->email, $fila->telefono]);
        $auditoria = DB::table('adm_auditoria')->sole();
        $this->assertSame(['adm_usuarios', (string) $this->usuario->id, 'EDITAR', $this->usuario->id],
            [$auditoria->entidad, $auditoria->registro, $auditoria->accion, (int) $auditoria->usuario_id]);
        $this->assertSame(['email' => ['ana@example.com', 'ana.nueva@example.com'], 'telefono' => ['5555-0000', null]],
            json_decode($auditoria->cambios, true));

        $this->put(route('mi-perfil.actualizar'), ['email' => 'ana.nueva@example.com'])->assertSessionHas('status', 'Sin cambios.');
        $this->assertSame(1, DB::table('adm_auditoria')->count());
    }

    public function test_no_permite_cambiar_otros_datos_ni_otra_cuenta(): void
    {
        $otra = $this->crearUsuario('otra');

        $this->put(route('mi-perfil.actualizar'), [
            'email' => 'ana@example.com', 'nombre' => 'Cambiado', 'usuario' => 'hackeo', 'activo' => false, 'id' => $otra->id,
        ])->assertSessionHasNoErrors();

        $fila = DB::table('adm_usuarios')->find($this->usuario->id);
        $this->assertSame(['ana', 'Nombre', 1], [$fila->usuario, $fila->nombre, (int) $fila->activo]);
        $this->assertSame('Nombre', DB::table('adm_usuarios')->where('id', $otra->id)->value('nombre'));
    }

    public function test_valida_el_correo_y_su_unicidad(): void
    {
        $this->crearUsuario('otra', ['email' => 'ocupado@example.com']);

        $this->put(route('mi-perfil.actualizar'), ['email' => 'no-es-correo'])->assertSessionHasErrors('email');
        $this->put(route('mi-perfil.actualizar'), ['email' => 'ocupado@example.com'])
            ->assertSessionHasErrors(['email' => 'El correo ya está registrado en otra cuenta.']);
        $this->assertSame('ana@example.com', DB::table('adm_usuarios')->where('id', $this->usuario->id)->value('email'));
    }

    public function test_cambia_la_contrasena_propia_con_la_actual_y_la_politica(): void
    {
        $datos = ['contrasena_actual' => self::CLAVE, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA];

        $this->put(route('mi-perfil.contrasena'), [...$datos, 'contrasena_actual' => 'Incorrecta-2026-x'])
            ->assertSessionHasErrors(['contrasena_actual' => 'La contraseña actual no es correcta.']);
        $this->put(route('mi-perfil.contrasena'), [...$datos, 'password' => 'debil', 'password_confirmation' => 'debil'])
            ->assertSessionHasErrors('password');
        $this->assertSame(0, DB::table('adm_auditoria')->count());

        $this->put(route('mi-perfil.contrasena'), $datos)->assertSessionHasNoErrors()->assertSessionHas('status', 'Contraseña actualizada.');
        $this->assertTrue(Hash::check(self::NUEVA, (string) DB::table('adm_usuarios')->where('id', $this->usuario->id)->value('password')));
        $this->assertStringNotContainsString(self::NUEVA, (string) DB::table('adm_auditoria')->value('cambios'));
    }

    public function test_los_mensajes_de_validacion_estan_en_espanol(): void
    {
        $this->put(route('mi-perfil.contrasena'), [])
            ->assertSessionHasErrors(['contrasena_actual' => 'Indique la contraseña actual.', 'password' => 'Indique la contraseña.']);
    }

    public function test_una_cuenta_desactivada_pierde_la_sesion(): void
    {
        DB::table('adm_usuarios')->where('id', $this->usuario->id)->update(['activo' => false]);

        $this->get(route('mi-perfil'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_produccion_en_solo_lectura_consulta_pero_no_escribe(): void
    {
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', false);

        $this->get(route('mi-perfil'))->assertOk();
        $this->put(route('mi-perfil.actualizar'), ['email' => 'otro@example.com'])
            ->assertForbidden()->assertSee(DestinoSql::MENSAJE_SOLO_LECTURA);
        $this->assertSame('ana@example.com', DB::table('adm_usuarios')->where('id', $this->usuario->id)->value('email'));
    }
}
