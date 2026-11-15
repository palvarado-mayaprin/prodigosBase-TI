# Desarrollo

Procedimiento para extender la plantilla. Ejemplo completo y verificado: [`MANUAL_TECNICO.md`, sección 4.1](../MANUAL_TECNICO.md#41-registrar-una-funcionalidad-nueva). Los módulos nuevos se construyen por fases: backend con pruebas, frontend y pruebas funcionales.

## Crear un módulo

1. **Catálogo** (`app/Autorizacion/CatalogoPermisos.php`, método `areas()`): añadir un área propia del proyecto (no modificar el área `administracion`) con módulo y funcionalidad: `nombre`, `descripcion`, `activo`, `ruta` (nombre de la ruta GET de entrada), `icono` (nombre de `resources/js/lib/iconos.ts`), `acciones` y, si procede, `soloAdministrador`.
2. **Tablas**, si las necesita: una migración nueva, con el SQL propio de SQL Server condicionado al driver como en las migraciones existentes. Después, permisos SQL mínimos para `adm_aplicacion` ([`MANUAL_TECNICO.md`, sección 4.2](../MANUAL_TECNICO.md#42-permisos-sql-de-las-tablas-nuevas)). Las migraciones se ejecutan solo con autorización.
3. **Servicio** en `app/Services/<Area>/` para toda escritura (plantilla en la sección «Escrituras»).
4. **Controlador** delgado en `app/Http/Controllers/<Area>/`: valida con `$request->validate()`, consulta y llama al servicio; devuelve `Inertia::render()` o `back()->with('status', …)`. Para indicar a la página que no puede guardar, pasar `soloLectura => ! $destino->escrituraConfigurada()` como en `GruposController`.
5. **Rutas** en `routes/web.php`, dentro del grupo `auth`, cada una con `->middleware(AutorizarPermiso::para('codigo:ACCION'))` y nombre. Una ruta POST que solo consulte se añade a `ControlarAcceso::RUTAS_SIN_ESCRITURA`.
6. **Página** en `resources/js/pages/<area>/<Pantalla>.vue`, siguiendo [`GUIA_DISENO_UI.md`, sección 11](../GUIA_DISENO_UI.md#11-reglas-para-pantallas-nuevas): migas desde «Inicio» con `defineOptions({ layout: { breadcrumbs: [...] } })`, contenedor `.adm-main`, encabezado `.adm-page-title`.
7. **Sincronizar y asignar:** `npm run build`, `php artisan adm:sincronizar-permisos` en cada entorno y asignar las acciones a los grupos en Administración de Permisos.
8. **Pruebas** en `tests/Feature/<Area>/` y documentación en el manual técnico y el historial.

Sin más cambios, la funcionalidad aparece en el menú y el Dashboard para quien tenga `VER`, y Seguimiento de Uso registra sus visitas (lo hace `lib/seguimientoUso.ts` con cualquier ruta GET asociada a una funcionalidad activa del catálogo).

## Escrituras

```php
$db = $this->destino->exigirEscritura();   // 403 si la escritura no está permitida
$db->transaction(function () use ($db, $id, $antes, $despues) {
    $db->table('mi_tabla')->where('id', $id)->update($despues);
    $this->auditor->registrar($db, 'mi_tabla', (string) $id, 'EDITAR', [
        'nombre' => [$antes['nombre'], $despues['nombre']],   // pares [anterior, nuevo]
    ]);
});
```

`DestinoSql` y `Auditor` se inyectan por constructor. Otros módulos no escriben en las tablas `adm_*`: usan los servicios de `app/Services/Admin`.

## Componentes y utilidades comunes

| Necesidad                        | Usar                                                                                                                      |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Confirmaciones, carga y mensajes | `confirmarAccion`, `mostrarCarga`, `cerrarCarga`, `mostrarExito`, `mostrarError`, `mostrarAdvertencia` de `@/lib/alertas` |
| Fechas en la zona configurada    | `fechaHora`, `fechaDia`, `duracion` de `@/lib/fechas` (prop compartida `zonaHoraria`)                                     |
| Contadores                       | `plural` de `@/lib/textos`                                                                                                |
| Selección con cambios pendientes | `diferencia`, `alternar`, `detalleCambios` de `@/lib/seleccion`                                                           |
| Iconos del catálogo              | `icono` de `@/lib/iconos` (añadir allí un icono nuevo de Lucide)                                                          |
| URL de rutas                     | Funciones generadas por Wayfinder en `@/routes/...`                                                                       |
| Exportar CSV o XLSX              | `App\Support\ExportarTabla` (neutraliza fórmulas)                                                                         |
| Fechas en el servidor            | `App\Support\Fechas`                                                                                                      |
| Componentes base                 | `resources/js/components/ui` (shadcn-vue)                                                                                 |
| Datos de prueba                  | `Tests\Concerns\CreaDatosAdministrativos` (`crearUsuario`, `crearGrupo`, `agregarAGrupo`…)                                |

Una página parecida (`pages/admin/Grupos.vue`, `Usuarios.vue`) es el mejor modelo de formularios, tablas, filtros y confirmaciones.

## Qué pruebas ejecutar

Desde la raíz del proyecto:

| Cambio                                | Pruebas                                                                                                                                                                                          |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| PHP (servicios, controladores, rutas) | `composer test` (Pint, Larastan y PHPUnit); durante el trabajo, `php artisan test --filter=<Prueba>`                                                                                             |
| Vue, TypeScript o CSS                 | `npm run check`, `npm run types:check`, `npm run test:js`, `npm run build` y `composer test` (incluye `PaletaTest`)                                                                              |
| Catálogo, permisos o rutas            | `composer test` (`AutorizacionTest`, `SincronizarPermisosTest`, `NavegacionTest`)                                                                                                                |
| Migraciones o SQL de SQL Server       | `composer test` y, con autorización, la suite `Sqlsrv` en una base `_pruebas` dedicada ([`VALIDACION_SQL_SERVER.md`, sección 3](../VALIDACION_SQL_SERVER.md#3-prueba-de-integración-sql-server)) |
| Solo documentación                    | `npm run check` (formato de Markdown) y revisión de enlaces                                                                                                                                      |
| Antes de cualquier commit             | Las cinco: `composer test`, `npm run check`, `npm run types:check`, `npm run test:js`, `npm run build`                                                                                           |

Informar el resultado real de cada ejecución, incluidas las omitidas. La revisión en navegador se describe en [`VALIDACION_SQL_SERVER.md`, sección 5](../VALIDACION_SQL_SERVER.md#5-revisión-en-navegador-con-playwright-mcp).
