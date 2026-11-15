<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\ValidarSesion;
use App\Models\Usuario;
use App\Services\Admin\RegistroSesiones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/**
 * Cambiar la contraseña propia (obligatorio o desde Mi Perfil) revoca las
 * demás sesiones abiertas de la cuenta y conserva la sesión desde la que se
 * cambió. Cada sesión es un inicio de sesión real con su propio registro.
 */
class SesionesTrasCambioContrasenaTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private const NUEVA = 'Nueva-Clave-Segura-26';

    /** @return array<string, array{string, bool}> */
    public static function flujos(): array
    {
        return [
            'cambio obligatorio' => ['contrasena.actualizar', true],
            'Mi Perfil' => ['mi-perfil.contrasena', false],
        ];
    }

    /** Sesión nueva e independiente (otro navegador): sin datos ni cuenta en memoria. */
    private function nuevaSesion(array $datos = []): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withSession($datos);
    }

    private function iniciarSesion(Usuario $usuario): int
    {
        $this->nuevaSesion();
        $this->post(route('login.store'), ['usuario' => $usuario->usuario, 'password' => self::CLAVE])->assertSessionHasNoErrors();

        return (int) session(RegistroSesiones::CLAVE);
    }

    /** Vuelve a una sesión anterior tal como la guarda el servidor. */
    private function retomarSesion(Usuario $usuario, int $registro): void
    {
        $this->nuevaSesion([Auth::guard('web')->getName() => $usuario->id, RegistroSesiones::CLAVE => $registro]);
    }

    #[DataProvider('flujos')]
    public function test_revoca_las_demas_sesiones_y_conserva_la_actual(string $ruta, bool $obligatorio): void
    {
        $usuario = $this->crearUsuario(atributos: ['debe_cambiar_password' => $obligatorio]);
        $otra = $this->crearUsuario('otra.cuenta');
        $deOtraCuenta = $this->iniciarSesion($otra);
        $segunda = $this->iniciarSesion($usuario);
        $actual = $this->iniciarSesion($usuario);
        DB::table('adm_auditoria')->delete();

        $this->put(route($ruta), ['contrasena_actual' => self::CLAVE, 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Contraseña actualizada.');

        $filas = DB::table('adm_sesiones')->get()->keyBy('id');
        $this->assertNull($filas[$actual]->revocada_utc);
        $this->assertNotNull($filas[$segunda]->revocada_utc);
        $this->assertSame($usuario->id, (int) $filas[$segunda]->revocada_por_id);
        $this->assertNull($filas[$deOtraCuenta]->revocada_utc);

        // La sesión actual sigue: misma fila tras regenerar el identificador.
        $this->assertSame($actual, session(RegistroSesiones::CLAVE));
        $this->app['auth']->forgetGuards();
        $this->get(route('dashboard'))->assertOk();

        // La segunda pierde el acceso en su siguiente petición.
        $this->retomarSesion($usuario, $segunda);
        $this->get(route('dashboard'))->assertRedirect(route('login'))->assertSessionHas('status', ValidarSesion::MENSAJE);
        $this->assertGuest();

        // La sesión de otra cuenta no se toca.
        $this->retomarSesion($otra, $deOtraCuenta);
        $this->get(route('dashboard'))->assertOk();

        // Misma transacción: el cambio y la revocación auditados, sin secretos ni identificadores.
        $auditoria = DB::table('adm_auditoria')->orderBy('id')->get();
        $this->assertSame([['adm_usuarios', 'EDITAR'], ['adm_sesiones', 'REVOCAR']],
            $auditoria->map(fn ($fila) => [$fila->entidad, $fila->accion])->all());
        $this->assertSame((string) $segunda, $auditoria[1]->registro);
        $volcado = json_encode($auditoria->all());
        $this->assertStringNotContainsString(self::NUEVA, (string) $volcado);
        $this->assertStringNotContainsString(self::CLAVE, (string) $volcado);
        $this->assertStringNotContainsString(session()->getId(), (string) $volcado);
    }

    #[DataProvider('flujos')]
    public function test_si_el_cambio_falla_no_revoca_ninguna_sesion(string $ruta, bool $obligatorio): void
    {
        $usuario = $this->crearUsuario(atributos: ['debe_cambiar_password' => $obligatorio]);
        $segunda = $this->iniciarSesion($usuario);
        $this->iniciarSesion($usuario);

        $this->put(route($ruta), ['contrasena_actual' => 'Incorrecta-2026-x', 'password' => self::NUEVA, 'password_confirmation' => self::NUEVA])
            ->assertSessionHasErrors('contrasena_actual');

        $this->assertSame(0, DB::table('adm_sesiones')->whereNotNull('revocada_utc')->count());
        $this->retomarSesion($usuario, $segunda);
        $this->get(route('mi-perfil'))->assertStatus($obligatorio ? 302 : 200);
        $this->assertAuthenticated();
    }
}
