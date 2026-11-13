<?php

namespace Tests\Feature\Esquema;

use App\Entorno\GuardaDdl;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Esquema administrativo sobre SQLite en memoria, con las mismas migraciones
 * que SQL Server. Las restricciones propias de SQL Server (índice filtrado,
 * ISJSON, CHECK de segundos) se prueban en tests/Sqlsrv.
 */
class EsquemaAdministrativoTest extends TestCase
{
    use RefreshDatabase;

    private const TABLAS = [
        'adm_usuarios', 'adm_grupos', 'adm_usuarios_grupos', 'adm_funcionalidades', 'adm_permisos',
        'adm_grupos_permisos', 'adm_auditoria', 'adm_sesiones', 'adm_actividad',
    ];

    private function usuario(string $usuario = 'usuario.prueba', ?string $email = null): int
    {
        return DB::table('adm_usuarios')->insertGetId([
            'usuario' => $usuario, 'nombre' => 'Nombre', 'apellidos' => 'Apellido', 'email' => $email,
            'password' => Hash::make('Clave-Ficticia-123'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function grupo(string $nombre = 'Grupo de prueba', bool $administrador = false): int
    {
        return DB::table('adm_grupos')->insertGetId([
            'nombre' => $nombre, 'es_administrador' => $administrador, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function funcionalidad(string $codigo = 'administracion.accesos.usuarios'): int
    {
        return DB::table('adm_funcionalidades')->insertGetId([
            'codigo' => $codigo, 'area' => 'administracion', 'modulo' => 'accesos', 'nombre' => 'Usuarios',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param callable(): mixed $accion */
    private function assertRechazado(callable $accion): void
    {
        try {
            $accion();
            $this->fail('Se esperaba que la base de datos rechazara la operación.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_crea_las_nueve_tablas_y_solo_ellas(): void
    {
        foreach (self::TABLAS as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta $tabla");
        }
        $propias = array_filter(Schema::getTableListing(), fn (string $t) => ! str_ends_with($t, 'migrations'));
        $this->assertEqualsCanonicalizing(self::TABLAS, array_values(array_map(fn ($t) => str_replace('main.', '', $t), $propias)));
    }

    public function test_usuarios_con_hash_valores_por_defecto_y_unicidad(): void
    {
        $id = $this->usuario();
        $fila = DB::table('adm_usuarios')->find($id);

        $this->assertNotSame('Clave-Ficticia-123', $fila->password);
        $this->assertTrue(Hash::check('Clave-Ficticia-123', $fila->password));
        $this->assertSame(1, (int) $fila->activo);
        $this->assertSame(0, (int) $fila->debe_cambiar_password);

        $this->assertRechazado(fn () => $this->usuario());
        // Email opcional: varios sin email, pero ninguno repetido.
        $this->usuario('otro.sin.email');
        $this->usuario('con.email', 'persona@example.com');
        $this->assertRechazado(fn () => $this->usuario('duplica.email', 'persona@example.com'));
    }

    public function test_membresias_por_identificador_sin_duplicados_ni_huerfanas(): void
    {
        $usuario = $this->usuario();
        $grupo = $this->grupo();
        DB::table('adm_usuarios_grupos')->insert(['usuario_id' => $usuario, 'grupo_id' => $grupo]);

        $this->assertRechazado(fn () => DB::table('adm_usuarios_grupos')->insert(['usuario_id' => $usuario, 'grupo_id' => $grupo]));
        $this->assertRechazado(fn () => DB::table('adm_usuarios_grupos')->insert(['usuario_id' => 999, 'grupo_id' => $grupo]));
        // Sin borrado en cascada: un usuario con membresías no se puede eliminar.
        $this->assertRechazado(fn () => DB::table('adm_usuarios')->where('id', $usuario)->delete());
        $this->assertRechazado(fn () => $this->grupo());
    }

    public function test_catalogo_permisos_y_asignaciones(): void
    {
        $funcionalidad = $this->funcionalidad();
        $this->assertRechazado(fn () => $this->funcionalidad());

        $permiso = DB::table('adm_permisos')->insertGetId(['funcionalidad_id' => $funcionalidad, 'accion' => 'VER']);
        $this->assertRechazado(fn () => DB::table('adm_permisos')->insert(['funcionalidad_id' => $funcionalidad, 'accion' => 'VER']));
        $this->assertRechazado(fn () => DB::table('adm_permisos')->insert(['funcionalidad_id' => $funcionalidad, 'accion' => 'EXPORTAR']));

        $grupo = $this->grupo();
        DB::table('adm_grupos_permisos')->insert(['grupo_id' => $grupo, 'permiso_id' => $permiso]);
        $this->assertRechazado(fn () => DB::table('adm_grupos_permisos')->insert(['grupo_id' => $grupo, 'permiso_id' => $permiso]));
        $this->assertRechazado(fn () => DB::table('adm_permisos')->where('id', $permiso)->delete());
    }

    public function test_auditoria_con_fecha_por_defecto_y_acciones_cerradas(): void
    {
        $usuario = $this->usuario();
        $id = DB::table('adm_auditoria')->insertGetId([
            'usuario_id' => $usuario, 'usuario' => 'usuario.prueba', 'ip' => '127.0.0.1',
            'entidad' => 'adm_usuarios', 'registro' => (string) $usuario, 'accion' => 'CREAR',
            'cambios' => json_encode(['nombre' => [null, 'Nombre']]),
        ]);
        $fila = DB::table('adm_auditoria')->find($id);

        $this->assertNotNull($fila->fecha_utc);
        $this->assertSame(['nombre' => [null, 'Nombre']], json_decode($fila->cambios, true));
        $this->assertRechazado(fn () => DB::table('adm_auditoria')->insert(['entidad' => 'x', 'accion' => 'BORRAR']));
        // Comando de consola: sin usuario.
        DB::table('adm_auditoria')->insert(['entidad' => 'adm_grupos', 'accion' => 'CREAR']);
        $this->assertSame(2, DB::table('adm_auditoria')->count());
    }

    public function test_sesiones_y_actividad(): void
    {
        $usuario = $this->usuario();
        $ahora = now();
        $sesion = DB::table('adm_sesiones')->insertGetId([
            'usuario_id' => $usuario, 'ip' => '127.0.0.1', 'inicio_utc' => $ahora, 'ultima_actividad_utc' => $ahora,
        ]);
        DB::table('adm_sesiones')->where('id', $sesion)->update(['revocada_utc' => $ahora, 'revocada_por_id' => $usuario]);
        $this->assertRechazado(fn () => DB::table('adm_sesiones')->where('id', $sesion)->update(['revocada_por_id' => 999]));

        DB::table('adm_actividad')->insert([
            'sesion_id' => $sesion, 'modulo' => 'administracion.accesos.usuarios', 'ruta' => '/admin/usuarios', 'visita_utc' => $ahora,
        ]);
        $this->assertSame(0, (int) DB::table('adm_actividad')->value('segundos_activos'));
        $this->assertRechazado(fn () => DB::table('adm_actividad')->insert([
            'sesion_id' => 999, 'modulo' => 'x', 'ruta' => '/x', 'visita_utc' => $ahora,
        ]));
    }

    public function test_las_migraciones_se_revierten_por_completo(): void
    {
        // Revertir es destructivo: solo en una base "_pruebas" autorizada (GuardaDdl).
        config()->set('entorno.destinos.desarrollo.base', 'adm_esquema_pruebas');
        putenv(GuardaDdl::AUTORIZACION_DESTRUCTIVA.'=adm_esquema_pruebas');
        try {
            $this->artisan('migrate:rollback')->assertSuccessful();
        } finally {
            putenv(GuardaDdl::AUTORIZACION_DESTRUCTIVA);
        }
        foreach (self::TABLAS as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), "Quedó $tabla");
        }
        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('adm_actividad'));
    }
}
