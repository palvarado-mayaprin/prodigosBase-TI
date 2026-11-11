<?php

namespace Tests\Sqlsrv;

use App\Entorno\GuardaDdl;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Integración con SQL Server real, sobre una base de pruebas dedicada y vacía.
 * Se omite salvo que se habilite expresamente (docs/ARQUITECTURA_SQL_SERVER.md):
 *
 *   ADM_PRUEBAS_SQLSRV=1
 *   ADM_PRUEBAS_SQLSRV_HOST, _PORT, _BD, _USUARIO, _CLAVE y _SERVIDOR (@@SERVERNAME)
 *
 * Por seguridad, la base debe terminar en "_pruebas". La prueba crea el esquema
 * con las migraciones (pasando por GuardaDdl) y lo elimina al terminar.
 */
#[Group('sqlsrv')]
class EsquemaSqlServerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('ADM_PRUEBAS_SQLSRV') !== '1') {
            $this->markTestSkipped('Integración con SQL Server deshabilitada (ADM_PRUEBAS_SQLSRV=1 para habilitarla).');
        }
        $base = (string) getenv('ADM_PRUEBAS_SQLSRV_BD');
        if (! str_ends_with($base, '_pruebas')) {
            $this->fail('La base de integración debe terminar en "_pruebas".');
        }

        config()->set('database.connections.sqlsrv', [
            'driver' => 'sqlsrv',
            'host' => (string) getenv('ADM_PRUEBAS_SQLSRV_HOST'),
            'port' => (string) (getenv('ADM_PRUEBAS_SQLSRV_PORT') ?: '1433'),
            'database' => $base,
            'username' => (string) getenv('ADM_PRUEBAS_SQLSRV_USUARIO'),
            'password' => (string) getenv('ADM_PRUEBAS_SQLSRV_CLAVE'),
            'charset' => 'utf8', 'prefix' => '', 'prefix_indexes' => true, 'url' => null,
            'encrypt' => getenv('DB_ENCRYPT') ?: null,
            'trust_server_certificate' => getenv('DB_TRUST_SERVER_CERTIFICATE') ?: null,
        ]);
        config()->set('database.default', 'sqlsrv');
        config()->set('entorno.entorno', 'desarrollo');
        config()->set('entorno.destinos.desarrollo', ['servidor' => (string) getenv('ADM_PRUEBAS_SQLSRV_SERVIDOR'), 'base' => $base]);
        DB::purge('sqlsrv');

        $this->assertSame([], Schema::getTableListing(), 'La base de integración debe estar vacía.');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        if (getenv('ADM_PRUEBAS_SQLSRV') === '1' && Schema::hasTable('migrations')) {
            $this->artisan('migrate:reset', ['--force' => true]);
            Schema::drop('migrations');
        }
        parent::tearDown();
    }

    private function usuario(string $usuario, ?string $email = null): int
    {
        return DB::table('adm_usuarios')->insertGetId([
            'usuario' => $usuario, 'nombre' => 'Nombre', 'apellidos' => 'Apellido', 'email' => $email,
            'password' => Hash::make('Clave-Ficticia-123'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param callable(): mixed $accion */
    private function assertRechazado(callable $accion): void
    {
        try {
            $accion();
            $this->fail('Se esperaba que SQL Server rechazara la operación.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_restricciones_propias_de_sql_server(): void
    {
        $this->assertNull(app(GuardaDdl::class)->motivoBloqueo('sqlsrv'));

        // Índice filtrado: varios NULL, ningún email repetido.
        $id = $this->usuario('a.prueba');
        $this->usuario('b.prueba');
        $this->usuario('c.prueba', 'c@example.com');
        $this->assertRechazado(fn () => $this->usuario('d.prueba', 'c@example.com'));

        // Auditoría: JSON válido y fecha por defecto en UTC.
        DB::table('adm_auditoria')->insert(['entidad' => 'adm_usuarios', 'accion' => 'CREAR', 'cambios' => '{"a":[null,1]}']);
        $this->assertRechazado(fn () => DB::table('adm_auditoria')->insert(['entidad' => 'x', 'accion' => 'CREAR', 'cambios' => 'no-json']));
        $this->assertRechazado(fn () => DB::table('adm_auditoria')->insert(['entidad' => 'x', 'accion' => 'BORRAR']));
        $fecha = DB::selectOne('SELECT DATEDIFF(SECOND, MAX(fecha_utc), SYSUTCDATETIME()) AS diferencia FROM adm_auditoria');
        $this->assertLessThan(60, abs((int) $fecha->diferencia));

        // Seguimiento: segundos nunca negativos.
        $sesion = DB::table('adm_sesiones')->insertGetId(['usuario_id' => $id, 'inicio_utc' => now(), 'ultima_actividad_utc' => now()]);
        $this->assertRechazado(fn () => DB::table('adm_actividad')->insert([
            'sesion_id' => $sesion, 'modulo' => 'x', 'ruta' => '/x', 'visita_utc' => now(), 'segundos_activos' => -1,
        ]));

        // Tipos esperados.
        $tipos = collect(DB::select("SELECT COLUMN_NAME AS columna, DATA_TYPE AS tipo FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'adm_auditoria'"))
            ->pluck('tipo', 'columna');
        $this->assertSame('datetime2', $tipos['fecha_utc']);
        $this->assertSame('nvarchar', $tipos['cambios']);
    }
}
