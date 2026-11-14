# Manual técnico

Arquitectura de la aplicación. La instalación está en [MANUAL_INSTALACION_Y_CONFIGURACION.md](MANUAL_INSTALACION_Y_CONFIGURACION.md) y el modelo de datos en [ARQUITECTURA_SQL_SERVER.md](ARQUITECTURA_SQL_SERVER.md).

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

`App\Entorno\DestinoSql` es la única autoridad sobre el destino SQL Server de la conexión predeterminada (`DB_CONNECTION`) y sobre la escritura. `App\Entorno\GuardaDdl` autoriza las migraciones por separado, al iniciar el comando (`CommandStarting`) y en el Migrator, y solo admite comandos destructivos en una base `_pruebas` autorizada con `ADM_PRUEBAS_DESTRUCTIVAS`. Detalle en el manual de instalación, sección 3.

## 3. Autenticación

- **Modelo:** `App\Models\Usuario` sobre `adm_usuarios`. Contraseña con cast `hashed`; sin `remember_token`.
- **Fortify:** solo inicio y cierre de sesión (`features => []`, `Fortify::ignoreRoutes()`; rutas en `routes/web.php`). Autentica por `usuario` y contraseña (`FortifyServiceProvider::authenticateUsing`):
    - rechaza cuentas inexistentes o inactivas y «recordarme»;
    - compara siempre con un hash (uno ficticio si la cuenta no existe) para no revelar por el tiempo de respuesta si existe;
    - 5 intentos por minuto por usuario e IP; CSRF y regeneración de la sesión los aplican Laravel y Fortify;
    - solo escribe el registro de la sesión (`adm_sesiones`) y únicamente si `DestinoSql` lo permite; con la producción en solo lectura el inicio de sesión funciona igual, sin registro (sección 6.6).
- **Política de contraseñas** (`Password::defaults()` en `AppServiceProvider`): 12 a 72 caracteres (bcrypt usa los primeros 72 bytes), letras, mayúsculas y minúsculas, números y símbolos, en todos los entornos.
- **Cambio obligatorio:** con `debe_cambiar_password = 1`, `ExigirCambioContrasena` solo permite `contrasena.editar`, `contrasena.actualizar` y `logout`; redirige el resto o responde 403 en JSON; el registro de uso (`seguimiento.*`) responde 204 sin registrar nada. `App\Services\Admin\CambiarContrasena` comprueba la contraseña actual, exige una distinta, actualiza el hash, libera la marca y audita en la misma transacción; después se regenera la sesión.

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
- **Página 403:** `resources/views/errors/403.blade.php`, en español y con la distribución del inicio de sesión. Muestra solo el mensaje explícito de `abort()` (o uno genérico) y mantiene el código 403; las peticiones JSON siguen recibiendo `{ message }`.

### 4.1 Registrar una funcionalidad nueva

1. Declararla en `CatalogoPermisos::areas()` con su `ruta` (GET de entrada) e `icono` (un nombre de `resources/js/lib/iconos.ts`). Aparece sola en el menú y el Dashboard de quien pueda consultarla.
2. Proteger sus rutas con `AutorizarPermiso::para()` o `AutorizarAdministrador`.
3. Ejecutar `php artisan adm:sincronizar-permisos` en cada entorno.
4. Asignar sus acciones a los grupos desde Administración de Permisos.

## 5. Auditoría

`App\Auditoria\Auditor::registrar($db, $entidad, $registro, $accion, $cambios)`:

- exige una transacción abierta en la conexión del cambio; si la auditoría falla, se revierte todo;
- usuario e IP salen de la sesión y la petición (vacíos desde la consola); la fecha la pone SQL Server en UTC;
- acciones de la lista cerrada (`CREAR`, `EDITAR`, `HABILITAR`, `DESHABILITAR`, `ASIGNAR`, `RETIRAR`, `REVOCAR`);
- enmascara (`********`) los valores de claves que contienen `password`, `contrasena`, `clave`, `token`, `secret` o `secreto`, también anidadas.

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

| Comando                    | Función                                                                                                                               |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `adm:entorno`              | Diagnóstico de solo lectura del destino y la escritura                                                                                |
| `adm:sincronizar-permisos` | Sincroniza el catálogo; idempotente y no destructivo; audita cada cambio                                                              |
| `adm:crear-administrador`  | Cuenta administradora, grupo administrador (si falta) y membresía en una transacción; contraseña temporal oculta y cambio obligatorio |

Los comandos que escriben usan `DestinoSql::exigirEscritura()`: en producción requieren `ADM_PRODUCCION_ESCRITURA=true`.

## 8. Pruebas

Se ejecutan con SQLite en memoria y las mismas migraciones (`RefreshDatabase`). `tests/Concerns/CreaDatosAdministrativos.php` crea usuarios, grupos y asignaciones ficticios. `tests/js` contiene pruebas de la lógica del frontend (`npm run test:js`) y `tests/Feature/Visual/PaletaTest.php` vigila que no haya colores fuera de los tokens. Las validaciones propias de SQL Server están en el grupo `sqlsrv` (ver [ARQUITECTURA_SQL_SERVER.md](ARQUITECTURA_SQL_SERVER.md#6-pruebas)).

## 9. Dependencias

Solo las necesarias para la plantilla; `tests/Feature/NavegacionTest.php` comprueba que no haya referencias a módulos de otros sistemas.

- **PHP:**
    - producción: `laravel/framework`, `laravel/fortify` (solo inicio y cierre de sesión), `inertiajs/inertia-laravel`, `laravel/wayfinder` (rutas tipadas para el frontend) y `laravel/tinker` (consola de mantenimiento);
    - desarrollo: `phpunit/phpunit`, `mockery/mockery`, `nunomaduro/collision`, `laravel/pao` (salida de las pruebas), `laravel/pint` y `larastan/larastan`.
- **npm:**
    - Vue, Inertia, `reka-ui`, `class-variance-authority`, `clsx` y `tailwind-merge` (componentes base), `@lucide/vue`, `@vueuse/core`, `vue-sonner`, `sweetalert2`, Tailwind y `tw-animate-css`;
    - desarrollo: `vite-plus`, TypeScript, `vue-tsc`, `@types/node` (pruebas `node --test`), los plugins de Vite y `concurrently` (`composer run dev`).
- **Retiradas en la Etapa 7:** `fakerphp/faker` (sin factorías ni usos) y `laravel/pail` (exige la extensión `pcntl`, inexistente en Windows e IIS).
- **Componentes base:** `resources/js/components/ui` (shadcn-vue, `components.json`) se conserva completo aunque algunos componentes aún no se usen: es la base reutilizable de los módulos futuros.
- **Archivos públicos:** sin favicon de marca (`<link rel="icon" href="data:,">` en `app.blade.php`); cada implantación puede agregar el suyo en `public/`.
