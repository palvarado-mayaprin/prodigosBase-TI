<?php

namespace App\Navegacion;

use App\Autorizacion\Autorizacion;
use App\Autorizacion\CatalogoPermisos;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Navegación dinámica (menú lateral y Dashboard): el catálogo centralizado
 * filtrado por Autorizacion::rutasPermitidas(). No hay listas fijas de
 * módulos ni otra lógica de autorización: cada ruta sigue exigiendo su
 * permiso en el servidor; esto solo decide qué se muestra.
 *
 * @phpstan-type Funcionalidad array{codigo: string, nombre: string, descripcion: string|null, icono: string|null, ruta: string, href: string}
 * @phpstan-type Modulo array{modulo: string, nombre: string, funcionalidades: list<Funcionalidad>}
 * @phpstan-type Area array{area: string, nombre: string, modulos: list<Modulo>}
 */
class Navegacion
{
    public function __construct(private readonly Autorizacion $autorizacion) {}

    /**
     * Áreas → módulos → funcionalidades que la cuenta puede consultar.
     * `verificada` es false si no se pudo leer la autorización.
     *
     * @return array{areas: list<Area>, verificada: bool}
     */
    public function para(Request $request): array
    {
        if (! $request->user() instanceof Usuario) {
            return ['areas' => [], 'verificada' => true];
        }

        try {
            $permitidas = $this->autorizacion->rutasPermitidas($request);
        } catch (QueryException) {
            return ['areas' => [], 'verificada' => false];
        }

        $areas = [];
        foreach (CatalogoPermisos::areas() as $area => $datosArea) {
            $modulos = [];
            foreach ($datosArea['modulos'] as $modulo => $datosModulo) {
                $funcionalidades = [];
                foreach ($datosModulo['funcionalidades'] as $clave => $datos) {
                    $ruta = $datos['ruta'];
                    if ($ruta === null || ! in_array($ruta, $permitidas, true)) {
                        continue;
                    }
                    $funcionalidades[] = [
                        'codigo' => "$area.$modulo.$clave",
                        'nombre' => $datos['nombre'],
                        'descripcion' => $datos['descripcion'],
                        'icono' => $datos['icono'] ?? null,
                        'ruta' => $ruta,
                        'href' => route($ruta, absolute: false),
                    ];
                }
                if ($funcionalidades !== []) {
                    $modulos[] = ['modulo' => $modulo, 'nombre' => $datosModulo['nombre'], 'funcionalidades' => $funcionalidades];
                }
            }
            if ($modulos !== []) {
                $areas[] = ['area' => $area, 'nombre' => $datosArea['nombre'], 'modulos' => $modulos];
            }
        }

        return ['areas' => $areas, 'verificada' => true];
    }
}
