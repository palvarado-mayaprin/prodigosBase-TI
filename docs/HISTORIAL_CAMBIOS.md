# Historial de cambios

## 2026-11-13 — Etapa 8.1: preparación de la validación en SQL Server

- **Preparado, no ejecutado:** `docs/VALIDACION_SQL_SERVER.md` con requisitos (SQL Server 2016+, compatibilidad 130, intercalación `Modern_Spanish_CI_AS`, tres cuentas sin roles de servidor y con acceso a una sola base), el procedimiento exacto (base, logins, `roles.sql`, migraciones, catálogo, administrador, verificaciones), la comprobación de aislamiento con `HAS_DBACCESS`, la prueba de integración, la configuración de Playwright MCP, los riesgos y las condiciones de autorización.
- **CI (`.github/workflows/tests.yml`):** PHP 8.4 (el `composer.lock` exige 8.4.1; con 8.3 la instalación fallaba), Node 24 (pruebas TypeScript nativas) y nuevo paso `npm run test:js`.
- **`public/robots.txt`:** `Disallow: /`; es una aplicación administrativa interna.
- **Prueba de integración:** `tearDown` solo revierte el esquema si la prueba lo creó. PHPUnit ejecuta `tearDown` aunque `setUp` falle, así que una base `_pruebas` no vacía con tabla `migrations` habría perdido sus tablas.
- **Documentación corregida:** `migrate --pretend` crea `dbo.migrations` antes de que `GuardaDdl` compruebe el destino (se antepone `adm:entorno` con la misma cuenta); `sqlcmd -b`; PHP mínimo 8.4.1.
- **Sin cambios:** lógica de la aplicación, migraciones, `roles.sql`, `.env`, SQL Server, IIS y PRODIGOS V2.
- **Verificación:** PHPUnit 156 aprobadas y 1 omitida (integración `sqlsrv`), `test:js` 13/13, Pint, Larastan 0, `npm run check`, `types:check` y `build`. El flujo de GitHub no se ejecutó (no hay repositorio remoto).

## 2026-11-13 — Etapa 7: limpieza y desacoplamiento final

- **Decisión aprobada:** apariencia única y clara, sin modo oscuro automático; la paleta (29 tokens) es el único elemento visual personalizable.
- **Cambio (solo limpieza verificada):**
    - dependencias de desarrollo retiradas: `fakerphp/faker` (sin factorías ni usos; también `faker_locale` y `APP_FAKER_LOCALE`) y `laravel/pail` (exige `pcntl`, inexistente en Windows e IIS). `composer.lock` solo pierde esos paquetes;
    - favicons genéricos del kit inicial (`favicon.ico`, `favicon.svg` con el logotipo de Laravel, `apple-touch-icon.png`) sustituidos por `<link rel="icon" href="data:,">`;
    - `pnpm-workspace.yaml` (el proyecto usa npm), el comando de ejemplo `inspire` y los tipos `Appearance`/`ResolvedAppearance` del modo oscuro retirado;
    - comentarios de código neutros (sin citar el sistema de referencia);
    - documentación: contradicción corregida (iniciar sesión sí registra la sesión cuando la escritura está permitida), referencias de etapa obsoletas y sección de dependencias en el manual técnico.
- **Conservado:** componentes base de `components/ui` (reutilizables), `components.json`, configuración SSR de Inertia, flujo de GitHub Actions, `laravel/tinker` y `concurrently` (`composer run dev`).
- **Independencia:** sin rutas, servidores, bases, repositorios ni archivos de PRODIGOS V2; las menciones restantes son de origen del diseño en la documentación y el historial.
- **Sin cambios:** lógica de autenticación, permisos, auditoría, sesiones, seguimiento y entornos; las nueve migraciones; PRODIGOS V2, `.env`, SQL Server e IIS.
- **Verificación:** PHPUnit 156 aprobadas y 1 omitida (integración `sqlsrv`), `test:js` 13/13, Pint, Larastan 0, `npm run check`, `types:check` y `build`.
- **Pendiente:** Etapa 8 (verificación integral) y Etapa 9 (documentación final y publicación en IIS).

## 2026-11-13 — Etapa 6: consolidación del lenguaje visual

- **Cambio:**
    - inicio de sesión y cambio de contraseña con la distribución de PRODIGOS V2 (`AuthSplitLayout`, familia `adm-login-*`): campos con icono, botón «Aceptar», panel de marca en diagonal; marca neutra (inicial y nombre de `APP_NAME`, descripción `ADM_APP_DESCRIPCION`) en lugar de logotipos; Fortify sin cambios;
    - componentes base (shadcn-vue) derivados de los tokens en `app.css`; sin colores fijos en botones, insignias, velos de diálogo, errores de campo y avatar; barra de progreso, fondo y carga de SweetAlert2 y fondo inicial del documento con tokens;
    - paleta única y clara: se retiran el modo oscuro automático (`HandleAppearance`, `useAppearance`, script de `app.blade.php`) y su paleta gris; se eliminan los logotipos del kit inicial (`AppLogo`, `AppLogoIcon`, `AuthSimpleLayout`);
    - `PasswordInput`: botón de mostrar contraseña en español, accesible con teclado y con `aria-pressed`;
    - `docs/GUIA_DISENO_UI.md` (estructura, jerarquía, tipografía, tokens, clases `adm-*`, patrones, responsive, accesibilidad, cambio de paleta y reglas) y `docs/ejemplos/paleta-ejemplo.css` (segunda paleta para verificar la personalización);
    - `PaletaTest` revisa todo el frontend y comprueba que la paleta solo contenga colores y que la de ejemplo tenga los mismos tokens.
- **Sin cambios:** PRODIGOS V2, `.env`, lógica administrativa, esquema, SQL Server, IIS y dependencias.
- **Verificación:** PHPUnit 156 aprobadas y 1 omitida (integración `sqlsrv`), `test:js` 13/13, Pint, Larastan 0, `npm run check`, `types:check` y `build`. Contraste AA (4,5:1) calculado en los pares de texto de ambas paletas. Sin revisión con navegador: no hay entorno local con base SQL Server autorizada.
- **Pendiente:** revisión visual en navegador (escritorio, móvil y teclado) con ambas paletas; Etapas 7 a 9.

## 2026-11-12 — Etapa 5.5: Dashboard y navegación dinámica

- **Cambio:**
    - `App\Navegacion\Navegacion`: menú y Dashboard desde `CatalogoPermisos` filtrado por `Autorizacion::rutasPermitidas()` (sin listas fijas de módulos), compartido como `navegacion`;
    - `rutasPermitidas()` omite funcionalidades desactivadas en el catálogo o en `adm_funcionalidades`; el catálogo declara un `icono` por funcionalidad y las páginas comunes (`PAGINAS_COMUNES`: Inicio y Mi Perfil);
    - menú lateral con marca, buscador, grupos por área y módulo, contraíble a iconos y estado activo; barra superior con migas de pan; Dashboard con tarjetas por área y estado vacío (`AppSidebar`, `NavMain`, `AppSidebarHeader`, `Dashboard`, `lib/navegacion.ts`, `lib/iconos.ts`);
    - Seguimiento de Uso registra Inicio y Mi Perfil para cualquier cuenta (`pagina.dashboard`, `pagina.mi-perfil`) sin crear permisos;
    - tokens del menú lateral en `tokens.css` y familias `adm-sidebar`, `adm-topbar` y `adm-module-card` en `componentes.css`.
- **Sin cambios:** PRODIGOS V2, `.env`, esquema (sin migraciones), SQL Server, IIS y dependencias.
- **Verificación:** PHPUnit 153 aprobadas y 1 omitida (integración `sqlsrv`), `test:js` 13/13, Pint, Larastan 0, `npm run check`, `types:check` y `build`.
- **Pendiente:** Etapa 6 (inicio de sesión y guía de personalización del sistema visual); revisión visual en navegador y validación en SQL Server real.

## 2026-11-12 — Etapa 5.4: Auditoría de Modificaciones y Seguimiento de Uso

- **Cambio:**
    - Auditoría de Modificaciones (`admin/auditoria`, `ConsultaAuditoria`): consulta de solo lectura con filtros (fechas, usuario, entidad, acción, texto), orden, paginación en el servidor, detalle legible de valores anteriores y nuevos, secretos y hashes ocultos aun anidados, y exportación CSV/XLSX por cursor;
    - Seguimiento de Uso: registro de visitas a funcionalidades del catálogo que la cuenta puede ver y de su tiempo activo (`seguimientoUso.ts`, `SeguimientoUsoController`, `SeguimientoUso`), ligado a la fila de `adm_sesiones` de la sesión, con los segundos limitados al tiempo real; reportes (`admin/seguimiento`) con indicadores, por usuario, módulo y día, historial paginado y cuatro exportaciones CSV/XLSX;
    - `App\Support\ExportarTabla` (CSV y XLSX sin dependencias, con fórmulas neutralizadas) y `App\Support\Fechas` (UTC guardado, presentación y filtros en `ADM_ZONA_HORARIA`, compartida con la interfaz como `zonaHoraria`; Sesiones Activas también la usa);
    - `componentes.css`: indicadores, paginación, gráfico de barras, barras de proporción y exportaciones con tokens; `lib/fechas.ts` con `duracion` y `fechaDia`.
- **Sin cambios:** PRODIGOS V2, `.env`, esquema (sin migraciones), SQL Server, IIS y dependencias. `.env.example` documenta `ADM_ZONA_HORARIA`.
- **Solo lectura:** consultas y exportaciones disponibles; el registro de uso se rechaza (sin excepción al control centralizado) y no se cuenta actividad.
- **Verificación:** PHPUnit 144 aprobadas y 1 omitida (integración `sqlsrv`), `test:js` 10/10, Pint, Larastan 0, `npm run check`, `types:check` y `build`.
- **Pendiente:** Dashboard y navegación dinámica (Etapa 5.5); revisión visual en navegador y validación en SQL Server real.

## 2026-11-12 — Etapa 5.3: Sesiones Activas

- **Cambio:**
    - registro de sesiones en `adm_sesiones` (`App\Services\Admin\RegistroSesiones`): una fila por inicio de sesión (eventos `Login` y `Logout`), con usuario, IP, agente y fechas UTC; la sesión de Laravel (en archivos) guarda el id de su fila y la base nunca recibe el identificador de sesión ni la cookie;
    - `ValidarSesion` (grupo `web`) relee la fila en cada petición, actualiza la última actividad como mucho una vez por minuto y rechaza en la siguiente petición una sesión revocada, cerrada o ajena;
    - Sesiones Activas (`admin/sesiones`, `AdministrarSesiones`): listado con estado (activa, vencida, cerrada, revocada), revocación individual y de todas las sesiones de una cuenta, nunca la propia sesión, referencias HMAC en lugar de identificadores y auditoría `REVOCAR` en la misma transacción;
    - con la producción en solo lectura el inicio de sesión funciona sin registro (sin excepción al control de `DestinoSql`);
    - `tests/TestCase::be()` trata cada cambio de cuenta con `actingAs()` como una sesión nueva; `lib/fechas.ts` con sus pruebas JS.
- **Sin cambios:** PRODIGOS V2, `.env`, `SESSION_DRIVER=file`, esquema (sin migraciones nuevas), SQL Server e IIS.
- **Verificación:** PHPUnit 126 aprobadas y 1 omitida (integración `sqlsrv`), `test:js` 9/9, Pint, Larastan 0, `npm run check`, `types:check` y `build`.
- **Pendiente:** Auditoría, Seguimiento de Uso, Dashboard y navegación; revisión visual en navegador y validación en SQL Server real.

## 2026-11-12 — Etapa 5.2: Grupos, membresías y permisos

- **Cambio:**
    - Administración de Grupos (`admin/grupos`): alta, edición de nombre y descripción, activación y desactivación sin eliminación física, nombre único, detalle con integrantes y permisos; `es_administrador` se fija al crear; los grupos administradores se protegen para no dejar el sistema sin administradores ni quitar la administración a quien actúa (`App\Services\Admin\AdministrarGrupos`);
    - Usuarios y grupos (`admin/membresias`): modalidades Usuarios → Grupos (edición) y Grupos → Usuarios (consulta) de PRODIGOS V2; concurrencia optimista con la base leída (`Relaciones`), sin duplicados, sin agregar a grupos inactivos, sin retirarse la propia administración y con la protección del último administrador (`AdministrarMembresias`); consultar exige VER y modificar, un grupo administrador;
    - Administración de Permisos (`admin/permisos`): árbol del catálogo centralizado; solo se asignan permisos declarados, de funcionalidades activas y no `soloAdministrador`; los grupos administradores no se editan (`AdministrarPermisos`);
    - todo auditado en la misma transacción (`CREAR`, `EDITAR`, `HABILITAR`, `DESHABILITAR`, `ASIGNAR`, `RETIRAR`);
    - `Autorizacion::quedariaSinAdministradores` admite la desactivación de un grupo y se añade `gruposAdministradores`;
    - `componentes.css` incorpora con tokens las familias de selección de PRODIGOS V2 (selector de modalidad, listas, opciones de grupo, indicadores de cambios y árbol de permisos); `lib/seleccion.ts` con sus pruebas JS.
- **Sin cambios:** PRODIGOS V2, `.env`, SQL Server, IIS y el catálogo de permisos (ya declaraba estas funcionalidades). Las rutas quedan registradas para la navegación dinámica (`rutasPermitidas`).
- **Verificación:** PHPUnit 116 aprobadas y 1 omitida (integración `sqlsrv`), `test:js` 7/7, Pint, Larastan 0, `npm run check`, `types:check` y `build`.
- **Pendiente:** Sesiones Activas, Auditoría, Seguimiento de Uso, Dashboard y navegación; revisión visual en navegador y validación en SQL Server real cuando se autorice una base.

## 2026-11-12 — Etapa 5.1: Administración de Usuarios y Mi Perfil

- **Cambio:**
    - Administración de Usuarios (`admin/usuarios`): alta con hash y cambio obligatorio, edición, restablecimiento de contraseña (vuelve a obligar a cambiarla), habilitar y deshabilitar; usuario y correo únicos; sin deshabilitar la propia cuenta ni dejar el sistema sin administradores; todo auditado sin contraseñas (`App\Services\Admin\AdministrarUsuarios`);
    - Mi Perfil (`mi-perfil`): consulta, correo y teléfono editables y cambio de contraseña reutilizando `CambiarContrasena`; acceso desde el menú de usuario;
    - pantallas trasladadas de PRODIGOS V2 con las mismas medidas, jerarquías y patrones (búsqueda y orden locales, formularios, confirmaciones y mensajes);
    - primer subconjunto del sistema visual: `resources/css/tema/tokens.css` (único archivo con colores) y `componentes.css` (prefijo `adm-`), y `lib/alertas.ts` con SweetAlert2 (nueva dependencia `sweetalert2`);
    - mensajes en español (`lang/es`) e idioma `es` por defecto;
    - `npm run test:js` (`node --test`) para la lógica del frontend.
- **Sin cambios:** PRODIGOS V2, `.env`, SQL Server e IIS. El menú lateral dinámico llega con la navegación (Etapa 5).
- **Verificación:** PHPUnit 91 aprobadas y 1 omitida (integración `sqlsrv`), `test:js` 3/3, Pint, Larastan 0, `npm run check`, `types:check` y `build`.
- **Pendiente:** el resto de la Etapa 5; validar en SQL Server real cuando se autorice una base.

## 2026-11-12 — Etapa 4: autenticación, autorización, catálogo y auditoría

- **Cambio:**
    - `App\Models\Usuario` sobre `adm_usuarios` sustituye al `User` provisional; Fortify autentica por `usuario` con hash, rechaza cuentas inactivas y «recordarme» y compara siempre contra un hash para no revelar si la cuenta existe;
    - política única de contraseñas (12 a 72 caracteres, mayúsculas, minúsculas, números y símbolos) en todos los entornos;
    - `ExigirCambioContrasena` y la pantalla `auth/CambiarContrasena` (`App\Services\Admin\CambiarContrasena`, auditada);
    - `CatalogoPermisos` solo con funcionalidades administrativas, `Autorizacion` por grupos activos y `es_administrador` (sin grupo `-1`), `AutorizarPermiso`, `AutorizarAdministrador` y la protección del último administrador;
    - `App\Auditoria\Auditor`: transacción obligatoria y secretos enmascarados;
    - comandos `adm:sincronizar-permisos` (idempotente, no destructivo y auditado) y `adm:crear-administrador`;
    - `DestinoSql` y `GuardaDdl` validan la conexión predeterminada (`DB_CONNECTION`, que debe usar el driver `sqlsrv`), la misma que usan servicios y migraciones; la única excepción sigue siendo PHPUnit con SQLite en memoria;
    - el frontend recibe solo `id`, `usuario`, `name` y `email` del usuario;
    - `composer types:check` ejecuta PHPStan sin OPcache: en Windows la caché compartida entre procesos mezclaba el `.phar` de otro proyecto.
- **Sin cambios:** PRODIGOS V2, `.env`, SQL Server e IIS; sin pantallas administrativas (Etapa 5).
- **Verificación:** PHPUnit 69 aprobadas y 1 omitida (integración `sqlsrv`); Pint, Larastan 0, `npm run check`, `types:check` y `build`.
- **Pendiente:** Etapa 5 (módulos administrativos y navegación); validar en SQL Server real cuando se autorice una base.

## 2026-11-12 — Etapa 3: esquema SQL Server

- **Cambio:**
    - nueve migraciones `adm_*` (usuarios, grupos, membresías, funcionalidades, permisos, asignaciones, auditoría, sesiones y actividad): `bigint IDENTITY`, FK sin cascada, PK compuestas en las asignaciones, únicos, `datetime2(3)` en UTC, `enum` con CHECK y contraseñas como hash de Laravel;
    - SQL propio de SQL Server condicionado al driver: email único filtrado, índice filtrado de sesiones activas, `ISJSON` en la auditoría, `SYSUTCDATETIME()` por defecto y CHECK de segundos;
    - `database/sql/roles.sql` idempotente y parametrizado: `adm_aplicacion` sin DDL ni borrado de datos (salvo asignaciones), auditoría solo `INSERT` y `SELECT` con `DENY UPDATE, DELETE`; `adm_despliegue` para migraciones;
    - `GuardaDdl` exige además que la migración use la conexión `sqlsrv`;
    - `docs/ARQUITECTURA_SQL_SERVER.md` y secciones 4 y 5 del manual (base, logins, roles, migraciones y comprobaciones).
- **Sin cambios:** no se creó ninguna base ni se ejecutaron migraciones en SQL Server; `.env`, PRODIGOS V2 e IIS intactos.
- **Verificación:**
    - PHPUnit 34 aprobadas y 1 omitida (integración `sqlsrv`, sin base habilitada);
    - el DDL de SQL Server se revisó sin conexión, en modo `pretend` de Laravel;
    - Pint, Larastan 0, `npm run check`, `types:check` y `build`.
- **Pendiente:** ejecutar `roles.sql` y la integración `sqlsrv` contra una base real cuando se autorice; Etapa 4 (autenticación, catálogo y auditoría).

## 2026-11-11 — Etapa 2: limpieza del starter y control de entornos

- **Limpieza del starter kit:**
    - fuera `laravel/chisel` (con `install:features` y su hook de `post-update-cmd`), `laravel/sail`, `@laravel/passkeys` y `vue-input-otp`. `react` desaparece con passkeys, que lo arrastraba como dependencia opcional (la entrada que queda en `package-lock.json` es de `@laravel/multiplex`, solo para macOS y Linux);
    - Fortify solo con inicio y cierre de sesión: `features => []` y `Fortify::ignoreRoutes()`, con `login`, `login.store` y `logout` declaradas en `routes/web.php`; sin registro, recuperación, verificación, confirmación de contraseña, 2FA ni passkeys;
    - eliminados `Welcome`, ajustes (`settings/*`), acciones y reglas de Fortify del starter, layouts y componentes sin uso, las migraciones `users`, `cache`, `jobs`, `passkeys` y 2FA, la factory y el seeder, y sus pruebas;
    - `User` queda como proveedor provisional, sin tabla, hasta la Etapa 4;
    - el disco `local` ya no publica rutas firmadas (`serve => false`).
- **Entornos:**
    - `App\Entorno\DestinoSql` con el mismo contrato público que la referencia y destinos configurables (`ADM_ENTORNO`, `ADM_PRODUCCION_ESCRITURA`, `ADM_DESARROLLO_*`, `ADM_PRODUCCION_*`). Rechaza destinos incompletos o iguales entre entornos, compara servidor y base sin distinguir mayúsculas e informa la causa de un fallo de conexión;
    - `App\Http\Middleware\ControlarAcceso` al inicio del grupo `web`: entorno admitido, origen (`ADM_REDES_CORPORATIVAS` en producción, el propio equipo en local), coherencia del destino y bloqueo de toda mutación sin escritura permitida;
    - `App\Entorno\GuardaDdl` en `MigrationsStarted`: destino real coherente y, en producción, `ADM_DESPLIEGUE_DDL` en la consola; el interruptor de escritura no habilita DDL;
    - comando de solo lectura `adm:entorno`.
- **Sin cambios:** PRODIGOS V2, SQL Server e IIS; no se creó ninguna base ni se ejecutaron migraciones.
- **Verificación:** PHPUnit 24/24 sin SQL Server (desarrollo, local contra producción con y sin escritura, IIS con y sin escritura y `APP_DEBUG`, origen, `APP_ENV` no admitido, configuración inválida, destino real incorrecto, mayúsculas, `DB_URL` y driver, una consulta por petición, logout, HEAD y OPTIONS, `adm:entorno` sin secretos, DDL y acceso inicial); Pint, Larastan 0, `npm run check`, `types:check` y `build`.
- **Pendiente:** las rutas `_inertia/devtools/*` las registra Inertia fuera del grupo `web`; su propio control las limita al entorno local. Etapa 3: esquema SQL Server.

## 2026-11-11 — Etapa 1: proyecto base

- **Cambio:**
    - proyecto creado desde `laravel/vue-starter-kit` (rama `dev-main`, la misma línea que PRODIGOS V2) sin scripts de creación: no se creó ninguna base ni se ejecutaron migraciones;
    - SQL Server como conexión por defecto, con `DB_ENCRYPT` y `DB_TRUST_SERVER_CERTIFICATE` configurables; sesión y caché en `file` y cola `sync`;
    - `.env.example` neutro, en español y sin datos de ningún sistema real;
    - `composer run setup` ya no ejecuta migraciones; Larastan con `--memory-limit=512M`;
    - estructura reservada (`app/Entorno`, `app/Autorizacion`, `app/Auditoria`, `app/Services/Admin`, `resources/css/tema`), documentada con un README por carpeta y sin clases vacías;
    - `AGENTS.md`, `CLAUDE.md`, `README.md` y el inicio del manual de instalación.
- **Sin cambios:** PRODIGOS V2, SQL Server e IIS.
- **Verificación:** `npm run build`, `npm run types:check`, `npm run check`, Pint, Larastan 0 y PHPUnit 42/42 (pruebas del starter kit con SQLite en memoria); `php artisan about` confirma `sqlsrv`, `file`, `file` y `sync`.
- **Pendiente:** Etapa 2 (limpieza del starter kit y control de entornos).
