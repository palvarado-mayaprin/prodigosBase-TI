<?php

namespace Tests\Feature\Autorizacion;

use App\Autorizacion\Autorizacion;
use App\Autorizacion\CatalogoPermisos;
use App\Http\Middleware\AutorizarAdministrador;
use App\Http\Middleware\AutorizarPermiso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/**
 * Autorización por grupos activos (adm_*): denegación por defecto, grupos
 * administradores por es_administrador y relectura de la cuenta por petición.
 */
class AutorizacionTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private const MEMBRESIAS = 'administracion.accesos.membresias:VER';

    private const USUARIOS = 'administracion.accesos.usuarios:VER';

    protected function setUp(): void
    {
        parent::setUp();
        // Rutas ficticias con los nombres del catálogo (las reales llegan en la Etapa 5).
        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('/_prueba/membresias', fn () => 'membresias')
                ->middleware(AutorizarPermiso::para(self::MEMBRESIAS))->name('admin.membresias');
            Route::get('/_prueba/usuarios', fn () => 'usuarios')
                ->middleware(AutorizarPermiso::para(self::USUARIOS))->name('admin.usuarios');
            Route::get('/_prueba/administrador', fn () => 'administrador')
                ->middleware(AutorizarAdministrador::class);
        });
        Route::getRoutes()->refreshNameLookups();
        $this->artisan('adm:sincronizar-permisos')->assertSuccessful();
    }

    public function test_deniega_por_defecto(): void
    {
        $this->actingAs($this->crearUsuario());

        $this->get('/_prueba/membresias')->assertForbidden();
        $this->get('/_prueba/usuarios')->assertForbidden();
        $this->get('/_prueba/administrador')->assertForbidden();
    }

    public function test_la_comprobacion_de_estado_up_esta_deshabilitada(): void
    {
        // Sin consumidores: no se declara (bootstrap/app.php) y responde 404.
        $this->assertNull(collect(Route::getRoutes())->first(fn ($ruta) => $ruta->uri() === 'up'));
        $this->get('/up')->assertNotFound();
    }

    public function test_toda_ruta_de_la_aplicacion_exige_un_permiso_o_esta_en_la_lista_de_rutas_propias(): void
    {
        // Rutas sin permiso del catálogo: acceso, cuenta propia y registro de uso.
        // /up está deshabilitada (bootstrap/app.php): no figura en la lista.
        $propias = [
            'home', 'login', 'login.store', 'logout', 'dashboard', 'contrasena.editar', 'contrasena.actualizar',
            'mi-perfil', 'mi-perfil.actualizar', 'mi-perfil.contrasena', 'seguimiento.visita', 'seguimiento.actividad',
        ];
        $sinPermiso = [];
        foreach (Route::getRoutes() as $ruta) {
            $nombre = (string) $ruta->getName();
            if (str_starts_with($ruta->uri(), '_prueba/') || in_array($nombre, $propias, true)) {
                continue;
            }
            $middleware = implode(' ', $ruta->gatherMiddleware());
            if (! str_contains($middleware, 'auth') || ! str_contains($middleware, 'Autorizar')) {
                $sinPermiso[] = $nombre.' '.$ruta->uri();
            }
        }

        $this->assertSame([], $sinPermiso);
    }

    public function test_la_pagina_403_esta_en_espanol_y_no_expone_detalles(): void
    {
        $this->actingAs($this->crearUsuario());

        $this->get('/_prueba/usuarios')->assertForbidden()
            ->assertSee('Acceso denegado')
            ->assertSee('No tiene permiso para acceder a esta página.')
            ->assertSee('Volver al inicio')
            ->assertDontSee('Forbidden')
            ->assertDontSee(self::USUARIOS);
    }

    public function test_concede_el_permiso_asignado_a_un_grupo_activo(): void
    {
        $usuario = $this->crearUsuario();
        $grupo = $this->crearGrupo('Consulta');
        $this->agregarAGrupo($usuario, $grupo);
        $this->asignarPermiso($grupo, self::MEMBRESIAS);
        $this->actingAs($usuario);

        $this->get('/_prueba/membresias')->assertOk();
        $this->get('/_prueba/usuarios')->assertForbidden();
        $this->get('/_prueba/administrador')->assertForbidden();
    }

    public function test_un_grupo_inactivo_o_una_funcionalidad_inactiva_no_conceden_nada(): void
    {
        $usuario = $this->crearUsuario();
        $grupo = $this->crearGrupo('Consulta');
        $this->agregarAGrupo($usuario, $grupo);
        $this->asignarPermiso($grupo, self::MEMBRESIAS);
        $this->actingAs($usuario);

        DB::table('adm_grupos')->where('id', $grupo)->update(['activo' => false]);
        $this->get('/_prueba/membresias')->assertForbidden();

        DB::table('adm_grupos')->where('id', $grupo)->update(['activo' => true]);
        DB::table('adm_funcionalidades')->where('codigo', 'administracion.accesos.membresias')->update(['activo' => false]);
        $this->get('/_prueba/membresias')->assertForbidden();
    }

    public function test_las_funcionalidades_solo_administrador_no_se_obtienen_por_asignacion(): void
    {
        $usuario = $this->crearUsuario();
        $grupo = $this->crearGrupo('Soporte');
        $this->agregarAGrupo($usuario, $grupo);
        $this->asignarPermiso($grupo, self::USUARIOS);
        $this->actingAs($usuario);

        $this->get('/_prueba/usuarios')->assertForbidden();
    }

    public function test_el_grupo_administrador_tiene_acceso_universal_solo_si_esta_activo(): void
    {
        $usuario = $this->crearUsuario();
        $grupo = $this->crearGrupo('Administradores', administrador: true);
        $this->agregarAGrupo($usuario, $grupo);
        $this->actingAs($usuario);

        $this->get('/_prueba/membresias')->assertOk();
        $this->get('/_prueba/usuarios')->assertOk();
        $this->get('/_prueba/administrador')->assertOk();

        DB::table('adm_grupos')->where('id', $grupo)->update(['activo' => false]);
        $this->get('/_prueba/administrador')->assertForbidden();
    }

    public function test_relee_la_cuenta_en_cada_peticion_y_cierra_la_sesion_si_esta_inactiva(): void
    {
        $usuario = $this->crearUsuario();
        $this->agregarAGrupo($usuario, $this->crearGrupo('Administradores', administrador: true));
        $this->actingAs($usuario);
        $this->get('/_prueba/administrador')->assertOk();

        DB::table('adm_usuarios')->where('id', $usuario->id)->update(['activo' => false]);
        $this->get('/_prueba/administrador')->assertForbidden();
        $this->assertGuest();
    }

    public function test_un_permiso_que_no_esta_en_el_catalogo_impide_declarar_la_ruta(): void
    {
        $this->expectException(LogicException::class);
        AutorizarPermiso::para('administracion.accesos.usuarios:EXPORTAR');
    }

    public function test_rutas_permitidas_para_menu_y_dashboard(): void
    {
        $usuario = $this->crearUsuario();
        $grupo = $this->crearGrupo('Consulta');
        $this->agregarAGrupo($usuario, $grupo);
        $this->asignarPermiso($grupo, self::MEMBRESIAS);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $usuario);

        $this->assertSame(['admin.membresias'], app(Autorizacion::class)->rutasPermitidas($request));

        $this->agregarAGrupo($usuario, $this->crearGrupo('Administradores', administrador: true));
        $request = Request::create('/');
        $request->setUserResolver(fn () => $usuario);
        // El administrador obtiene todo el catálogo; se comprueban las rutas del área base.
        $rutas = app(Autorizacion::class)->rutasPermitidas($request);
        $this->assertCount(count(CatalogoPermisos::funcionalidades()), $rutas);
        $this->assertSame(['admin.usuarios', 'admin.grupos', 'admin.membresias', 'admin.permisos', 'admin.sesiones', 'admin.auditoria', 'admin.seguimiento'],
            array_values(array_filter($rutas, fn ($r) => str_starts_with($r, 'admin.'))));
    }

    public function test_proteccion_del_ultimo_administrador_activo(): void
    {
        $administrador = $this->crearUsuario('admin.uno');
        $grupo = $this->crearGrupo('Administradores', administrador: true);
        $this->agregarAGrupo($administrador, $grupo);
        $autorizacion = app(Autorizacion::class);

        DB::transaction(fn () => $this->assertTrue($autorizacion->quedariaSinAdministradores(DB::connection(), $administrador->id)));

        $otro = $this->crearUsuario('admin.dos');
        $this->agregarAGrupo($otro, $grupo);
        DB::transaction(fn () => $this->assertFalse($autorizacion->quedariaSinAdministradores(DB::connection(), $administrador->id)));

        // Un administrador inactivo no cuenta.
        DB::table('adm_usuarios')->where('id', $otro->id)->update(['activo' => false]);
        DB::transaction(fn () => $this->assertTrue($autorizacion->quedariaSinAdministradores(DB::connection(), $administrador->id)));
    }

    public function test_el_catalogo_declara_codigos_validos_y_el_area_administrativa_base(): void
    {
        $funcionalidades = CatalogoPermisos::funcionalidades();

        foreach (array_keys($funcionalidades) as $codigo) {
            $this->assertMatchesRegularExpression('/^[a-z][a-zA-Z0-9]*\.[a-z][a-zA-Z0-9]*\.[a-z][a-zA-Z0-9]*$/', $codigo);
        }
        // La plantilla aporta exactamente estas funcionalidades administrativas;
        // un proyecto derivado añade las suyas en otras áreas.
        $base = array_keys(array_filter($funcionalidades, fn ($f) => $f['area'] === 'administracion'));
        $this->assertSame([
            'administracion.accesos.usuarios', 'administracion.accesos.grupos', 'administracion.accesos.membresias',
            'administracion.accesos.permisos', 'administracion.sesiones.activas', 'administracion.auditoria.modificaciones',
            'administracion.seguimiento.uso',
        ], $base);
    }
}
