<?php

namespace App\Autorizacion;

use LogicException;

/**
 * Definición única de las funcionalidades autorizables.
 *
 * Toda funcionalidad nueva se declara aquí, dentro de su área y su módulo. Su
 * código es `area.modulo.funcionalidad` (camelCase), sus acciones son las
 * estándar (ACCIONES) y cada ruta protegida declara lo que exige con
 * AutorizarPermiso::para('codigo:ACCION') o AutorizarAdministrador. Después se
 * ejecuta `php artisan adm:sincronizar-permisos`.
 *
 * `soloAdministrador`: la funcionalidad no se puede asignar a grupos; solo la
 * obtienen los grupos con es_administrador = 1.
 */
final class CatalogoPermisos
{
    /** Acciones estándar de todas las funcionalidades. */
    public const ACCIONES = ['VER', 'CREAR', 'EDITAR', 'ELIMINAR'];

    private const IDENTIFICADOR = '/^[a-z][a-zA-Z0-9]*$/';

    /**
     * @return array<string, array{nombre: string, modulos: array<string, array{nombre: string, funcionalidades: array<string, array{nombre: string, descripcion: string|null, activo: bool, ruta: string|null, soloAdministrador?: bool, acciones: array<string, string>}>}>}>
     */
    public static function areas(): array
    {
        return [
            'administracion' => [
                'nombre' => 'Administración',
                'modulos' => [
                    'accesos' => [
                        'nombre' => 'Accesos',
                        'funcionalidades' => [
                            'usuarios' => [
                                'nombre' => 'Usuarios',
                                'descripcion' => 'Alta, edición y habilitación de cuentas.',
                                'activo' => true,
                                'ruta' => 'admin.usuarios',
                                'soloAdministrador' => true,
                                'acciones' => [
                                    'VER' => 'Consultar cuentas',
                                    'CREAR' => 'Crear cuentas',
                                    'EDITAR' => 'Editar, habilitar y deshabilitar cuentas',
                                ],
                            ],
                            'grupos' => [
                                'nombre' => 'Grupos',
                                'descripcion' => 'Alta, edición y habilitación de grupos.',
                                'activo' => true,
                                'ruta' => 'admin.grupos',
                                'soloAdministrador' => true,
                                'acciones' => [
                                    'VER' => 'Consultar grupos',
                                    'CREAR' => 'Crear grupos',
                                    'EDITAR' => 'Editar, habilitar y deshabilitar grupos',
                                ],
                            ],
                            'membresias' => [
                                'nombre' => 'Usuarios y grupos',
                                'descripcion' => 'Consulta de las membresías. Modificarlas queda reservado a los administradores.',
                                'activo' => true,
                                'ruta' => 'admin.membresias',
                                'acciones' => [
                                    'VER' => 'Consultar membresías',
                                ],
                            ],
                            'permisos' => [
                                'nombre' => 'Permisos',
                                'descripcion' => 'Asignación de permisos a los grupos.',
                                'activo' => true,
                                'ruta' => 'admin.permisos',
                                'soloAdministrador' => true,
                                'acciones' => [
                                    'VER' => 'Consultar permisos',
                                    'EDITAR' => 'Asignar y retirar permisos',
                                ],
                            ],
                        ],
                    ],
                    'sesiones' => [
                        'nombre' => 'Sesiones',
                        'funcionalidades' => [
                            'activas' => [
                                'nombre' => 'Sesiones activas',
                                'descripcion' => 'Consulta y revocación de sesiones abiertas.',
                                'activo' => true,
                                'ruta' => 'admin.sesiones',
                                'soloAdministrador' => true,
                                'acciones' => [
                                    'VER' => 'Consultar sesiones',
                                    'ELIMINAR' => 'Revocar sesiones',
                                ],
                            ],
                        ],
                    ],
                    'auditoria' => [
                        'nombre' => 'Auditoría',
                        'funcionalidades' => [
                            'modificaciones' => [
                                'nombre' => 'Auditoría de modificaciones',
                                'descripcion' => 'Consulta y exportación de los cambios administrativos.',
                                'activo' => true,
                                'ruta' => 'admin.auditoria',
                                'soloAdministrador' => true,
                                'acciones' => [
                                    'VER' => 'Consultar y exportar la auditoría',
                                ],
                            ],
                        ],
                    ],
                    'seguimiento' => [
                        'nombre' => 'Seguimiento',
                        'funcionalidades' => [
                            'uso' => [
                                'nombre' => 'Seguimiento de uso',
                                'descripcion' => 'Indicadores y exportación del uso de los módulos.',
                                'activo' => true,
                                'ruta' => 'admin.seguimiento',
                                'soloAdministrador' => true,
                                'acciones' => [
                                    'VER' => 'Consultar y exportar el seguimiento',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Funcionalidades indexadas por código, con su área y módulo padre.
     * Valida el catálogo: un error de declaración impide arrancar las rutas.
     *
     * @return array<string, array{area: string, modulo: string, nombre: string, descripcion: string|null, activo: bool, ruta: string|null, soloAdministrador: bool, acciones: array<string, string>}>
     */
    public static function funcionalidades(): array
    {
        $resultado = [];
        foreach (self::areas() as $area => $datosArea) {
            foreach ($datosArea['modulos'] as $modulo => $datosModulo) {
                foreach ($datosModulo['funcionalidades'] as $funcionalidad => $datos) {
                    $codigo = "$area.$modulo.$funcionalidad";
                    foreach ([$area, $modulo, $funcionalidad] as $identificador) {
                        if (! preg_match(self::IDENTIFICADOR, $identificador)) {
                            throw new LogicException("Identificador de permiso no válido en $codigo: $identificador");
                        }
                    }
                    if ($datos['acciones'] === [] || array_diff(array_keys($datos['acciones']), self::ACCIONES) !== []) {
                        throw new LogicException("La funcionalidad $codigo debe declarar solo acciones estándar.");
                    }
                    if (strlen($codigo) > 150 || strlen($area) > 60 || strlen($modulo) > 60) {
                        throw new LogicException("Código demasiado largo en $codigo.");
                    }

                    $resultado[$codigo] = [
                        'area' => $area,
                        'modulo' => $modulo,
                        'nombre' => $datos['nombre'],
                        'descripcion' => $datos['descripcion'],
                        'activo' => $datos['activo'],
                        'ruta' => $datos['ruta'],
                        'soloAdministrador' => $datos['soloAdministrador'] ?? false,
                        'acciones' => $datos['acciones'],
                    ];
                }
            }
        }

        return $resultado;
    }

    /**
     * Separa y comprueba un permiso `codigo:ACCION`.
     *
     * @return array{0: string, 1: string}
     */
    public static function exigir(string $permiso): array
    {
        [$codigo, $accion] = array_pad(explode(':', $permiso, 2), 2, '');
        if (! isset(self::funcionalidades()[$codigo]['acciones'][$accion])) {
            throw new LogicException("Permiso no declarado en el catálogo: $permiso");
        }

        return [$codigo, $accion];
    }
}
