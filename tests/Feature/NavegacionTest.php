<?php

namespace Tests\Feature;

use App\Autorizacion\CatalogoPermisos;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/** Navegación dinámica (menú lateral) y Dashboard según los permisos. */
class NavegacionTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private Usuario $admin;

    private Usuario $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('admin');
        $this->agregarAGrupo($this->admin, $this->crearGrupo('Administradores', administrador: true));
        $this->ana = $this->crearUsuario('ana');
        $this->artisan('adm:sincronizar-permisos')->assertSuccessful();
    }

    /** @return list<string> Hrefs de la navegación en el Dashboard. */
    private function enlaces(Usuario $usuario): array
    {
        $areas = $this->actingAs($usuario)->get(route('dashboard'))->assertOk()
            ->viewData('page')['props']['navegacion']['areas'];

        return collect($areas)->flatMap(fn ($a) => collect($a['modulos'])->flatMap(fn ($m) => array_column($m['funcionalidades'], 'href')))->all();
    }

    public function test_un_administrador_ve_todo_el_catalogo_agrupado_y_cada_enlace_abre(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertInertia(fn ($p) => $p
            ->component('Dashboard')
            ->where('navegacion.verificada', true)
            ->where('navegacion.areas.0.area', 'administracion')
            ->where('navegacion.areas.0.nombre', 'Administración')
            ->where('navegacion.areas.0.modulos.0.nombre', 'Accesos')
            ->has('navegacion.areas.0.modulos', 4)
            ->where('navegacion.areas.0.modulos.0.funcionalidades.0', [
                'codigo' => 'administracion.accesos.usuarios', 'nombre' => 'Usuarios',
                'descripcion' => 'Alta, edición y habilitación de cuentas.', 'icono' => 'UserCog',
                'ruta' => 'admin.usuarios', 'href' => '/admin/usuarios',
            ]));

        $enlaces = $this->enlaces($this->admin);
        $this->assertSame(['/admin/usuarios', '/admin/grupos', '/admin/membresias', '/admin/permisos',
            '/admin/sesiones', '/admin/auditoria', '/admin/seguimiento'], $enlaces);
        foreach ($enlaces as $enlace) {
            $this->get($enlace)->assertOk();
        }
    }

    public function test_permisos_parciales_muestran_solo_lo_autorizado(): void
    {
        $grupo = $this->crearGrupo('Consulta');
        $this->agregarAGrupo($this->ana, $grupo);
        $this->asignarPermiso($grupo, 'administracion.accesos.membresias:VER');
        // Una funcionalidad soloAdministrador asignada no se muestra ni abre.
        $this->asignarPermiso($grupo, 'administracion.accesos.usuarios:VER');

        $this->assertSame(['/admin/membresias'], $this->enlaces($this->ana));
        $this->get('/admin/membresias')->assertOk();
        foreach (['/admin/usuarios', '/admin/grupos', '/admin/permisos', '/admin/sesiones', '/admin/auditoria', '/admin/seguimiento'] as $ruta) {
            $this->get($ruta)->assertForbidden();
        }
    }

    public function test_grupos_inactivos_y_funcionalidades_desactivadas_no_se_muestran(): void
    {
        $grupo = $this->crearGrupo('Consulta');
        $this->agregarAGrupo($this->ana, $grupo);
        $this->asignarPermiso($grupo, 'administracion.accesos.membresias:VER');

        DB::table('adm_grupos')->where('id', $grupo)->update(['activo' => false]);
        $this->assertSame([], $this->enlaces($this->ana));

        DB::table('adm_grupos')->where('id', $grupo)->update(['activo' => true]);
        DB::table('adm_funcionalidades')->where('codigo', 'administracion.accesos.membresias')->update(['activo' => false]);
        $this->assertSame([], $this->enlaces($this->ana));
        $this->assertNotContains('/admin/membresias', $this->enlaces($this->admin));
    }

    public function test_dashboard_sin_modulos_y_sin_sesion(): void
    {
        $this->actingAs($this->ana)->get(route('dashboard'))->assertOk()->assertInertia(fn ($p) => $p
            ->component('Dashboard')
            ->where('navegacion', ['areas' => [], 'verificada' => true]));
        $this->get(route('mi-perfil'))->assertOk();
        $this->get('/admin/usuarios')->assertForbidden();

        $this->post(route('logout'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertInertia(fn ($p) => $p->where('navegacion.areas', []));
    }

    public function test_la_navegacion_no_agrega_consultas_por_elemento(): void
    {
        $grupo = $this->crearGrupo('Consulta');
        $this->agregarAGrupo($this->ana, $grupo);
        $this->asignarPermiso($grupo, 'administracion.accesos.membresias:VER');
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('dashboard'))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->actingAs($this->ana)->get(route('dashboard'));
        $uno = $contar();
        foreach (['administracion.accesos.grupos', 'administracion.accesos.permisos'] as $codigo) {
            DB::table('adm_funcionalidades')->where('codigo', $codigo)->update(['solo_administrador' => false]);
        }
        $otro = $this->crearGrupo('Consulta B');
        $this->agregarAGrupo($this->ana, $otro);
        $this->asignarPermiso($otro, 'administracion.accesos.membresias:VER');

        $this->assertSame($uno, $contar());
    }

    public function test_cada_funcionalidad_del_catalogo_tiene_ruta_get_existente_e_icono(): void
    {
        foreach (CatalogoPermisos::funcionalidades() as $codigo => $funcionalidad) {
            $this->assertNotNull($funcionalidad['ruta'], $codigo);
            $this->assertTrue(Route::has($funcionalidad['ruta']), $codigo);
            $this->assertContains('GET', Route::getRoutes()->getByName($funcionalidad['ruta'])->methods(), $codigo);
            $this->assertNotNull($funcionalidad['icono'], $codigo);
        }
        foreach (array_keys(CatalogoPermisos::PAGINAS_COMUNES) as $ruta) {
            $this->assertTrue(Route::has($ruta), $ruta);
        }
    }

    public function test_menu_y_paginas_sin_listas_fijas_y_con_migas(): void
    {
        $sidebar = (string) file_get_contents(resource_path('js/components/AppSidebar.vue'));
        $this->assertStringContainsString('page.props.navegacion', $sidebar);
        $this->assertStringNotContainsString('@/routes/admin', $sidebar);
        $this->assertStringContainsString('page.props.navegacion', (string) file_get_contents(resource_path('js/pages/Dashboard.vue')));

        $paginas = [...glob(resource_path('js/pages/admin/*.vue')), resource_path('js/pages/Dashboard.vue'), resource_path('js/pages/MiPerfil.vue')];
        $this->assertCount(9, $paginas);
        foreach ($paginas as $pagina) {
            $contenido = (string) file_get_contents($pagina);
            $this->assertMatchesRegularExpression("/breadcrumbs: \\[\\s*\\{\\s*title: 'Inicio',\\s*href: dashboard\\(\\)/", $contenido, basename($pagina));
        }
    }

    public function test_sin_referencias_a_modulos_de_negocio_de_prodigos(): void
    {
        $prohibido = '/costos|control[-_ ]?tiempos|prensas|entregasDiarias|planificaci|marcaje|optimus|\bpv2[-_]|PV2_/i';
        $archivos = [];
        foreach (['app', 'routes', 'config', 'database', 'resources/js', 'resources/css'] as $carpeta) {
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($carpeta), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterador as $archivo) {
                $ruta = str_replace('\\', '/', $archivo->getPathname());
                // Código generado por Wayfinder: refleja las rutas de este proyecto.
                if (! preg_match('#/resources/js/(actions|routes|wayfinder)/#', $ruta)) {
                    $archivos[] = $ruta;
                }
            }
        }

        $hallazgos = array_values(array_filter($archivos, fn ($ruta) => preg_match($prohibido, (string) file_get_contents($ruta)) === 1));
        $this->assertSame([], $hallazgos);
    }
}
