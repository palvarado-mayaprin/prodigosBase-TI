<?php

namespace Tests\Feature\Entorno;

use Tests\TestCase;

/**
 * Archivos de despliegue que no se pueden ejecutar sin SQL Server real:
 * se comprueba su contenido. La ejecución de roles.sql queda para la
 * validación en SQL Server (docs/VALIDACION_SQL_SERVER.md).
 */
class DespliegueTest extends TestCase
{
    public function test_roles_sql_comprueba_la_base_esperada_antes_de_cualquier_cambio(): void
    {
        $sql = (string) file_get_contents(base_path('database/sql/roles.sql'));
        $codigo = (string) preg_replace('~/\*.*?\*/~s', '', $sql); // sin comentarios

        $this->assertStringContainsString("DECLARE @esperada sysname = N'$(BaseEsperada)';", $codigo);
        $this->assertMatchesRegularExpression("~IF @esperada = N''\s+THROW~", $codigo, 'Rechaza el parámetro vacío');
        $this->assertMatchesRegularExpression('~IF @base COLLATE \w+ <> @esperada COLLATE \w+\s+THROW~', $codigo, 'Rechaza otra base');

        // Ningún cambio antes de esas comprobaciones, y ningún RETURN que deje seguir el script.
        $ultimaComprobacion = strrpos($codigo, 'THROW 50005');
        $this->assertNotFalse($ultimaComprobacion);
        foreach (['BEGIN TRANSACTION', 'CREATE ROLE', 'ALTER ROLE', 'GRANT ', 'DENY '] as $cambio) {
            $posicion = strpos($codigo, $cambio);
            $this->assertNotFalse($posicion, $cambio);
            $this->assertGreaterThan($ultimaComprobacion, $posicion, "$cambio antes de comprobar la base");
        }
        $this->assertStringNotContainsString('RETURN', $codigo);
        $this->assertStringContainsString('SET XACT_ABORT ON;', $codigo);
    }

    public function test_los_ejemplos_de_sqlcmd_pasan_la_base_esperada_y_detienen_ante_errores(): void
    {
        $archivos = ['database/sql/roles.sql', 'docs/MANUAL_INSTALACION_Y_CONFIGURACION.md', 'docs/VALIDACION_SQL_SERVER.md'];
        $ejemplos = 0;
        foreach ($archivos as $archivo) {
            $texto = (string) file_get_contents(base_path($archivo));
            preg_match_all('~sqlcmd [^\n]*-i database\\\\sql\\\\roles\.sql[^\n]*\n[^\n]*~', $texto, $coincidencias);
            foreach ($coincidencias[0] as $ejemplo) {
                $ejemplos++;
                $this->assertStringContainsString(' -b ', $ejemplo, "$archivo: $ejemplo");
                $this->assertMatchesRegularExpression('~-d (\S+) .*BaseEsperada="\1"|-d <base> .*BaseEsperada="<base>"~s', $ejemplo, "$archivo: $ejemplo");
            }
        }
        $this->assertGreaterThanOrEqual(4, $ejemplos);
    }

    public function test_la_version_de_php_es_coherente(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
        $ci = (string) file_get_contents(base_path('.github/workflows/tests.yml'));

        $this->assertSame('^8.4.1', $composer['require']['php']);
        $this->assertSame('^8.4.1', $lock['platform']['php'], 'composer.lock desactualizado: composer update --lock');
        $this->assertStringContainsString("php-version: '8.4'", $ci);
        $this->assertGreaterThanOrEqual(80401, PHP_VERSION_ID);

        // Ningún paquete del lock exige un mínimo superior a PHP 8.4.1.
        foreach ([...$lock['packages'], ...$lock['packages-dev']] as $paquete) {
            $this->assertDoesNotMatchRegularExpression('~>=\s*8\.(4\.([2-9]|\d\d)|[5-9])~', (string) ($paquete['require']['php'] ?? ''), $paquete['name']);
        }
    }
}
