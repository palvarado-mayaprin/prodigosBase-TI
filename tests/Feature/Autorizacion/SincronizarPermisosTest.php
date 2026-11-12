<?php

namespace Tests\Feature\Autorizacion;

use App\Autorizacion\CatalogoPermisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaDatosAdministrativos;
use Tests\TestCase;

/** adm:sincronizar-permisos: idempotente, no destructivo y auditado. */
class SincronizarPermisosTest extends TestCase
{
    use CreaDatosAdministrativos, RefreshDatabase;

    private function totalAcciones(): int
    {
        return array_sum(array_map(fn ($f) => count($f['acciones']), CatalogoPermisos::funcionalidades()));
    }

    public function test_crea_el_catalogo_y_lo_audita(): void
    {
        $this->artisan('adm:sincronizar-permisos')
            ->expectsOutputToContain('Funcionalidades nuevas: '.count(CatalogoPermisos::funcionalidades()))
            ->assertSuccessful();

        $this->assertSame(count(CatalogoPermisos::funcionalidades()), DB::table('adm_funcionalidades')->count());
        $this->assertSame($this->totalAcciones(), DB::table('adm_permisos')->count());
        $this->assertSame(0, DB::table('adm_grupos_permisos')->count(), 'Nunca asigna permisos a grupos.');
        $this->assertSame(
            DB::table('adm_funcionalidades')->count() + DB::table('adm_permisos')->count(),
            DB::table('adm_auditoria')->count(),
        );
    }

    public function test_repetir_la_sincronizacion_no_cambia_nada(): void
    {
        $this->artisan('adm:sincronizar-permisos')->assertSuccessful();
        $auditorias = DB::table('adm_auditoria')->count();

        $this->artisan('adm:sincronizar-permisos')
            ->expectsOutputToContain('Funcionalidades nuevas: 0')
            ->expectsOutputToContain('Funcionalidades actualizadas: 0')
            ->expectsOutputToContain('Acciones nuevas: 0')
            ->assertSuccessful();
        $this->assertSame($auditorias, DB::table('adm_auditoria')->count());
    }

    public function test_corrige_cambios_y_desactiva_sin_borrar_lo_que_salio_del_catalogo(): void
    {
        $this->artisan('adm:sincronizar-permisos')->assertSuccessful();
        DB::table('adm_funcionalidades')->where('codigo', 'administracion.accesos.usuarios')->update(['nombre' => 'Otro nombre']);
        $antigua = DB::table('adm_funcionalidades')->insertGetId([
            'codigo' => 'administracion.antigua.retirada', 'area' => 'administracion', 'modulo' => 'antigua',
            'nombre' => 'Retirada', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permiso = DB::table('adm_permisos')->insertGetId(['funcionalidad_id' => $antigua, 'accion' => 'VER']);
        $grupo = $this->crearGrupo('Consulta');
        DB::table('adm_grupos_permisos')->insert(['grupo_id' => $grupo, 'permiso_id' => $permiso]);

        $this->artisan('adm:sincronizar-permisos')
            ->expectsOutputToContain('Funcionalidades actualizadas: 1')
            ->expectsOutputToContain('Funcionalidades desactivadas: 1')
            ->assertSuccessful();

        $this->assertSame('Usuarios', DB::table('adm_funcionalidades')->where('codigo', 'administracion.accesos.usuarios')->value('nombre'));
        $this->assertSame(0, (int) DB::table('adm_funcionalidades')->where('id', $antigua)->value('activo'));
        $this->assertSame(1, DB::table('adm_grupos_permisos')->where('permiso_id', $permiso)->count(), 'Conserva asignaciones.');
    }

    public function test_no_escribe_con_produccion_en_solo_lectura(): void
    {
        config()->set('entorno.entorno', 'produccion');
        config()->set('entorno.escritura_produccion', false);

        $this->artisan('adm:sincronizar-permisos')->expectsOutputToContain('Escritura no permitida')->assertFailed();
        $this->assertSame(0, DB::table('adm_funcionalidades')->count());
    }
}
