# Manual técnico

Arquitectura de la aplicación y guía para extenderla. La instalación está en [MANUAL_INSTALACION_Y_CONFIGURACION.md](MANUAL_INSTALACION_Y_CONFIGURACION.md) y el modelo de datos en [ARQUITECTURA_SQL_SERVER.md](ARQUITECTURA_SQL_SERVER.md).

## 0. Estructura de carpetas

| Carpeta                                           | Contenido                                                                                                            |
| ------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `app/Entorno`                                     | `DestinoSql` (destino y escritura) y `GuardaDdl` (migraciones y comandos destructivos)                               |
| `app/Autorizacion`                                | `CatalogoPermisos` (catálogo único) y `Autorizacion` (cálculo de permisos por petición)                              |
| `app/Auditoria`                                   | `Auditor` (registro transaccional de cambios)                                                                        |
| `app/Services/Admin`                              | Servicios de los módulos administrativos; las únicas clases que escriben en `adm_*`                                  |
| `app/Http`                                        | Controladores delgados y los middleware de la sección 1                                                              |
| `app/Navegacion`, `app/Support`                   | Menú y Dashboard desde el catálogo; fechas y exportaciones                                                           |
| `app/Console/Commands`                            | `adm:entorno`, `adm:sincronizar-permisos` y `adm:crear-administrador`                                                |
| `config/entorno.php`                              | Variables `ADM_*` del control de entornos                                                                            |
| `database/migrations`, `database/sql`             | Esquema (fuente única) y `roles.sql`                                                                                 |
| `routes/web.php`                                  | Todas las rutas, cada una con su permiso                                                                             |
| `resources/js/pages`                              | Pantallas Inertia (`admin/`, `auth/`, `Dashboard`, `MiPerfil`)                                                       |
| `resources/js/layouts`, `resources/js/components` | Distribución con menú lateral, inicio de sesión y componentes; `components/ui` son los componentes base (shadcn-vue) |
| `resources/js/lib`                                | `alertas`, `fechas`, `iconos`, `navegacion`, `politicaContrasena`, `seguimientoUso`, `seleccion` y `textos`          |
| `resources/css/tema`                              | `tokens.css` (únicos colores) y `componentes.css` (clases `adm-*`)                                                   |
| `resources/views`                                 | `app.blade.php` y las páginas de error (`errors/`)                                                                   |
| `lang/es`                                         | Mensajes de validación y autenticación                                                                               |
| `tests`                                           | `Feature` (PHPUnit con SQLite), `Sqlsrv` (SQL Server real) y `js` (lógica del frontend)                              |
| `docs`                                            | Manuales, `sql/preparacion` (instancia local) y `ejemplos` (paleta y `web.config`)                                   |

`resources/js/actions`, `routes` y `wayfinder` los genera Wayfinder al compilar y no se versionan.

## 1. Cadena de una petición web

```
ControlarAcceso (origen, destino SQL, bloqueo de mutaciones)
  → sesión, CSRF, auth (Fortify / Usuario)
  → ValidarSesion (registro en adm_sesiones; rechaza sesiones revocadas)
  → ExigirCambioContrasena
  → AutorizarPermiso::para('codigo:ACCION') | AutorizarAdministrador
  → controlador → servicio → DestinoSql::exigirEscritura() → transacción → Auditor
```

`ControlarAcceso` va al inicio del grupo `web`; `ValidarSesion` y `ExigirCambioContrasena` al final. Se aplican a todas las rutas web sin declararlos en cada una.

## 2. Entorno y escritura

`App\Entorno\DestinoSql` es la única autoridad sobre el destino SQL Server de la conexión predeterminada (`DB_CONNECTION`) y sobre la escritura. `App\Entorno\GuardaDdl` autoriza las migraciones por separado, al iniciar el comando (`CommandStarting`) y en el Migrator, y solo admite comandos destructivos en una base `_pruebas` autorizada con `ADM_PRUEBAS_DESTRUCTIVAS`. `ADM_DESPLIEGUE_DDL` y `ADM_PRUEBAS_DESTRUCTIVAS` solo autorizan como variables del proceso: Laravel copia al proceso lo que lee de `.env`, así que `GuardaDdl::archivoQueDeclara()` rechaza la autorización si `.env`, el archivo de entorno configurado o cualquier `.env.*` de la carpeta (como `.env.<APP_ENV>`) declara la variable, sea cual sea su valor y con o sin `config:cache`; las líneas comentadas no cuentan (`AutorizacionDesdeArchivoTest`). Detalle en el manual de instalación, sección 3.

## 3. Autenticación

- **Modelo:** `App\Models\Usuario` sobre `adm_usuarios`. Contraseña con cast `hashed`; sin `remember_token`.
- **Fortify:** solo inicio y cierre de sesión (`features => []`, `Fortify::ignoreRoutes()`; rutas en `routes/web.php`). Autentica por `usuario` y contraseña (`FortifyServiceProvider::authenticateUsing`):
    - rechaza cuentas inexistentes o inactivas y «recordarme»;
    - compara siempre con un hash (uno ficticio si la cuenta no existe) para no revelar por el tiempo de respuesta si existe;
    - 5 intentos por minuto por usuario e IP; CSRF y regeneración de la sesión los aplican Laravel y Fortify;
    - solo escribe el registro de la sesión (`adm_sesiones`) y el último acceso de la cuenta (`adm_usuarios.ultimo_acceso_utc`, en UTC, el mismo instante que el inicio de la sesión), en una transacción y únicamente si `DestinoSql` lo permite; con la producción en solo lectura el inicio de sesión funciona igual, sin registro (sección 6.6). El último acceso es un dato técnico: no se audita ni cambia `updated_at`, solo se escribe tras credenciales válidas (evento `Login`) y no se repite en las peticiones siguientes.
- **Política de contraseñas** (`Password::defaults()` en `AppServiceProvider`): 12 a 72 caracteres (bcrypt usa los primeros 72 bytes), letras, mayúsculas y minúsculas, números y símbolos, en todos los entornos.
- **Cambio obligatorio:** con `debe_cambiar_password = 1`, `ExigirCambioContrasena` solo permite `contrasena.editar`, `contrasena.actualizar` y `logout`; redirige el resto o responde 403 en JSON; el registro de uso (`seguimiento.*`) responde 204 sin registrar nada. `App\Services\Admin\CambiarContrasena` comprueba la contraseña actual, exige una distinta, actualiza el hash, libera la marca y audita en la misma transacción; en ella revoca también las demás sesiones abiertas de la cuenta con `AdministrarSesiones::revocarDeUsuario()` (auditadas como `REVOCAR`) y conserva la sesión desde la que se cambia (`RegistroSesiones::actual()`). Si el cambio falla no se revoca nada. Después se regenera la sesión. Lo mismo rige para el cambio desde Mi Perfil.

## 4. Autorización

- **Catálogo** (`App\Autorizacion\CatalogoPermisos`): área → módulo → funcionalidad, código `area.modulo.funcionalidad` en camelCase y acciones `VER`, `CREAR`, `EDITAR` y `ELIMINAR`. Se valida al registrar las rutas: un permiso mal escrito impide arrancar. Contiene solo funcionalidades administrativas.
- **Cálculo** (`App\Autorizacion\Autorizacion`):
    - relee en cada petición la cuenta (cierra la sesión si está inactiva) y sus grupos **activos**; la memoria dura solo la petición;
    - un grupo activo con `es_administrador = 1` tiene acceso universal; no hay identificadores de grupo especiales;
    - deniega por defecto; los demás grupos solo obtienen las acciones asignadas en `adm_grupos_permisos` sobre funcionalidades activas;
    - las funcionalidades `soloAdministrador` no se obtienen por asignación;
    - `rutasPermitidas()` alimenta el menú y el Dashboard (solo presentación): omite funcionalidades desactivadas en el catálogo o en `adm_funcionalidades` y hace dos consultas como máximo;
    - `quedariaSinAdministradores()` protege del último administrador activo; se usa dentro de la transacción del cambio y bloquea las membresías administrativas.
- **Middleware:** `AutorizarPermiso::para('codigo:ACCION', …)` (basta uno) y `AutorizarAdministrador`. Un fallo de SQL responde 503.
- **Rutas sin permiso del catálogo:** solo las de acceso, la cuenta propia (Dashboard, Mi Perfil, cambio de contraseña) y el registro de uso. `AutorizacionTest` falla si otra ruta carece de `auth` y de un middleware de autorización; una ruta propia nueva se añade a su lista de forma explícita.
- **Páginas de error:** `resources/views/errors/` (403, 404, 419, 429, 500 y 503) sobre `plantilla.blade.php`, en español y con la distribución del inicio de sesión. La 403 muestra el mensaje explícito de `abort()` (redactado por la aplicación); las demás, un texto fijo sin rutas ni detalles de la excepción. Conservan su código HTTP; las peticiones JSON siguen recibiendo `{ message }`.

### 4.1 Registrar una funcionalidad nueva

Un proyecto derivado añade sus funcionalidades en áreas propias, sin tocar el área `administracion` de la plantilla. Ejemplo verificado en la instalación limpia (Etapa 9) con una funcionalidad ficticia, que no forma parte de la plantilla:

1. **Catálogo** (`CatalogoPermisos::areas()`): área → módulo → funcionalidad, con identificadores en camelCase. El orden del arreglo es el del menú. `soloAdministrador => true` reserva la funcionalidad a los grupos administradores.

    ```php
    'operaciones' => [
        'nombre' => 'Operaciones',
        'modulos' => [
            'inventario' => [
                'nombre' => 'Inventario',
                'funcionalidades' => [
                    'articulos' => [
                        'nombre' => 'Artículos',
                        'descripcion' => 'Consulta y edición de artículos.',
                        'activo' => true,
                        'ruta' => 'operaciones.articulos',   // GET de entrada
                        'icono' => 'LayoutGrid',              // nombre de resources/js/lib/iconos.ts
                        'acciones' => ['VER' => 'Consultar artículos', 'EDITAR' => 'Editar artículos'],
                    ],
                ],
            ],
        ],
    ],
    ```

2. **Rutas** (`routes/web.php`, dentro del grupo `auth`), cada una con su permiso. `AutorizacionTest` falla si una ruta queda sin autorización.

    ```php
    Route::inertia('operaciones/articulos', 'operaciones/Articulos')
        ->middleware(AutorizarPermiso::para('operaciones.inventario.articulos:VER'))
        ->name('operaciones.articulos');
    ```

3. **Pantalla** en `resources/js/pages/operaciones/Articulos.vue`, con migas desde «Inicio» y los patrones de [GUIA_DISENO_UI.md](GUIA_DISENO_UI.md#11-reglas-para-pantallas-nuevas). Un icono que no esté en `iconos.ts` se agrega allí.
4. **Escrituras** en un servicio de `app/Services`, con `DestinoSql::exigirEscritura()`, una transacción y `Auditor` (sección 5).
5. `npm run build`, `php artisan adm:sincronizar-permisos` en cada entorno y asignar las acciones a los grupos desde Administración de Permisos.
6. **Permisos SQL:** si el módulo tiene tablas propias, concederlos a `adm_aplicacion` después de su migración (sección 4.2).

Resultado verificado: el administrador ve la funcionalidad en el menú y el Dashboard y la abre; una cuenta sin el permiso no la ve y recibe 403; la sincronización queda auditada; Seguimiento de Uso registra sus visitas sin más cambios. Las pruebas de la plantilla localizan el área `administracion` por su nombre, así que siguen pasando con áreas añadidas.

### 4.2 Permisos SQL de las tablas nuevas

`database/sql/roles.sql` concede permisos solo sobre las nueve tablas `adm_*` y no se amplía con las tablas de un proyecto derivado. Cada tabla nueva recibe, aparte, únicamente los permisos que usa su servicio:

- **Quién:** el DBA, o una cuenta con `db_owner` o `db_securityadmin`, conectado a la base de la aplicación de cada entorno, como con `roles.sql`. No puede hacerlo la cuenta de la aplicación (no tiene permiso para conceder) ni la de despliegue (`db_ddladmin` crea tablas, pero no concede permisos).
- **Cuándo:** después de la migración que crea la tabla (manual de instalación, sección 5.1), en cada entorno.
- **Qué:** solo las acciones necesarias, tabla por tabla: `SELECT` para consultar; `INSERT` y `UPDATE` si el servicio crea o modifica filas; `DELETE` solo si las borra (por ejemplo, tablas de asignación). Nunca permisos sobre el esquema o la base (`ON SCHEMA::dbo`, `ON DATABASE`), `CONTROL` ni roles fijos (`db_datareader`, `db_datawriter`, `db_owner`) para `adm_aplicacion`.
- **Registro:** el script del módulo se guarda en el proyecto derivado (por ejemplo, `database/sql/permisos-<modulo>.sql`) y se repite en cada entorno; es idempotente.

Ejemplo para una tabla ficticia `inv_articulos` que el módulo consulta, crea y modifica, pero no borra:

```sql
/*
 * Permisos mínimos de adm_aplicacion sobre una tabla de un módulo nuevo.
 *   sqlcmd -S <servidor> -d <base> -E -b -f 65001 -i database\sql\permisos-<modulo>.sql ^
 *          -v BaseEsperada="<base>" Tabla="inv_articulos"
 */
SET NOCOUNT ON;
SET XACT_ABORT ON;
IF DB_NAME() COLLATE Latin1_General_CI_AS <> N'$(BaseEsperada)' COLLATE Latin1_General_CI_AS
    THROW 50005, N'La base conectada (DB_NAME()) no es la indicada en BaseEsperada. No se aplicó ningún cambio.', 1;
IF OBJECT_ID(N'dbo.$(Tabla)', N'U') IS NULL
    THROW 50006, N'La tabla no existe: ejecute antes su migración. No se aplicó ningún cambio.', 1;

BEGIN TRANSACTION;
GRANT SELECT, INSERT, UPDATE ON OBJECT::dbo.[$(Tabla)] TO adm_aplicacion;  -- solo lo que use el módulo
-- GRANT DELETE ON OBJECT::dbo.[$(Tabla)] TO adm_aplicacion;              -- solo si el módulo borra filas
COMMIT TRANSACTION;
PRINT N'Permisos concedidos a adm_aplicacion sobre dbo.$(Tabla).';
```

Verificación de los permisos efectivos con el usuario de base de datos de la aplicación (`adm_base_app` en el manual de instalación). Esperado: 1 en las acciones concedidas y 0 en las demás y en `CREATE TABLE`:

```sql
EXECUTE AS USER = N'adm_base_app';
SELECT p.permiso, HAS_PERMS_BY_NAME(N'dbo.inv_articulos', N'OBJECT', p.permiso) AS concedido
FROM (VALUES (N'SELECT'), (N'INSERT'), (N'UPDATE'), (N'DELETE'), (N'ALTER')) AS p (permiso);
SELECT HAS_PERMS_BY_NAME(NULL, N'DATABASE', N'CREATE TABLE') AS crear_tablas;
REVERT;
-- Concesiones explícitas del rol sobre la tabla:
SELECT permission_name, state_desc FROM sys.database_permissions
WHERE grantee_principal_id = DATABASE_PRINCIPAL_ID(N'adm_aplicacion') AND major_id = OBJECT_ID(N'dbo.inv_articulos');
```

Procedimiento validado en una base temporal local con una tabla ficticia (Fase 4, intervención 4.2): concede exactamente las acciones indicadas, no cambia los permisos de las tablas `adm_*` y se detiene sin cambios si la base o la tabla no corresponden.

## 5. Auditoría

`App\Auditoria\Auditor::registrar($db, $entidad, $registro, $accion, $cambios)`:

- exige una transacción abierta en la conexión del cambio; si la auditoría falla, se revierte todo;
- usuario e IP salen de la sesión y la petición (vacíos desde la consola); la fecha la pone SQL Server en UTC;
- acciones de la lista cerrada (`CREAR`, `EDITAR`, `HABILITAR`, `DESHABILITAR`, `ASIGNAR`, `RETIRAR`, `REVOCAR`);
- enmascara (`********`) los valores de claves que contienen `password`, `contrasena`, `clave`, `token`, `secret` o `secreto`, también anidadas.

Uso en un servicio nuevo (los cambios son pares `[anterior, nuevo]` de los campos modificados):

```php
$db = $this->destino->exigirEscritura();   // 403 si la escritura no está permitida
$db->transaction(function () use ($db, $id, $antes, $despues) {
    $db->table('mi_tabla')->where('id', $id)->update($despues);
    $this->auditor->registrar($db, 'mi_tabla', (string) $id, 'EDITAR', [
        'nombre' => [$antes['nombre'], $despues['nombre']],
    ]);
});
```

Una acción nueva exige ampliar la lista cerrada de `Auditor` y el CHECK de `adm_auditoria.accion` con una migración. Los registros se consultan y exportan en Auditoría de Modificaciones y nunca se modifican ni se borran (`roles.sql` lo deniega).

## 6. Módulos administrativos

### 6.1 Administración de Usuarios

- **Rutas** (`administracion.accesos.usuarios`, `soloAdministrador`): `GET admin/usuarios` (VER), `POST admin/usuarios` (CREAR), `PUT admin/usuarios/{usuario}` y `PUT admin/usuarios/{usuario}/estado` (EDITAR).
- **Servicio** `App\Services\Admin\AdministrarUsuarios`: cada operación en una transacción con `DestinoSql::exigirEscritura()` y `Auditor`.
    - alta con hash y `debe_cambiar_password = 1`; el nombre de usuario (letras, números, `.`, `_`, `-`) no se edita después;
    - edición de nombre, apellidos, correo y teléfono; una contraseña nueva asignada por el administrador vuelve a obligar a cambiarla; vacía conserva la actual;
    - usuario y correo únicos: comprobación legible dentro de la transacción y, ante una carrera, la restricción única se informa como error de validación;
    - las cuentas no se eliminan: `HABILITAR` y `DESHABILITAR`. Nadie deshabilita su propia cuenta y nunca se deja el sistema sin un administrador activo (`Autorizacion::quedariaSinAdministradores`);
    - la auditoría registra solo los campos cambiados y enmascara la contraseña.
- **Pantalla** `admin/Usuarios.vue`: búsqueda y orden locales, formulario de alta y edición, confirmación de cambio de estado y mensajes con `lib/alertas.ts`.

### 6.2 Mi Perfil

- **Rutas** (cualquier cuenta activa, sin permiso del catálogo): `GET mi-perfil`, `PUT mi-perfil` (correo y teléfono) y `PUT mi-perfil/contrasena` (6 intentos por minuto, contador propio).
- La cuenta sale solo de la sesión; se ignoran identificadores y campos no permitidos. El cambio de contraseña reutiliza `CambiarContrasena` y regenera la sesión.
- **Pantalla** `MiPerfil.vue`: datos de solo lectura, contacto editable y cambio de contraseña con los requisitos en vivo (`lib/politicaContrasena.ts`, mismas expresiones que el servidor).

### 6.3 Administración de Grupos

- **Rutas** (`administracion.accesos.grupos`, `soloAdministrador`): `GET admin/grupos` (VER), `POST admin/grupos` (CREAR), `PUT admin/grupos/{grupo}` y `PUT admin/grupos/{grupo}/estado` (EDITAR).
- **Servicio** `App\Services\Admin\AdministrarGrupos`:
    - alta, edición de nombre y descripción, activación y desactivación (`HABILITAR`/`DESHABILITAR`); los grupos no se eliminan;
    - nombre único (comprobación en la transacción y restricción única como garantía final);
    - `es_administrador` se fija al crear y no se edita;
    - un grupo administrador no se desactiva si es el único que da la administración a quien actúa ni si el sistema quedaría sin administradores activos (`quedariaSinAdministradores($db, grupoId: …)`).
- **Pantalla** `admin/Grupos.vue`: búsqueda local, formulario de alta y edición, confirmación de cambio de estado y detalle de consulta con integrantes y permisos.

### 6.4 Usuarios y grupos (membresías)

- **Rutas** (`administracion.accesos.membresias`, asignable): `GET admin/membresias` exige VER; `PUT admin/membresias/{usuario}` exige un grupo administrador (`AutorizarAdministrador`).
- **Servicio** `App\Services\Admin\AdministrarMembresias::sincronizarGruposDeUsuario($actor, $usuario, $base, $grupos)`:
    - concurrencia optimista (`Relaciones::diferencias`): si las membresías actuales ya no coinciden con la `base` que leyó la pantalla, responde el error `conflicto` y no guarda nada; una inserción concurrente duplicada (clave primaria) también se informa como conflicto;
    - a un grupo inactivo no se agrega a nadie; sí se retira;
    - nadie retira su propia capacidad administrativa ni deja el sistema sin administradores activos;
    - cada alta y retiro se audita (`ASIGNAR`/`RETIRAR`, entidad `adm_usuarios_grupos`, registro `usuario:grupo`).
- **Pantalla** `admin/Membresias.vue`: modalidades Usuarios → Grupos (edición con cambios pendientes, confirmación y recarga) y Grupos → Usuarios (consulta), como en PRODIGOS V2. Quien solo tiene VER la abre en modo consulta.

### 6.5 Administración de Permisos

- **Rutas** (`administracion.accesos.permisos`, `soloAdministrador`): `GET admin/permisos` (VER) y `PUT admin/permisos/{grupo}` (EDITAR).
- **Servicio** `App\Services\Admin\AdministrarPermisos::sincronizar($grupo, $base, $permisos)`:
    - solo asigna permisos sincronizados que estén declarados en `CatalogoPermisos`, de funcionalidades activas y no `soloAdministrador`; retirar se permite siempre (limpia asignaciones de funcionalidades retiradas);
    - los permisos de un grupo administrador no se editan (acceso universal);
    - misma concurrencia optimista y auditoría (`adm_grupos_permisos`, registro `grupo:permiso`, valor `codigo:ACCION`).
- **Pantalla** `admin/Permisos.vue`: árbol área → módulo → funcionalidad con búsqueda, contadores, indicadores de cambios pendientes, panel expandible y confirmación con el detalle de los cambios.
- La denegación por defecto no cambia: `Autorizacion` solo concede lo asignado a grupos activos.

### 6.6 Sesiones Activas

- **Registro técnico** (`App\Services\Admin\RegistroSesiones`, sin auditoría: no es una modificación administrativa):
    - la sesión de Laravel sigue en archivos (`SESSION_DRIVER=file`); `adm_sesiones` guarda una fila por inicio de sesión con usuario, IP, agente y fechas UTC;
    - la base nunca recibe el identificador de sesión ni la cookie: es la sesión, en el servidor, la que guarda el id de su fila (`adm_sesion_id`). El vínculo sobrevive a la regeneración de la sesión (inicio de sesión, cambio de contraseña) y no se duplica en peticiones sucesivas;
    - el evento `Login` crea la fila y `Logout` la cierra (`cierre_utc`); `ValidarSesion` relee la fila en cada petición autenticada, actualiza la última actividad como mucho una vez por minuto y rechaza la sesión si se revocó, se cerró, no existe o es de otra cuenta (redirección al inicio de sesión o 401 en JSON);
    - **solo lectura:** sin escritura permitida por `DestinoSql` no se crea ni actualiza ninguna fila. La sesión funciona, pero no aparece en el listado ni se puede revocar; se registra en la primera petición en que la escritura vuelva a estar permitida. No hay excepción al control centralizado;
    - Seguimiento de Uso (`adm_actividad.sesion_id`) usará este mismo id (`RegistroSesiones::actual()`), sin otro identificador.
- **Rutas** (`administracion.sesiones.activas`, `soloAdministrador`): `GET admin/sesiones` (VER), `PUT admin/sesiones/{referencia}/revocar` y `PUT admin/sesiones/usuarios/{usuario}/revocar` (ELIMINAR).
- **Servicio** `App\Services\Admin\AdministrarSesiones`:
    - la interfaz solo recibe una referencia HMAC del registro (clave de la aplicación, 32 caracteres), que se resuelve únicamente contra sesiones abiertas;
    - revocar marca `revocada_utc` y `revocada_por_id`; las filas nunca se eliminan;
    - nunca se revoca la sesión desde la que se actúa; «revocar todas» de la propia cuenta conserva la actual;
    - cambiar la contraseña propia (cambio obligatorio o Mi Perfil) revoca, en la misma transacción, las demás sesiones abiertas de la cuenta, con `revocada_por_id` igual a la propia cuenta (sección 3);
    - cada revocación se audita en la misma transacción (`REVOCAR`, entidad `adm_sesiones`) con la cuenta afectada, sin IP ni agente.
- **Estados:** activa, vencida por inactividad (sin actividad durante `SESSION_LIFETIME`), cerrada y revocada. El listado muestra las abiertas y las de los últimos 7 días (máximo 500).
- **Pantalla** `admin/Sesiones.vue`: filtros locales (usuario, estado, texto), tabla con estado, última actividad, inicio, IP, equipo y referencia corta; revocación individual o de todas las sesiones de una cuenta, con confirmación. Fechas en la hora del navegador (`lib/fechas.ts`).

### 6.7 Auditoría de Modificaciones

- **Rutas** (`administracion.auditoria.modificaciones`, `soloAdministrador`, solo GET): `admin/auditoria` y `admin/auditoria/exportar/{csv|xlsx}`. Funcionan con la escritura bloqueada.
- **Servicio** `App\Services\Admin\ConsultaAuditoria` (solo lectura de `adm_auditoria`, nunca modifica registros):
    - filtros validados en el servidor: fechas (días de la zona configurada convertidos a un rango UTC), usuario, entidad, acción, texto (en usuario, entidad, registro y cambios; los comodines de `LIKE` se escapan), orden (`fecha`, `usuario`, `entidad`, `accion`) y dirección. Un filtro no válido vuelve a la vista sin filtros con el error;
    - paginación de 25 en el servidor; la exportación recorre un cursor, sin cargar el historial en memoria;
    - cambios legibles: un par `[anterior, nuevo]` es un campo modificado; cualquier otro valor es un dato descriptivo. Al leer se vuelve a enmascarar con `Auditor::enmascarar` (claves sensibles a cualquier profundidad) y se ocultan los valores con forma de hash bcrypt o argon.
- **Pantalla** `admin/Auditoria.vue`: filtros, tabla ordenable, paginación, detalle en diálogo y exportaciones con los filtros aplicados.

### 6.8 Seguimiento de Uso

- **Registro** (`App\Services\Admin\SeguimientoUso`, sin auditoría: es un registro técnico):
    - `resources/js/lib/seguimientoUso.ts` envía la ruta de cada pantalla (`POST seguimiento/visitas`) y los segundos activos (`POST seguimiento/actividad`, cada minuto, al navegar y al ocultar la pestaña). Cuenta solo con la pestaña visible, hasta 5 minutos después de la última interacción y en una sola pestaña;
    - el servidor toma la sesión de `RegistroSesiones::actual()` (la fila de `adm_sesiones`; no crea sesiones ni identificadores) y el usuario de la autenticación; `ValidarSesion` ya rechazó una sesión revocada;
    - las páginas comunes (`CatalogoPermisos::PAGINAS_COMUNES`: Inicio y Mi Perfil) se registran para cualquier cuenta autenticada con el código `pagina.<ruta>`, sin permisos;
    - solo se registran rutas GET con nombre que el catálogo asocia a una funcionalidad activa (`ruta`) y que la cuenta puede ver (`Autorizacion::permite(...:VER)`); el resto responde 204 sin registrar. Se guarda el código de la funcionalidad y la ruta sin parámetros de consulta;
    - los segundos se validan (1 a 600 por envío), solo se suman a visitas de la propia sesión y el total de la sesión nunca supera el tiempo real desde su inicio más 5 segundos;
    - **solo lectura:** son escrituras, así que `ControlarAcceso` las rechaza (403) y el navegador las descarta. Mientras la escritura esté bloqueada no se registra uso; tampoco el de las sesiones iniciadas en ese modo, que no tienen fila en `adm_sesiones`. No hay excepción al control centralizado.
- **Reportes** (`administracion.seguimiento.uso`, `soloAdministrador`, solo GET): `admin/seguimiento` y `admin/seguimiento/exportar/{historial|usuarios|modulos|diario}/{csv|xlsx}`.
    - indicadores, por usuario y por módulo con agregados SQL; por día de la zona configurada con un cursor que solo retiene los totales por día; historial paginado (25) en el servidor;
    - filtros validados: fechas, usuario exacto y módulo del catálogo.
- **Pantalla** `admin/Seguimiento.vue`: indicadores, gráfico diario con selector de medida, módulos y usuarios con barras de proporción, historial paginado y exportaciones.

### 6.9 Navegación y Dashboard

- **Servidor:** `App\Navegacion\Navegacion::para()` recorre `CatalogoPermisos::areas()` y conserva solo las rutas de `Autorizacion::rutasPermitidas()`: áreas → módulos → funcionalidades con nombre, descripción, icono y `href`. No hay listas fijas de módulos ni otra lógica de autorización. `HandleInertiaRequests` la comparte como `navegacion` (se calcula al renderizar, una vez por respuesta). Si la consulta de permisos falla, `verificada` es `false`.
- **Menú lateral** (`AppSidebar.vue`, `NavMain.vue`): marca, buscador del menú (`lib/navegacion.ts`, sin distinguir mayúsculas ni tildes), «Inicio» para toda cuenta y un grupo por área con sus módulos como secciones. Se contrae a iconos con ayuda emergente; el elemento activo se marca por ruta. Ocultar una opción no protege nada: cada ruta exige su permiso.
- **Barra superior** (`AppSidebarHeader.vue`, `.adm-topbar`): disparador del menú y migas de pan declaradas por cada página (`Inicio › Página`).
- **Dashboard** (`pages/Dashboard.vue`): bienvenida y una tarjeta por funcionalidad autorizada, agrupadas por área, con enlace directo. Sin módulos, un aviso y el acceso a Mi Perfil. No muestra indicadores ni datos de negocio.
- **Iconos:** `lib/iconos.ts` traduce el `icono` del catálogo a un componente de Lucide; un nombre desconocido usa uno genérico.
- **Páginas comunes:** Inicio y Mi Perfil solo exigen sesión; no son funcionalidades ni generan permisos.

### 6.10 Base compartida

- **Idioma:** `lang/es` (validación y autenticación) y `es` como idioma por defecto.
- **Sistema visual** (detalle y reglas en [GUIA_DISENO_UI.md](GUIA_DISENO_UI.md)):
    - `resources/css/tema/tokens.css` es el único archivo con colores (`--adm-*`); `componentes.css` contiene las medidas de PRODIGOS V2 con prefijo `adm-` y los colores derivados con `color-mix`;
    - las variables de los componentes base (shadcn-vue) en `app.css`, el fondo y el indicador de SweetAlert2, la barra de progreso de Inertia y el fondo inicial de `app.blade.php` derivan de los tokens;
    - paleta única y clara: se retiraron el modo oscuro automático (`HandleAppearance`, `useAppearance`, script de `app.blade.php`) y su paleta gris, que dejaban los componentes base con otros colores que las pantallas `adm-`;
    - el inicio de sesión y el cambio de contraseña usan `layouts/auth/AuthSplitLayout.vue` (distribución de PRODIGOS V2) con marca neutra: inicial y nombre de `APP_NAME` y descripción de `ADM_APP_DESCRIPCION` (`descripcionApp` compartida);
    - `tests/Feature/Visual/PaletaTest.php` recorre todo el frontend: sin colores literales ni clases de color de Tailwind fuera de `tokens.css`, paleta solo con colores, mismos tokens que `docs/ejemplos/paleta-ejemplo.css` y componentes base derivados.
- **Alertas:** `resources/js/lib/alertas.ts` (SweetAlert2 con carga diferida); los colores de los iconos se leen de los tokens.
- **Selección:** `resources/js/lib/seleccion.ts` calcula agregados, retirados y cambios pendientes de membresías y permisos.
- **Fechas:** se guardan en UTC y se presentan y filtran en `app.zona_horaria` (`ADM_ZONA_HORARIA`, UTC por defecto; una zona no válida usa UTC) con `App\Support\Fechas`. La zona se comparte con la interfaz (`zonaHoraria`) y `lib/fechas.ts` la aplica.
- **Exportaciones:** `App\Support\ExportarTabla` genera CSV (UTF-8 con BOM, punto y coma) y XLSX (ZipArchive) sin dependencias, fila a fila desde iterables, y neutraliza las celdas que empiezan por `=`, `+`, `-`, `@`, tabulador o retorno anteponiendo un apóstrofo.

## 7. Comandos

| Comando                    | Función                                                                                                                                                                                     |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `adm:entorno`              | Diagnóstico de solo lectura del destino y la escritura                                                                                                                                      |
| `adm:sincronizar-permisos` | Sincroniza el catálogo; idempotente y no destructivo; audita cada cambio                                                                                                                    |
| `adm:crear-administrador`  | Cuenta administradora, grupo administrador (si falta) y membresía en una transacción; contraseña temporal oculta (o `--leer-contrasena` en instalaciones desatendidas) y cambio obligatorio |

Los comandos que escriben usan `DestinoSql::exigirEscritura()`: en producción requieren `ADM_PRODUCCION_ESCRITURA=true`.

## 8. Pruebas

Se ejecutan con SQLite en memoria y las mismas migraciones (`RefreshDatabase`); los comandos están en el [manual de instalación, sección 7](MANUAL_INSTALACION_Y_CONFIGURACION.md#7-verificaciones-y-pruebas). `tests/Concerns/CreaDatosAdministrativos.php` crea usuarios, grupos y asignaciones ficticios. `tests/js` contiene pruebas de la lógica del frontend (`npm run test:js`) y `tests/Feature/Visual/PaletaTest.php` vigila que no haya colores fuera de los tokens. Las validaciones propias de SQL Server están en el grupo `sqlsrv` y la revisión en navegador se hace con Playwright MCP ([VALIDACION_SQL_SERVER.md](VALIDACION_SQL_SERVER.md)). `tests/Feature/PaginasErrorTest.php` cubre las páginas de error.

Un proyecto derivado conserva estas pruebas: localizan el área `administracion` por su nombre y admiten áreas nuevas. `PaletaTest` admite cualquier paleta válida, incluida una idéntica a `docs/ejemplos/paleta-ejemplo.css`: exige los mismos tokens, solo colores y ningún cambio estructural.

## 9. Dependencias

Solo las necesarias para la plantilla; `tests/Feature/NavegacionTest.php` comprueba que no haya referencias a módulos de otros sistemas.

- **PHP:**
    - producción: `laravel/framework`, `laravel/fortify` (solo inicio y cierre de sesión), `inertiajs/inertia-laravel`, `laravel/wayfinder` (rutas tipadas para el frontend) y `laravel/tinker` (consola de mantenimiento);
    - desarrollo: `phpunit/phpunit`, `mockery/mockery`, `nunomaduro/collision`, `laravel/pao` (salida de las pruebas), `laravel/pint` y `larastan/larastan`.
- **npm:**
    - Vue, Inertia, `reka-ui`, `class-variance-authority`, `clsx` y `tailwind-merge` (componentes base), `@lucide/vue`, `@vueuse/core`, `vue-sonner`, `sweetalert2`, Tailwind y `tw-animate-css`;
    - desarrollo: `vite-plus`, TypeScript, `vue-tsc`, `@types/node` (pruebas `node --test`), los plugins de Vite y `concurrently` (`composer run dev`).
- **Retiradas en la Etapa 7:** `fakerphp/faker` (sin factorías ni usos) y `laravel/pail` (exige la extensión `pcntl`, inexistente en Windows e IIS).
- **Herramientas de diagnóstico:** no deben almacenar ni exponer credenciales. Inertia DevTools (incluido en `inertiajs/inertia-laravel`) graba cada petición en `storage/inertia-devtools` y la sirve en `/_inertia/devtools/entries`; el paquete lo activa solo con `APP_ENV=local` y únicamente oculta campos con nombres exactos en inglés, por lo que guardaba `contrasena_actual` en texto plano. Está deshabilitado en todos los entornos en `config/inertia.php` (`devtools.enabled = false`, sin variable de entorno): no registra peticiones ni declara sus rutas (`tests/Feature/DevToolsInertiaTest.php`). Reactivarlo es una decisión de seguridad que requiere aprobación, y no basta con ampliar la lista de campos ocultos.
- **Componentes base:** `resources/js/components/ui` (shadcn-vue, `components.json`) se conserva completo aunque algunos componentes aún no se usen: es la base reutilizable de los módulos futuros.
- **Archivos públicos:** sin favicon de marca (`<link rel="icon" href="data:,">` en `app.blade.php`); cada implantación puede agregar el suyo en `public/`.

## 10. Retención de registros

Política operativa propuesta para las tres tablas de historial. Los plazos son **configurables**: cada proyecto los ajusta a su normativa y a sus necesidades y los deja aprobados por escrito. La plantilla no borra nada por sí misma ni incluye tareas programadas de eliminación, y la cuenta de la aplicación no puede borrar en ninguna de las tres (`roles.sql` no le concede `DELETE` y en la auditoría además lo deniega).

| Tabla           | Información                                                                                          | Naturaleza                             | Conservación propuesta (configurable)                                                          |
| --------------- | ---------------------------------------------------------------------------------------------------- | -------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `adm_auditoria` | Modificaciones administrativas: quién, qué, cuándo y desde qué IP (contraseñas siempre enmascaradas) | Evidencia de control; no se modifica   | 5 años como mínimo; nunca se borra de forma automática                                         |
| `adm_sesiones`  | Inicio, actividad, cierre y revocación de cada sesión, con IP y agente                               | Registro técnico de seguridad          | 24 meses desde la última actividad, solo sesiones finalizadas; nunca menos que `adm_actividad` |
| `adm_actividad` | Visitas y tiempo activo por pantalla (Seguimiento de Uso)                                            | Registro técnico de uso, para reportes | 24 meses desde la visita                                                                       |

- **Sesiones activas y finalizadas:** una sesión está **activa** si no tiene `cierre_utc` ni `revocada_utc` y su última actividad está dentro de `SESSION_LIFETIME`; **vencida** si no tiene cierre ni revocación pero su última actividad es anterior; **cerrada** o **revocada** si tiene la marca correspondiente. Solo se depuran sesiones vencidas, cerradas o revocadas, nunca activas: borrar la fila de una sesión activa la terminaría sin auditoría (`ValidarSesion` la rechaza).
- **Dependencias:** `adm_actividad.sesion_id` referencia `adm_sesiones`, así que primero se depura la actividad y después las sesiones que ya no tengan actividad. La auditoría de las revocaciones guarda el id de la sesión como texto, sin clave foránea.
- **Responsables:** el responsable funcional del sistema propone y aprueba los plazos; el administrador de la aplicación revisa el volumen y solicita la depuración; el DBA la ejecuta. Depurar `adm_auditoria` requiere además la aprobación de seguridad o de auditoría interna.
- **Frecuencia:** revisión anual de esta política y de los plazos; revisión semestral del volumen de las tres tablas; depuración solo cuando se apruebe.

**Procedimiento de depuración autorizada** (manual, nunca desde la aplicación):

1. Solicitud escrita con la tabla, la fecha de corte y el motivo, aprobada por los responsables indicados.
2. Respaldo completo de la base, verificado antes de empezar. Para la auditoría, además, exportación del periodo afectado (Auditoría de Modificaciones, CSV o Excel) archivada según la política de la organización.
3. Con la cuenta del DBA (no con `adm_aplicacion` ni con la de despliegue), contar las filas que cumplen el criterio aprobado. Por ejemplo, para las sesiones:

    ```sql
    DECLARE @corte datetime2(3) = '<fecha de corte UTC>';
    SELECT COUNT(*) FROM dbo.adm_sesiones s
    WHERE s.ultima_actividad_utc < @corte
      AND (s.cierre_utc IS NOT NULL OR s.revocada_utc IS NOT NULL
           OR s.ultima_actividad_utc < DATEADD(MINUTE, -<SESSION_LIFETIME>, SYSUTCDATETIME()))
      AND NOT EXISTS (SELECT 1 FROM dbo.adm_actividad a WHERE a.sesion_id = s.id);
    ```

4. Borrar con el mismo criterio dentro de una transacción, comprobar que el número de filas coincide con el conteo y confirmar; si no coincide, revertir.
5. Dejar constancia fuera de la aplicación (fecha, tabla, criterio, filas y responsables), porque la auditoría de la aplicación no registra las operaciones del DBA.
