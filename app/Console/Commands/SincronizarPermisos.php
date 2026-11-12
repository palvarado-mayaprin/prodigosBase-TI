<?php

namespace App\Console\Commands;

use App\Auditoria\Auditor;
use App\Autorizacion\CatalogoPermisos;
use App\Entorno\DestinoSql;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sincroniza CatalogoPermisos con adm_funcionalidades y adm_permisos.
 *
 * Idempotente y no destructivo: crea y actualiza funcionalidades, añade las
 * acciones que falten y deja inactivas (sin borrar) las funcionalidades que
 * ya no están en el catálogo, conservando sus asignaciones. Nunca asigna
 * permisos a grupos. Escribe solo si DestinoSql lo permite y audita cada cambio.
 */
class SincronizarPermisos extends Command
{
    protected $signature = 'adm:sincronizar-permisos';

    protected $description = 'Sincroniza el catálogo de permisos con la base de datos (idempotente, no destructivo)';

    public function handle(DestinoSql $destino, Auditor $auditor): int
    {
        try {
            $db = $destino->exigirEscritura();
        } catch (HttpException) {
            $this->error('Escritura no permitida: '.$destino->motivoBloqueo());

            return self::FAILURE;
        }

        $catalogo = CatalogoPermisos::funcionalidades();
        $resumen = ['funcionalidades nuevas' => 0, 'funcionalidades actualizadas' => 0, 'acciones nuevas' => 0, 'funcionalidades desactivadas' => 0];

        $db->transaction(function () use ($db, $auditor, $catalogo, &$resumen): void {
            foreach ($catalogo as $codigo => $funcionalidad) {
                $datos = [
                    'area' => $funcionalidad['area'],
                    'modulo' => $funcionalidad['modulo'],
                    'nombre' => $funcionalidad['nombre'],
                    'descripcion' => $funcionalidad['descripcion'],
                    'ruta' => $funcionalidad['ruta'],
                    'solo_administrador' => $funcionalidad['soloAdministrador'],
                    'activo' => $funcionalidad['activo'],
                ];
                $fila = $db->table('adm_funcionalidades')->where('codigo', $codigo)->first();

                if ($fila === null) {
                    $id = (int) $db->table('adm_funcionalidades')->insertGetId(['codigo' => $codigo, ...$datos, 'created_at' => now(), 'updated_at' => now()]);
                    $auditor->registrar($db, 'adm_funcionalidades', $codigo, 'CREAR', array_map(fn ($valor) => [null, $valor], $datos));
                    $resumen['funcionalidades nuevas']++;
                } else {
                    $id = (int) $fila->id;
                    $cambios = [];
                    foreach ($datos as $campo => $valor) {
                        $actual = is_bool($valor) ? (bool) $fila->{$campo} : $fila->{$campo};
                        if ($actual !== $valor) {
                            $cambios[$campo] = [$actual, $valor];
                        }
                    }
                    if ($cambios !== []) {
                        $db->table('adm_funcionalidades')->where('id', $id)->update([...$datos, 'updated_at' => now()]);
                        $auditor->registrar($db, 'adm_funcionalidades', $codigo, 'EDITAR', $cambios);
                        $resumen['funcionalidades actualizadas']++;
                    }
                }

                $existentes = $db->table('adm_permisos')->where('funcionalidad_id', $id)->pluck('accion')->all();
                foreach (array_keys($funcionalidad['acciones']) as $accion) {
                    if (! in_array($accion, $existentes, true)) {
                        $db->table('adm_permisos')->insert(['funcionalidad_id' => $id, 'accion' => $accion]);
                        $auditor->registrar($db, 'adm_permisos', "$codigo:$accion", 'CREAR');
                        $resumen['acciones nuevas']++;
                    }
                }
            }

            // Fuera del catálogo: se desactivan (sin efecto) y conservan sus asignaciones.
            $sobrantes = $db->table('adm_funcionalidades')->whereNotIn('codigo', array_keys($catalogo))
                ->where('activo', true)->pluck('codigo')->all();
            foreach ($sobrantes as $codigo) {
                $db->table('adm_funcionalidades')->where('codigo', $codigo)->update(['activo' => false, 'updated_at' => now()]);
                $auditor->registrar($db, 'adm_funcionalidades', (string) $codigo, 'DESHABILITAR', ['activo' => [true, false]]);
                $resumen['funcionalidades desactivadas']++;
            }
        });

        foreach ($resumen as $concepto => $total) {
            $this->line(ucfirst($concepto).": $total");
        }
        $this->info('Catálogo sincronizado: '.count($catalogo).' funcionalidades.');

        return self::SUCCESS;
    }
}
