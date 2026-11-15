# Arquitectura

Resumen para orientarse en el código. Estructura completa de carpetas: [`MANUAL_TECNICO.md`, sección 0](../MANUAL_TECNICO.md#0-estructura-de-carpetas).

## Recorrido de una petición

Grupo `web` (`bootstrap/app.php`) y rutas en `routes/web.php`:

```
ControlarAcceso (origen, destino SQL, bloqueo de mutaciones)
  → sesión, CSRF, auth (Fortify / App\Models\Usuario)
  → ValidarSesion (fila en adm_sesiones; rechaza sesiones revocadas o cerradas)
  → ExigirCambioContrasena
  → AutorizarPermiso::para('codigo:ACCION') | AutorizarAdministrador
  → controlador → servicio → DestinoSql::exigirEscritura() → transacción → Auditor
  → Inertia::render('carpeta/Pagina', props) → página Vue
```

`HandleInertiaRequests` comparte en todas las páginas: `name`, `descripcionApp`, `auth.user`, `zonaHoraria`, `navegacion` y `sidebarOpen`.

## Backend (`app/`)

| Pieza                                     | Responsabilidad                                                                                                                                           |
| ----------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Http/Controllers` (y `Admin/`)           | Delgados: validan la petición, consultan (lectura con `DB::table`) y llaman a un servicio para escribir                                                   |
| `Services/Admin`                          | Únicas clases que escriben en las tablas `adm_*` (`AdministrarUsuarios`, `AdministrarGrupos`, `CambiarContrasena`, `RegistroSesiones`, `SeguimientoUso`…) |
| `Entorno/DestinoSql`, `Entorno/GuardaDdl` | Destino SQL y escritura; autorización de migraciones ([`04-SEGURIDAD.md`](04-SEGURIDAD.md))                                                               |
| `Autorizacion/CatalogoPermisos`           | Catálogo único de áreas, módulos, funcionalidades y acciones                                                                                              |
| `Autorizacion/Autorizacion`               | Permisos de la cuenta en cada petición, grupo administrador y protección del último administrador                                                         |
| `Auditoria/Auditor`                       | Registro de cambios dentro de la transacción, con enmascarado de secretos                                                                                 |
| `Navegacion/Navegacion`                   | Menú y Dashboard a partir del catálogo y los permisos                                                                                                     |
| `Support/Fechas`, `Support/ExportarTabla` | Zona horaria de presentación; exportaciones CSV y XLSX sin dependencias                                                                                   |
| `Console/Commands`                        | `adm:entorno`, `adm:sincronizar-permisos`, `adm:crear-administrador`                                                                                      |

Los modelos Eloquent son mínimos (`Usuario` para la autenticación); los servicios usan el constructor de consultas sobre la conexión que devuelve `DestinoSql`.

## Frontend (`resources/js`)

- **Páginas Inertia** en `pages/` (`admin/`, `auth/`, `Dashboard.vue`, `MiPerfil.vue`). `app.ts` asigna `AppLayout` (menú lateral y barra superior) o, para `auth/`, `AuthLayout`.
- **Rutas tipadas:** Wayfinder genera `@/routes` y `@/actions` al compilar (`resources/js/routes`, `actions` y `wayfinder` no se versionan). En las páginas se importan funciones como `dashboard()` o `grupos()` en lugar de escribir URL.
- **Bibliotecas propias** en `lib/`: `alertas`, `fechas`, `textos`, `iconos`, `navegacion`, `seleccion`, `seguimientoUso`, `politicaContrasena` ([`05-DESARROLLO.md`](05-DESARROLLO.md#componentes-y-utilidades-comunes)).
- **Estilos:** `resources/css/tema/tokens.css` (colores) y `componentes.css` (clases `adm-*`); componentes base en `components/ui`.

## Base de datos

- Nueve tablas `adm_*` creadas exclusivamente por las migraciones de `database/migrations`; relaciones, índices y restricciones en [`ARQUITECTURA_SQL_SERVER.md`](../ARQUITECTURA_SQL_SERVER.md).
- El SQL propio de SQL Server (CHECK con `ISJSON`, índices filtrados) se condiciona al driver para que las mismas migraciones corran en SQLite durante las pruebas.
- Fechas en UTC (`*_utc`); se presentan en `ADM_ZONA_HORARIA`.
- Permisos SQL mínimos en `database/sql/roles.sql` (roles `adm_aplicacion` y `adm_despliegue`).
- Sesión, caché y cola en archivos (`SESSION_DRIVER=file`): no requieren tablas.

## Pruebas

`tests/Feature` (PHPUnit sobre SQLite en memoria con `RefreshDatabase`), `tests/Sqlsrv` (integración con SQL Server real, omitida salvo habilitación) y `tests/js` (lógica del frontend). Datos ficticios con `tests/Concerns/CreaDatosAdministrativos.php`.
