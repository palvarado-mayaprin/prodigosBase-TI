<?php

namespace Tests\Feature\Admin;

use App\Auditoria\Auditor;
use App\Entorno\DestinoSql;
use App\Models\Usuario;
use App\Services\Admin\AdministrarMembresias;
use App\Services\Admin\Relaciones;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/** Usuarios y grupos (administracion.accesos.membresias). */
class MembresiasTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private Usuario $admin;

    private int $grupoAdmin;

    private Usuario $ana;

    private int $soporte;

    private int $ventas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('admin');
        $this->grupoAdmin = $this->crearGrupo('Administradores', administrador: true);
        $this->agregarAGrupo($this->admin, $this->grupoAdmin);
        $this->ana = $this->crearUsuario('ana');
        $this->soporte = $this->crearGrupo('Soporte');
        $this->ventas = $this->crearGrupo('Ventas');
        $this->artisan('adm:sincronizar-permisos')->assertSuccessful();
        DB::table('adm_auditoria')->delete();
        $this->actingAs($this->admin);
    }

    /**
     * @param  list<int>  $base
     * @param  list<int>  $grupos
     */
    private function guardar(Usuario $usuario, array $base, array $grupos): TestResponse
    {
        return $this->put(route('admin.membresias.guardar', $usuario->id), ['base' => $base, 'grupos' => $grupos]);
    }

    /** @return list<int> */
    private function gruposDe(Usuario $usuario): array
    {
        return Relaciones::ordenar(DB::table('adm_usuarios_grupos')->where('usuario_id', $usuario->id)->pluck('grupo_id')->all());
    }

    /** @return list<object> */
    private function auditorias(): array
    {
        return DB::table('adm_auditoria')->orderBy('id')->get()->all();
    }

    public function test_consulta_con_ver_y_modifica_solo_un_administrador(): void
    {
        $consulta = $this->crearUsuario('consulta');
        $this->agregarAGrupo($consulta, $this->soporte);
        $this->asignarPermiso($this->soporte, 'administracion.accesos.membresias:VER');

        $this->actingAs($consulta)->get(route('admin.membresias'))->assertOk()->assertInertia(fn ($pagina) => $pagina
            ->component('admin/Membresias')
            ->where('soloLectura', true)
            ->has('usuarios', 3)
            ->has('grupos', 3)
            ->where('asignaciones', fn ($a) => count($a) === 2));
        $this->guardar($this->ana, [], [$this->soporte])->assertForbidden();
        $this->assertSame([], $this->gruposDe($this->ana));

        $sinPermiso = $this->crearUsuario('sin.permiso');
        $this->actingAs($sinPermiso)->get(route('admin.membresias'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.membresias'))->assertInertia(fn ($pagina) => $pagina
            ->where('soloLectura', false)->where('actorId', $this->admin->id));
    }

    public function test_asigna_y_retira_con_auditoria(): void
    {
        $this->guardar($this->ana, [], [$this->soporte, $this->ventas])->assertSessionHasNoErrors()->assertSessionHas('status', 'Cambios guardados.');
        $this->assertSame([$this->soporte, $this->ventas], $this->gruposDe($this->ana));

        $this->guardar($this->ana, [$this->soporte, $this->ventas], [$this->ventas])->assertSessionHasNoErrors();
        $this->assertSame([$this->ventas], $this->gruposDe($this->ana));
        $this->guardar($this->ana, [$this->ventas], [$this->ventas])->assertSessionHas('status', 'Sin cambios.');

        $auditorias = $this->auditorias();
        $this->assertSame(['ASIGNAR', 'ASIGNAR', 'RETIRAR'], array_column($auditorias, 'accion'));
        $this->assertSame(['adm_usuarios_grupos', "{$this->ana->id}:{$this->soporte}"], [$auditorias[2]->entidad, $auditorias[2]->registro]);
        $this->assertSame(['usuario' => 'ana', 'grupo' => 'Soporte'], json_decode($auditorias[2]->cambios, true));
    }

    public function test_valida_listas_y_grupos(): void
    {
        $this->put(route('admin.membresias.guardar', $this->ana->id), [])->assertSessionHasErrors(['base', 'grupos']);
        $this->guardar($this->ana, [], [$this->soporte, $this->soporte])->assertSessionHasErrors('grupos.0');
        $this->guardar($this->ana, [], ['x'])->assertSessionHasErrors('grupos.0');
        $this->guardar($this->ana, [], [99999])->assertSessionHasErrors(['grupos' => 'Uno o más grupos no existen.']);
        $this->put(route('admin.membresias.guardar', 99999), ['base' => [], 'grupos' => []])->assertNotFound();
        $this->assertSame([], $this->gruposDe($this->ana));
        $this->assertSame([], $this->auditorias());
    }

    public function test_detecta_cambios_concurrentes_sin_guardar_nada(): void
    {
        // Otra sesión agregó Soporte después de que la pantalla leyera la base.
        $this->agregarAGrupo($this->ana, $this->soporte);

        $this->guardar($this->ana, [], [$this->ventas])->assertSessionHasErrors(['conflicto' => Relaciones::CONFLICTO]);
        $this->assertSame([$this->soporte], $this->gruposDe($this->ana));
        $this->assertSame([], $this->auditorias());
    }

    public function test_no_duplica_membresias(): void
    {
        $this->agregarAGrupo($this->ana, $this->soporte);

        $this->guardar($this->ana, [$this->soporte], [$this->soporte])->assertSessionHas('status', 'Sin cambios.');
        $this->assertSame(1, DB::table('adm_usuarios_grupos')->where('usuario_id', $this->ana->id)->count());
    }

    public function test_grupo_inactivo_solo_admite_retiros(): void
    {
        $inactivo = $this->crearGrupo('Archivo', activo: false);
        $this->guardar($this->ana, [], [$inactivo])->assertSessionHasErrors(['grupos' => 'No se puede agregar a un grupo inactivo.']);

        $this->agregarAGrupo($this->ana, $inactivo);
        $this->guardar($this->ana, [$inactivo], [])->assertSessionHasNoErrors();
        $this->assertSame([], $this->gruposDe($this->ana));
    }

    public function test_nadie_retira_su_propia_capacidad_administrativa(): void
    {
        $this->guardar($this->admin, [$this->grupoAdmin], [$this->soporte])
            ->assertSessionHasErrors(['grupos' => 'No puede retirar su propia capacidad administrativa.']);
        $this->assertSame([$this->grupoAdmin], $this->gruposDe($this->admin));

        // Con otro grupo administrador propio, sí puede dejar uno.
        $segundo = $this->crearGrupo('Administración B', administrador: true);
        $this->guardar($this->admin, [$this->grupoAdmin], [$this->grupoAdmin, $segundo])->assertSessionHasNoErrors();
        $this->guardar($this->admin, [$this->grupoAdmin, $segundo], [$segundo])->assertSessionHasNoErrors();
        $this->assertSame([$segundo], $this->gruposDe($this->admin));
    }

    public function test_protege_al_ultimo_administrador_activo(): void
    {
        $otro = $this->crearUsuario('admin.dos');
        $this->agregarAGrupo($otro, $this->grupoAdmin);
        // Con otro administrador activo se puede retirar a uno.
        $this->guardar($otro, [$this->grupoAdmin], [])->assertSessionHasNoErrors();

        // El servicio nunca deja el sistema sin administradores activos, aunque
        // queden cuentas inactivas en el grupo.
        $inactivo = $this->crearUsuario('admin.inactivo', ['activo' => false]);
        $this->agregarAGrupo($inactivo, $this->grupoAdmin);
        try {
            app(AdministrarMembresias::class)->sincronizarGruposDeUsuario(0, $this->admin->id, [$this->grupoAdmin], []);
            $this->fail('Debió impedirse dejar el sistema sin administradores.');
        } catch (ValidationException $error) {
            $this->assertSame('Debe permanecer al menos un administrador activo.', $error->errors()['grupos'][0]);
        }
        $this->assertSame([$this->grupoAdmin], $this->gruposDe($this->admin));
    }

    public function test_si_falla_la_auditoria_no_se_guarda_nada(): void
    {
        $this->mock(Auditor::class)->shouldReceive('registrar')->andThrow(new RuntimeException('Auditoría no disponible'));

        $this->withoutExceptionHandling();
        try {
            $this->guardar($this->ana, [], [$this->soporte, $this->ventas]);
            $this->fail('Debió propagarse el error de auditoría.');
        } catch (RuntimeException) {
        }
        $this->assertSame([], $this->gruposDe($this->ana));
    }

    public function test_las_relaciones_exigen_registros_existentes_y_no_se_borran_en_cascada(): void
    {
        $this->agregarAGrupo($this->ana, $this->soporte);

        $this->assertLanza(fn () => DB::table('adm_grupos')->where('id', $this->soporte)->delete());
        $this->assertLanza(fn () => DB::table('adm_usuarios')->where('id', $this->ana->id)->delete());
        $this->assertLanza(fn () => DB::table('adm_usuarios_grupos')->insert(['usuario_id' => $this->ana->id, 'grupo_id' => 99999]));
        $this->assertLanza(fn () => DB::table('adm_usuarios_grupos')->insert(['usuario_id' => $this->ana->id, 'grupo_id' => $this->soporte]));
        $this->assertSame([$this->soporte], $this->gruposDe($this->ana));
    }

    private function assertLanza(callable $operacion): void
    {
        try {
            $operacion();
            $this->fail('La base debió rechazar la operación.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_produccion_en_solo_lectura(): void
    {
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', false);

        $this->guardar($this->ana, [], [$this->soporte])->assertForbidden()->assertSee(DestinoSql::MENSAJE_SOLO_LECTURA);
        $this->get(route('admin.membresias'))->assertOk()->assertInertia(fn ($pagina) => $pagina->where('soloLectura', true));
        $this->assertSame([], $this->gruposDe($this->ana));
    }
}
