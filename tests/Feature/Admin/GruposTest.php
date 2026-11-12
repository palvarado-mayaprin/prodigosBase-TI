<?php

namespace Tests\Feature\Admin;

use App\Entorno\DestinoSql;
use App\Models\Usuario;
use App\Services\Admin\AdministrarGrupos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/** Administración de Grupos (administracion.accesos.grupos). */
class GruposTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private Usuario $admin;

    private int $grupoAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('admin');
        $this->grupoAdmin = $this->crearGrupo('Administradores', administrador: true);
        $this->agregarAGrupo($this->admin, $this->grupoAdmin);
        $this->artisan('adm:sincronizar-permisos')->assertSuccessful();
        DB::table('adm_auditoria')->delete();
        $this->actingAs($this->admin);
    }

    /** @return list<object> */
    private function auditorias(): array
    {
        return DB::table('adm_auditoria')->orderBy('id')->get()->all();
    }

    public function test_solo_los_administradores_acceden(): void
    {
        $comun = $this->crearUsuario('comun');
        $grupo = $this->crearGrupo('Soporte');
        $this->agregarAGrupo($comun, $grupo);
        $this->asignarPermiso($grupo, 'administracion.accesos.grupos:VER');

        $this->actingAs($comun)->get(route('admin.grupos'))->assertForbidden();
        $this->post(route('admin.grupos.crear'), ['nombre' => 'Nuevo'])->assertForbidden();
        $this->put(route('admin.grupos.estado', $grupo), ['activo' => false])->assertForbidden();
        $this->assertSame(2, DB::table('adm_grupos')->count());
    }

    public function test_lista_grupos_con_integrantes_y_permisos(): void
    {
        $ana = $this->crearUsuario('ana', ['nombre' => 'Ana', 'apellidos' => 'López']);
        $soporte = $this->crearGrupo('Soporte');
        $this->agregarAGrupo($ana, $soporte);
        $this->asignarPermiso($soporte, 'administracion.accesos.membresias:VER');

        $this->get(route('admin.grupos'))->assertOk()->assertInertia(fn ($pagina) => $pagina
            ->component('admin/Grupos')
            ->where('soloLectura', false)
            ->has('grupos', 2)
            ->where('grupos.0.nombre', 'Administradores')
            ->where('grupos.0.esAdministrador', true)
            ->where('grupos.1.nombre', 'Soporte')
            ->where('grupos.1.integrantes.0.usuario', 'ana')
            ->where('grupos.1.integrantes.0.nombreCompleto', 'Ana López')
            ->where('grupos.1.permisos', ['administracion.accesos.membresias:VER']));
    }

    public function test_crea_y_edita_con_auditoria(): void
    {
        $this->post(route('admin.grupos.crear'), ['nombre' => ' Soporte ', 'descripcion' => 'Mesa de ayuda'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Grupo creado.');
        $grupo = DB::table('adm_grupos')->where('nombre', 'Soporte')->first();
        $this->assertSame(['Mesa de ayuda', 0, 1], [$grupo->descripcion, (int) $grupo->es_administrador, (int) $grupo->activo]);

        $this->put(route('admin.grupos.actualizar', $grupo->id), ['nombre' => 'Soporte TI', 'descripcion' => ''])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Grupo actualizado.');
        $this->put(route('admin.grupos.actualizar', $grupo->id), ['nombre' => 'Soporte TI'])->assertSessionHas('status', 'Sin cambios.');
        // El carácter administrador se fija al crear.
        $this->put(route('admin.grupos.actualizar', $grupo->id), ['nombre' => 'Soporte TI', 'es_administrador' => true])
            ->assertSessionHasErrors('es_administrador');

        $this->assertSame(['CREAR', 'EDITAR'], array_column($this->auditorias(), 'accion'));
        $this->assertSame(['nombre' => ['Soporte', 'Soporte TI'], 'descripcion' => ['Mesa de ayuda', null]],
            json_decode($this->auditorias()[1]->cambios, true));
        $this->assertSame(0, (int) DB::table('adm_grupos')->where('id', $grupo->id)->value('es_administrador'));
    }

    public function test_valida_y_exige_nombre_unico(): void
    {
        $this->post(route('admin.grupos.crear'), [])->assertSessionHasErrors('nombre');
        $this->post(route('admin.grupos.crear'), ['nombre' => str_repeat('x', 101)])->assertSessionHasErrors('nombre');
        $this->post(route('admin.grupos.crear'), ['nombre' => 'Administradores'])
            ->assertSessionHasErrors(['nombre' => 'Ya existe un grupo con ese nombre.']);

        $soporte = $this->crearGrupo('Soporte');
        $this->put(route('admin.grupos.actualizar', $soporte), ['nombre' => 'Administradores'])->assertSessionHasErrors('nombre');
        $this->assertSame([], $this->auditorias());
    }

    public function test_activa_y_desactiva_y_un_grupo_inactivo_no_concede_permisos(): void
    {
        $ana = $this->crearUsuario('ana');
        $soporte = $this->crearGrupo('Soporte');
        $this->agregarAGrupo($ana, $soporte);
        $this->asignarPermiso($soporte, 'administracion.accesos.membresias:VER');
        $this->actingAs($ana)->get(route('admin.membresias'))->assertOk();

        $this->actingAs($this->admin)->put(route('admin.grupos.estado', $soporte), ['activo' => false])->assertSessionHasNoErrors();
        $this->actingAs($ana)->get(route('admin.membresias'))->assertForbidden();

        $this->actingAs($this->admin)->put(route('admin.grupos.estado', $soporte), ['activo' => true])->assertSessionHasNoErrors();
        $this->assertSame(['DESHABILITAR', 'HABILITAR'], array_column($this->auditorias(), 'accion'));
        // No hay eliminación física.
        $this->assertSame(2, DB::table('adm_grupos')->count());
    }

    public function test_protege_los_grupos_administradores(): void
    {
        // El grupo que otorga la administración a quien actúa.
        $this->put(route('admin.grupos.estado', $this->grupoAdmin), ['activo' => false])
            ->assertSessionHasErrors(['activo' => 'No puede desactivar el grupo que le otorga la administración.']);

        // Con otro grupo administrador propio sí, mientras queden administradores.
        $segundo = $this->crearGrupo('Administración B', administrador: true);
        $this->agregarAGrupo($this->admin, $segundo);
        $this->put(route('admin.grupos.estado', $this->grupoAdmin), ['activo' => false])->assertSessionHasNoErrors();
        $this->assertSame(0, (int) DB::table('adm_grupos')->where('id', $this->grupoAdmin)->value('activo'));

        // El servicio nunca deja el sistema sin administradores activos.
        try {
            app(AdministrarGrupos::class)->cambiarEstado($segundo, false, actorId: 0);
            $this->fail('Debió impedirse dejar el sistema sin administradores.');
        } catch (ValidationException $error) {
            $this->assertSame('Debe permanecer al menos un administrador activo.', $error->errors()['activo'][0]);
        }
        $this->assertSame(1, (int) DB::table('adm_grupos')->where('id', $segundo)->value('activo'));
        $this->assertSame(['DESHABILITAR'], array_column($this->auditorias(), 'accion'));
    }

    public function test_grupo_inexistente_y_produccion_en_solo_lectura(): void
    {
        $this->put(route('admin.grupos.estado', 99999), ['activo' => false])->assertNotFound();

        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', false);
        $this->post(route('admin.grupos.crear'), ['nombre' => 'Nuevo'])->assertForbidden()->assertSee(DestinoSql::MENSAJE_SOLO_LECTURA);
        $this->get(route('admin.grupos'))->assertOk()->assertInertia(fn ($pagina) => $pagina->where('soloLectura', true));
        $this->assertNull(DB::table('adm_grupos')->where('nombre', 'Nuevo')->first());
    }
}
