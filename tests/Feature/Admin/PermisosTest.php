<?php

namespace Tests\Feature\Admin;

use App\Entorno\DestinoSql;
use App\Models\Usuario;
use App\Services\Admin\Relaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/** Administración de Permisos (administracion.accesos.permisos). */
class PermisosTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private Usuario $admin;

    private int $grupoAdmin;

    private int $soporte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('admin');
        $this->grupoAdmin = $this->crearGrupo('Administradores', administrador: true);
        $this->agregarAGrupo($this->admin, $this->grupoAdmin);
        $this->soporte = $this->crearGrupo('Soporte');
        $this->artisan('adm:sincronizar-permisos')->assertSuccessful();
        DB::table('adm_auditoria')->delete();
        $this->actingAs($this->admin);
    }

    private function id(string $permiso): int
    {
        [$codigo, $accion] = explode(':', $permiso);

        return (int) DB::table('adm_permisos as p')
            ->join('adm_funcionalidades as f', 'f.id', '=', 'p.funcionalidad_id')
            ->where('f.codigo', $codigo)->where('p.accion', $accion)->value('p.id');
    }

    /**
     * @param  list<int>  $base
     * @param  list<int>  $permisos
     */
    private function guardar(int $grupo, array $base, array $permisos): TestResponse
    {
        return $this->put(route('admin.permisos.guardar', $grupo), ['base' => $base, 'permisos' => $permisos]);
    }

    /** @return list<int> */
    private function permisosDe(int $grupo): array
    {
        return Relaciones::ordenar(DB::table('adm_grupos_permisos')->where('grupo_id', $grupo)->pluck('permiso_id')->all());
    }

    public function test_muestra_el_catalogo_y_las_asignaciones(): void
    {
        $ver = $this->id('administracion.accesos.membresias:VER');
        $this->asignarPermiso($this->soporte, 'administracion.accesos.membresias:VER');

        $this->get(route('admin.permisos'))->assertOk()->assertInertia(fn ($pagina) => $pagina
            ->component('admin/Permisos')
            ->where('soloLectura', false)
            ->where('areas.0.area', 'administracion')
            ->where('areas.0.modulos.0.funcionalidades.0.codigo', 'administracion.accesos.usuarios')
            ->where('areas.0.modulos.0.funcionalidades.0.soloAdministrador', true)
            ->where('areas.0.modulos.0.funcionalidades.2.acciones.0.permisoId', $ver)
            ->where('grupos.1.nombre', 'Soporte')
            ->where('grupos.1.permisos', [$ver]));
    }

    public function test_solo_los_administradores_acceden(): void
    {
        $comun = $this->crearUsuario('comun');
        $this->agregarAGrupo($comun, $this->soporte);

        $this->actingAs($comun)->get(route('admin.permisos'))->assertForbidden();
        $this->guardar($this->soporte, [], [$this->id('administracion.accesos.membresias:VER')])->assertForbidden();
        $this->assertSame([], $this->permisosDe($this->soporte));
    }

    public function test_asigna_y_retira_con_auditoria_y_concede_el_acceso(): void
    {
        $ver = $this->id('administracion.accesos.membresias:VER');
        $ana = $this->crearUsuario('ana');
        $this->agregarAGrupo($ana, $this->soporte);
        $this->actingAs($ana)->get(route('admin.membresias'))->assertForbidden();

        $this->actingAs($this->admin);
        $this->guardar($this->soporte, [], [$ver])->assertSessionHasNoErrors()->assertSessionHas('status', 'Permisos guardados.');
        $this->actingAs($ana)->get(route('admin.membresias'))->assertOk();

        $this->actingAs($this->admin);
        $this->guardar($this->soporte, [$ver], [$ver])->assertSessionHas('status', 'Sin cambios.');
        $this->guardar($this->soporte, [$ver], [])->assertSessionHasNoErrors();
        $this->assertSame([], $this->permisosDe($this->soporte));

        $auditorias = DB::table('adm_auditoria')->orderBy('id')->get();
        $this->assertSame(['ASIGNAR', 'RETIRAR'], $auditorias->pluck('accion')->all());
        $this->assertSame(['adm_grupos_permisos', "{$this->soporte}:$ver"], [$auditorias[0]->entidad, $auditorias[0]->registro]);
        $this->assertSame(['grupo' => 'Soporte', 'permiso' => 'administracion.accesos.membresias:VER'], json_decode($auditorias[0]->cambios, true));
    }

    public function test_rechaza_permisos_inexistentes_y_solo_administrador(): void
    {
        $this->guardar($this->soporte, [], [99999])
            ->assertSessionHasErrors(['permisos' => 'Uno o más permisos no existen en el catálogo.']);
        $this->guardar($this->soporte, [], [$this->id('administracion.accesos.usuarios:VER')])
            ->assertSessionHasErrors('permisos');
        $this->guardar($this->soporte, [], ['VER'])->assertSessionHasErrors('permisos.0');

        // Un permiso sincronizado que ya no está en el catálogo tampoco se asigna.
        $funcionalidad = DB::table('adm_funcionalidades')->insertGetId([
            'codigo' => 'antiguo.modulo.retirado', 'area' => 'antiguo', 'modulo' => 'modulo', 'nombre' => 'Retirado',
            'activo' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $retirado = DB::table('adm_permisos')->insertGetId(['funcionalidad_id' => $funcionalidad, 'accion' => 'VER']);
        $this->guardar($this->soporte, [], [$retirado])->assertSessionHasErrors('permisos');
        $this->assertSame([], $this->permisosDe($this->soporte));

        // Pero sí se retira si quedó asignado.
        DB::table('adm_grupos_permisos')->insert(['grupo_id' => $this->soporte, 'permiso_id' => $retirado, 'created_at' => now()]);
        $this->guardar($this->soporte, [$retirado], [])->assertSessionHasNoErrors();
        $this->assertSame([], $this->permisosDe($this->soporte));
    }

    public function test_un_grupo_administrador_no_se_edita(): void
    {
        $this->guardar($this->grupoAdmin, [], [$this->id('administracion.accesos.membresias:VER')])
            ->assertSessionHasErrors(['permisos' => 'El grupo administrador tiene acceso universal: sus permisos no se editan.']);
        $this->guardar(99999, [], [])->assertNotFound();
    }

    public function test_detecta_cambios_concurrentes(): void
    {
        $this->asignarPermiso($this->soporte, 'administracion.accesos.membresias:VER');

        $this->guardar($this->soporte, [], [])->assertSessionHasErrors(['conflicto' => Relaciones::CONFLICTO]);
        $this->assertCount(1, $this->permisosDe($this->soporte));
        $this->assertSame(0, DB::table('adm_auditoria')->count());
    }

    public function test_produccion_en_solo_lectura(): void
    {
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', false);

        $this->guardar($this->soporte, [], [$this->id('administracion.accesos.membresias:VER')])
            ->assertForbidden()->assertSee(DestinoSql::MENSAJE_SOLO_LECTURA);
        $this->get(route('admin.permisos'))->assertOk()->assertInertia(fn ($pagina) => $pagina->where('soloLectura', true));
        $this->assertSame([], $this->permisosDe($this->soporte));
    }
}
