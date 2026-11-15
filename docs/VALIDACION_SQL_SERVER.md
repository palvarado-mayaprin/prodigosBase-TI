# Validación en SQL Server y en navegador

Pruebas de prodigosBase-TI contra SQL Server real y en navegador, y registro de sus resultados. La **instalación** (bases, cuentas, roles, `.env`, migraciones y primer administrador) está solo en el [manual de instalación](MANUAL_INSTALACION_Y_CONFIGURACION.md); este documento añade los requisitos detallados del servidor, la prueba de integración, la revisión con Playwright y los resultados de cada etapa (secciones 8 y 9).

Los valores entre `<>` se sustituyen al ejecutar. Las contraseñas nunca se escriben en archivos, documentos ni en el historial de la consola. Cualquier servidor compartido requiere autorización expresa del DBA (sección 7).

## 1. Requisitos

### 1.1 Servidor

| Requisito                | Valor                                                                                                                                                                                            |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Versión                  | SQL Server 2016 o superior (cualquier edición, también Developer o Express)                                                                                                                      |
| Nivel de compatibilidad  | 130 o superior en la base. Lo exige `ISJSON` (CHECK de `adm_auditoria.cambios`)                                                                                                                  |
| Intercalación de la base | Insensible a mayúsculas y sensible a acentos (`*_CI_AS`); propuesta `Modern_Spanish_CI_AS`. Los índices únicos de `usuario`, `adm_grupos.nombre` y `adm_funcionalidades.codigo` dependen de ella |
| Autenticación            | Modo mixto si se usan logins SQL (`SERVERPROPERTY('IsIntegratedSecurityOnly') = 0`); con autenticación de Windows no hace falta                                                                  |
| Opciones de sesión       | `ANSI_NULLS` y `QUOTED_IDENTIFIER` activas (valor por defecto de `pdo_sqlsrv` y `sqlcmd`); las exigen los índices filtrados                                                                      |
| Red                      | Puerto TCP accesible desde el equipo de desarrollo (por defecto 1433)                                                                                                                            |

Las cadenas se crean como `nvarchar` (Unicode), así que el juego de caracteres de la intercalación no limita los datos. No se requiere `READ_COMMITTED_SNAPSHOT` ni ninguna otra opción de base distinta de los valores por defecto.

### 1.2 Bases

| Base                    | Uso                                                                  | Contenido inicial |
| ----------------------- | -------------------------------------------------------------------- | ----------------- |
| `prodigos_base_dev`     | Desarrollo de la aplicación (`.env`)                                 | Vacía             |
| `prodigos_base_pruebas` | Solo la prueba de integración (`tests/Sqlsrv`); se vacía al terminar | Vacía             |

Son bases nuevas, exclusivas de prodigosBase-TI. Nunca se reutiliza una base de otro sistema.

### 1.3 Cuentas

| Login              | Usuario en              | Rol de base                                               | Dónde se usa                          |
| ------------------ | ----------------------- | --------------------------------------------------------- | ------------------------------------- |
| `adm_base_app`     | `prodigos_base_dev`     | `adm_aplicacion` (DML acotado, sin DDL)                   | `.env` (`DB_USERNAME`, `DB_PASSWORD`) |
| `adm_base_ddl`     | `prodigos_base_dev`     | `adm_despliegue` (`db_ddladmin` y DML en `dbo`)           | Solo la consola de migraciones        |
| `adm_base_pruebas` | `prodigos_base_pruebas` | `adm_despliegue` (crea y elimina el esquema de la prueba) | Solo las variables de la prueba       |

Ninguna de las tres cuentas tiene roles de servidor ni usuario en otra base. Con autenticación de Windows se sustituyen por cuentas de dominio con la misma separación.

### 1.4 Equipo de desarrollo

PHP 8.4.1 o superior (`composer.json`: `^8.4.1`) con `pdo_sqlsrv` y `sqlsrv`, Microsoft ODBC Driver 17 o 18 y `sqlcmd`. Comprobación:

```powershell
php -v
php -m | Select-String sqlsrv     # pdo_sqlsrv y sqlsrv
sqlcmd -?                         # cualquier versión
```

## 2. Instalación de la base de desarrollo

Secciones 3 a 5 del [manual de instalación](MANUAL_INSTALACION_Y_CONFIGURACION.md#3-preparar-sql-server): comprobaciones previas, base y logins, `roles.sql` (dos ejecuciones), aislamiento de las cuentas, `.env`, migraciones con la cuenta de despliegue, catálogo y primer administrador. Comprobación adicional de los permisos del rol de la aplicación:

```sql
SELECT OBJECT_NAME(major_id) AS tabla, permission_name, state_desc
FROM sys.database_permissions
WHERE grantee_principal_id = DATABASE_PRINCIPAL_ID(N'adm_aplicacion')
ORDER BY tabla, permission_name;   -- DENY UPDATE y DELETE en adm_auditoria
```

## 3. Prueba de integración SQL Server

`tests/Sqlsrv/EsquemaSqlServerTest.php` (grupo `sqlsrv`) comprueba lo que SQLite no reproduce: índice filtrado de email, CHECK con `ISJSON`, `SYSUTCDATETIME()`, CHECK de segundos no negativos y tipos reales (`datetime2`, `nvarchar`).

Requisitos y salvaguardas:

- base **dedicada, vacía** y cuyo nombre termine en `_pruebas`; si no termina así, falla sin conectarse a ella, y si no está vacía, falla antes de migrar;
- exige `ADM_PRUEBAS_DESTRUCTIVAS` con el nombre exacto de esa base, que autoriza la limpieza final; sin ella falla antes de migrar;
- crea el esquema con `migrate` (pasa por `GuardaDdl` con esa base como destino de desarrollo) y al terminar ejecuta `migrate:reset` y elimina `migrations`, **solo** si fue esta prueba la que creó el esquema;
- cuenta con rol `adm_despliegue` en esa base y en ninguna otra;
- se omite salvo `ADM_PRUEBAS_SQLSRV=1`; nunca usa `.env`.

Preparación (DBA):

```sql
USE master;
CREATE DATABASE prodigos_base_pruebas COLLATE Modern_Spanish_CI_AS;
GO
CREATE LOGIN adm_base_pruebas WITH PASSWORD = N'<contraseña segura>', DEFAULT_DATABASE = prodigos_base_pruebas, CHECK_POLICY = ON;
GO
USE prodigos_base_pruebas;
CREATE USER adm_base_pruebas FOR LOGIN adm_base_pruebas;
GO
```

```powershell
sqlcmd -S <servidor> -d prodigos_base_pruebas -E -b -f 65001 -i database\sql\roles.sql `
       -v BaseEsperada="prodigos_base_pruebas" UsuarioAplicacion="" UsuarioDespliegue="adm_base_pruebas"
sqlcmd -S <servidor> -U adm_base_pruebas -d prodigos_base_pruebas -b -Q "SET NOCOUNT ON; SELECT name FROM sys.databases WHERE database_id > 4 AND HAS_DBACCESS(name) = 1;"
```

Esperado en la última consulta: solo `prodigos_base_pruebas`.

Ejecución, en una consola nueva:

```powershell
$env:ADM_PRUEBAS_SQLSRV = '1'
$env:ADM_PRUEBAS_SQLSRV_HOST = '<servidor>'
$env:ADM_PRUEBAS_SQLSRV_PORT = '1433'
$env:ADM_PRUEBAS_SQLSRV_BD = 'prodigos_base_pruebas'
$env:ADM_PRUEBAS_SQLSRV_SERVIDOR = '<valor de SELECT @@SERVERNAME>'
$env:ADM_PRUEBAS_SQLSRV_USUARIO = 'adm_base_pruebas'
$env:ADM_PRUEBAS_SQLSRV_CLAVE = [Net.NetworkCredential]::new('', (Read-Host 'Contraseña de adm_base_pruebas' -AsSecureString)).Password
$env:ADM_PRUEBAS_DESTRUCTIVAS = 'prodigos_base_pruebas'
php artisan test --testsuite=Sqlsrv
Remove-Item Env:ADM_PRUEBAS_SQLSRV*, Env:ADM_PRUEBAS_DESTRUCTIVAS
```

Si el certificado lo exige, definir también `$env:DB_ENCRYPT` y `$env:DB_TRUST_SERVER_CERTIFICATE` en esa consola. Esperado: 1 prueba aprobada y la base de nuevo vacía (`SELECT name FROM sys.tables;` sin filas). Si `migrate` fallara a medias, el esquema parcial queda en `prodigos_base_pruebas` y se limpia a mano antes de repetir.

## 4. Validación funcional

Con el administrador inicial, recorrer la tabla de la sección 7 del [manual de instalación](MANUAL_INSTALACION_Y_CONFIGURACION.md#7-verificaciones-y-pruebas) con cuentas de prueba ficticias. Además, en SQL Server real:

- **Sesiones:** iniciar sesión en dos navegadores, revocar una desde Sesiones Activas y comprobar que su siguiente petición vuelve al inicio de sesión.
- **Concurrencia:** abrir la misma membresía o los mismos permisos en dos pestañas, guardar en una y después en la otra; la segunda debe recibir el aviso de conflicto y no aplicar sus cambios.
- **Auditoría:** como `adm_base_app`, `UPDATE adm_auditoria SET accion = accion;` debe fallar por permisos.

## 5. Revisión en navegador con Playwright MCP

Ejecutada en las Etapas 8.2 y 8.3 (resultados en las secciones 8.5 y 8.6). Solo procede con una base de desarrollo funcionando y autorizada.

Configuración: `.mcp.json` en la raíz (está en `.gitignore`, no se versiona), con versión fija, perfil en memoria, navegación limitada al servidor local y las contraseñas de las cuentas ficticias entregadas como **secretos con nombre**:

```json
{
    "mcpServers": {
        "playwright-pbti": {
            "type": "stdio",
            "command": "powershell.exe",
            "args": [
                "-NoProfile",
                "-NonInteractive",
                "-File",
                "<carpeta local>\\iniciar-mcp.ps1",
                "--isolated",
                "--headless",
                "--browser",
                "msedge",
                "--allowed-origins",
                "http://127.0.0.1:8010",
                "--output-dir",
                "storage/app/revision-visual",
                "--viewport-size",
                "1366x768",
                "--codegen",
                "none"
            ]
        }
    }
}
```

`iniciar-mcp.ps1` vive fuera del repositorio: descifra con DPAPI las contraseñas ficticias, las escribe en un archivo temporal con permisos solo del usuario (que borra a los 30 segundos) y lanza `@playwright/mcp` (versión fija, instalada localmente) con `--secrets <archivo>`. En el navegador se escribe el **nombre** del secreto (p. ej. `ADM_ADMIN_CLAVE`), nunca su valor, y Playwright oculta los valores en sus respuestas. No usar «Mostrar contraseña» en capturas.

`--allowed-origins` no es una barrera de seguridad; la protección real es que la aplicación solo admite conexiones del propio equipo y apunta a la base de desarrollo. `storage/app` está excluido del control de versiones. Con `php artisan serve`, usar una pestaña por contexto (sección 8.3).

Guion de la revisión:

1. `npm run build`, `php artisan serve --host=127.0.0.1 --port=8010` y `php artisan adm:entorno` coherente (sin Vite).
2. Escritorio (1366×768) y móvil (390×844, con `browser_resize`): inicio de sesión (error y correcto), cambio de contraseña obligatorio, Dashboard, menú lateral (contraído y buscador), cada módulo administrativo con sus filtros, formularios, confirmaciones y estados vacíos.
3. Teclado: recorrido con Tab y Mayús+Tab, foco visible, Esc en diálogos.
4. Con la paleta de `docs/ejemplos/paleta-ejemplo.css` aplicada temporalmente (y restaurada al terminar), repetir inicio de sesión, Dashboard y una tabla.
5. Consola del navegador sin errores (`browser_console_messages`).

Solo cuentas y datos ficticios; nunca credenciales reales en capturas.

## 6. Riesgos y salvaguardas

| Riesgo                                                                              | Salvaguarda                                                                                                                                                                                                                                                                   |
| ----------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Un comando actúa sobre la base de otro sistema                                      | Las cuentas nuevas solo tienen usuario en su base (manual de instalación, 3.4): cualquier conexión a otra base falla en SQL Server. Además `DestinoSql` exige que `DB_DATABASE`, `DB_NAME()` y `@@SERVERNAME` coincidan con el destino configurado                            |
| `migrate` o `migrate --pretend` escriben antes de comprobar el destino              | **Corregido (Etapa 8.1, correcciones):** `GuardaDdl` se comprueba al iniciar el comando, antes de crear `dbo.migrations`. Con un destino incoherente o, en producción, sin `ADM_DESPLIEGUE_DDL`, no se escribe nada                                                           |
| `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` y `migrate:rollback` | **Corregido:** bloqueados salvo en una base de desarrollo cuyo nombre termine en `_pruebas`, con el destino real coincidente y la variable de proceso `ADM_PRUEBAS_DESTRUCTIVAS` igual a esa base. Nunca en producción ni en `prodigos_base_dev`. Ya no dependen de `APP_ENV` |
| `roles.sql` sobre una base equivocada                                               | **Corregido:** exige `-v BaseEsperada` igual a `DB_NAME()`; si falta o no coincide, se detiene antes de cualquier cambio. Con `-b`, `sqlcmd` termina con error                                                                                                                |
| La prueba de integración vacía la base que se le indique                            | Exige sufijo `_pruebas`, base vacía y `ADM_PRUEBAS_DESTRUCTIVAS`; solo revierte el esquema que creó                                                                                                                                                                           |
| Credenciales en el historial de la consola o en archivos                            | Contraseñas pedidas con `Read-Host -AsSecureString`; nunca en `.env.example`, documentos ni capturas                                                                                                                                                                          |
| Intercalación sensible a mayúsculas                                                 | Crear la base con `Modern_Spanish_CI_AS` (manual de instalación, 3.2) y verificarla                                                                                                                                                                                           |
| Versión de PHP                                                                      | **Corregido:** `composer.json` exige `^8.4.1`, coherente con `composer.lock` y con el CI (PHP 8.4)                                                                                                                                                                            |

**Confirmado en la Etapa 8.2 (instancia local):** `roles.sql` con `BaseEsperada` distinta de `-d` se detiene con 50005 sin crear roles, y `GuardaDdl` bloquea contra el servidor real un destino incorrecto (`migrate` y `migrate --pretend`) y los comandos destructivos sobre `prodigos_base_dev`, sin crear `dbo.migrations`.

## 7. Autorización en servidores compartidos

En una instancia local dedicada (sección 8) basta seguir el manual. En cualquier otro servidor, antes de crear nada:

1. Servidor SQL Server de desarrollo identificado (nombre y `@@SERVERNAME`), distinto de cualquier servidor productivo o, si se comparte instancia, con aprobación expresa del DBA.
2. Nombres `prodigos_base_dev` y `prodigos_base_pruebas` libres (manual de instalación, 3.1).
3. DBA responsable de crear bases y logins y de custodiar las contraseñas.
4. Aceptación de las tres cuentas sin roles de servidor y con acceso a una sola base cada una.
5. Respaldo del `.env` actual del equipo antes de editarlo.
6. Autorización expresa para ejecutar las migraciones y `roles.sql` en esas bases, y para la revisión con Playwright cuando la aplicación funcione.

## 8. Instancia local dedicada (Etapa 8.2)

Entorno de validación propio, sin relación con servidores de la empresa. Se puede reproducir en cualquier equipo de desarrollo.

### 8.1 Instalación

| Elemento                   | Valor                                                                                                                                                                                                                                    |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Producto                   | SQL Server 2022 Developer (16.0.1000.6), solo el componente del motor (`FEATURES=SQLENGINE`)                                                                                                                                             |
| Instancia                  | `PRODIGOSBASE` (`@@SERVERNAME` = `<EQUIPO>\PRODIGOSBASE`), separada de cualquier otra instancia del equipo                                                                                                                               |
| Medio                      | ISO oficial `SQLServer2022-x64-ENU-Dev.iso` de `download.microsoft.com`; `setup.exe` con firma Microsoft válida. Los instaladores web `SQL2022-SSEI-*.exe` (16.2211) ya no funcionan («Esta versión del instalador ya no es compatible») |
| Disco                      | NTFS. SQL Server no admite exFAT para archivos de instancia ni de datos (el instalador falla con «No se puede encontrar el valor solicitado 'exFAT'»)                                                                                    |
| Intercalación del servidor | `Modern_Spanish_CI_AS`                                                                                                                                                                                                                   |
| Autenticación              | Mixta (las tres cuentas son logins SQL); administración con Windows (`SQLSYSADMINACCOUNTS`); `sa` con contraseña aleatoria no conservada y **deshabilitado**                                                                             |
| Red                        | TCP solo en `127.0.0.1:14330` (`ListenOnAllIPs=0`, resto de direcciones deshabilitadas), canalizaciones con nombre desactivadas, SQL Browser deshabilitado. Sin reglas de firewall                                                       |
| Servicios                  | Motor automático; Agente y telemetría (CEIP) de la instancia deshabilitados                                                                                                                                                              |

Instalación desatendida con un archivo de configuración (`ACTION=Install`, `FEATURES=SQLENGINE`, `INSTANCENAME=PRODIGOSBASE`, `SQLCOLLATION`, `SQLSYSADMINACCOUNTS`, `SECURITYMODE=SQL`, `AGTSVCSTARTUPTYPE=Disabled`, `SQLTELSVCSTARTUPTYPE=Disabled`, `TCPENABLED=0`, `NPENABLED=0`, `UPDATEENABLED=False`) y `setup.exe /ConfigurationFile=<archivo> /IACCEPTSQLSERVERLICENSETERMS /SAPWD=<aleatoria>`. Después: `ALTER LOGIN sa DISABLE;` y la configuración TCP de la tabla en la clave `SuperSocketNetLib\Tcp` de la instancia (con reinicio del servicio).

### 8.2 Bases con los scripts de `docs/sql/preparacion`

Los tres scripts toman el servidor esperado de la variable sqlcmd `ServidorEsperado`; sin ella (por ejemplo, en SSMS fuera del modo SQLCMD) la comprobación de servidor falla y se detienen. `02` fija la compatibilidad 150 en cada base recién creada.

```powershell
cd docs\sql\preparacion
sqlcmd -S tcp:127.0.0.1,14330 -E -b -f 65001 -W -v ServidorEsperado="<EQUIPO>\PRODIGOSBASE" -i 01_verificacion_previa.sql
sqlcmd -S tcp:127.0.0.1,14330 -E -b -f 65001 -v ServidorEsperado="<EQUIPO>\PRODIGOSBASE" -i 02_crear_bases.sql
sqlcmd -S tcp:127.0.0.1,14330 -E -b -f 65001 -W -i 03_verificacion_posterior.sql
```

Comprobado: con `ServidorEsperado` incorrecto, `02` termina con 50101 sin crear nada; repetido tras crear las bases, termina con 50104.

### 8.3 Cuentas y conexión

Se siguen las secciones 3 a 5 del manual de instalación y la sección 3 de este documento con `-S tcp:127.0.0.1,14330`, `DB_HOST=127.0.0.1`, `DB_PORT=14330`, `ADM_DESARROLLO_SERVIDOR=<EQUIPO>\PRODIGOSBASE` y `ADM_PRUEBAS_SQLSRV_PORT=14330`. Notas prácticas:

- **Contraseñas:** se generan aleatoriamente y se guardan cifradas con DPAPI del usuario de Windows (`ConvertFrom-SecureString`) fuera del repositorio; se pasan a `sqlcmd` con `SQLCMDPASSWORD` o como variables de entorno del proceso, nunca en la línea de comandos ni en archivos del proyecto.
- **`sqlcmd -I`:** `sqlcmd` arranca con `QUOTED_IDENTIFIER` desactivado; las escrituras manuales sobre tablas con índices filtrados fallan con el error 1934 si no se añade `-I`.
- **Parámetro vacío en PowerShell 5.1:** `UsuarioAplicacion=""` se pierde al pasar a `sqlcmd` (error «Invalid argument»); ejecutar esa llamada con `cmd /c '<comando>'`.
- **`adm:crear-administrador`:** la petición oculta de Windows no admite la entrada redirigida; en una consola no interactiva usar `--leer-contrasena` (manual de instalación, 5.3, desde la Etapa 9).
- **Puerto de la aplicación:** si `127.0.0.1:8000` ya está en uso por otra aplicación, `php artisan serve` informa de que arrancó pero las peticiones llegan a la otra. Usar otro puerto (`--port=8010`) y ajustar `APP_URL`.
- **Varias pestañas con `php artisan serve`:** en Windows el servidor integrado atiende una conexión cada vez; al abrir una segunda pestaña, las conexiones anticipadas del navegador lo bloquean hasta unos 60 s. No es un defecto de la aplicación. Para la revisión, usar una pestaña por contexto y esperar a `domcontentloaded`.

### 8.4 Resultados (2026-11-13)

| Comprobación                       | Resultado                                                                                                                                                                            |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Bases                              | `prodigos_base_dev` y `prodigos_base_pruebas`: ONLINE, `Modern_Spanish_CI_AS`, compatibilidad 150, vacías al crearse                                                                 |
| Aislamiento                        | Cada cuenta solo accede a su base; ninguna tiene roles de servidor                                                                                                                   |
| `roles.sql`                        | Rechazo con `BaseEsperada` distinta (50005); dos ejecuciones en desarrollo (antes y después de migrar) y una en pruebas con `UsuarioAplicacion=""`                                   |
| `GuardaDdl`                        | Destino incorrecto (`migrate`, `--pretend`), `migrate:reset` y `db:wipe` sobre `prodigos_base_dev` (incluso con `ADM_PRUEBAS_DESTRUCTIVAS=prodigos_base_dev`): bloqueados, 0 tablas  |
| Migraciones                        | Nueve, con `adm_base_ddl`; 9 tablas `adm_*` y 9 filas en `dbo.migrations`                                                                                                            |
| Catálogo                           | 7 funcionalidades y 13 acciones; la segunda sincronización no cambia nada                                                                                                            |
| Administrador                      | Cuenta ficticia con cambio de contraseña obligatorio; auditoría con la contraseña oculta y sin el hash                                                                               |
| Permisos de `adm_base_app`         | Denegados: UPDATE y DELETE en `adm_auditoria`, DELETE en `adm_usuarios`, CREATE TABLE, ALTER, TRUNCATE y acceso a `prodigos_base_pruebas`. Permitidos: SELECT e INSERT               |
| Integración (`--testsuite=Sqlsrv`) | 1 prueba y 11 aserciones aprobadas; `prodigos_base_pruebas` vacía al terminar                                                                                                        |
| Navegador                          | Revisión completa de la sección 5 (detalle en 8.5). Consola sin errores ni advertencias tras las correcciones                                                                        |
| Sesiones                           | Dos sesiones independientes; revocación y deshabilitación expulsan solo la sesión afectada; el cierre de sesión en un navegador no afecta al otro                                    |
| Concurrencia                       | Membresías y permisos con datos desactualizados: aviso de conflicto sin cambios. Dos procesos simultáneos sobre el mismo grupo: uno guarda, el otro recibe el conflicto (dos rondas) |

### 8.5 Revisión en navegador (2026-11-13)

Playwright MCP 0.0.84 (Edge sin ventana), un único `php artisan serve` en `127.0.0.1:8010` con assets compilados (sin Vite), cuentas ficticias `admin.pruebas` y `usuario.pruebas` y un grupo ficticio «Consulta Pruebas». Las contraseñas se pasan al navegador como secretos con nombre (DPAPI) y nunca aparecen en respuestas ni capturas. Capturas en `storage/app/revision-visual` (no versionado).

| Área                         | Comprobado                                                                                                                                                                                            |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Acceso                       | Inicio de sesión correcto e incorrecto (mensaje genérico; la contraseña anterior deja de servir tras el restablecimiento); cierre de sesión; tras salir, `/dashboard` vuelve al inicio de sesión      |
| Cambio obligatorio           | Tras el alta y tras el restablecimiento por el administrador; otras rutas redirigen al cambio; confirmación distinta rechazada; después, Dashboard                                                    |
| Dashboard y navegación       | Siete módulos para el administrador; para un grupo con solo «Consultar membresías», solo Inicio y Usuarios y grupos (en modo consulta); `/admin/usuarios` y el `PUT` de membresías responden 403      |
| Usuarios y Mi Perfil         | Alta, validación de obligatorios, búsqueda, estado vacío, orden con `aria-sort`, edición, deshabilitar y habilitar con confirmación; Mi Perfil con correo inválido rechazado y datos guardados        |
| Grupos, Membresías, Permisos | Alta, nombre duplicado con otras mayúsculas rechazado, detalle con estados vacíos, asignaciones con confirmación de los cambios y aviso de conflicto                                                  |
| Sesiones Activas             | Lista con «Esta sesión», filtros, revocación con confirmación; la sesión revocada vuelve al inicio con «Su sesión fue finalizada»                                                                     |
| Auditoría y Seguimiento      | Filtros (acción, usuario, módulo), paginación, estados vacíos e indicadores; exportaciones CSV (con BOM) y XLSX que conservan los filtros y no contienen hashes                                       |
| Diseño                       | Escritorio 1366×768 y móvil 390×844: menú expandido, contraído con descripciones emergentes, buscador, migas, panel móvil (Esc lo cierra), tablas con desplazamiento propio y sin desbordar la página |
| Teclado y diálogos           | Tab y Mayús+Tab en orden lógico con foco visible; SweetAlert2 con foco inicial en Cancelar en las confirmaciones y Esc para cerrar                                                                    |
| Paleta alternativa           | `docs/ejemplos/paleta-ejemplo.css` inyectada en el navegador (sin tocar `tokens.css`): inicio de sesión, Dashboard y Usuarios con geometría idéntica a la paleta principal                            |

**Concurrencia simultánea:** `php artisan serve` atiende una petición cada vez, así que la simultaneidad real se probó con dos procesos PHP sincronizados que llaman a `AdministrarPermisos::sincronizar` con la misma base. El segundo espera el bloqueo del primero (unos 1.100 ms) y recibe el conflicto; queda una sola auditoría por ronda.

**Observaciones sin corregir en la Etapa 8.2 (resueltas en la 8.3, sección 8.6):** no hay páginas de error propias (los 403 muestran la página genérica en inglés de Laravel, también al deshabilitar una cuenta con la sesión abierta); no hay enlace «Saltar al contenido»; tras cerrar la alerta de validación, el foco vuelve a `body`; los contadores dicen «1 usuarios encontrados»; Membresías mezcla tuteo («Selecciona un usuario») con el trato de usted.

### 8.6 Validación final (Etapa 8.3, 2026-11-14)

| Comprobación                       | Resultado                                                                                                                                                                                                    |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Destino                            | `@@SERVERNAME` = `T01003PRODIGOSBASE`; solo `prodigos_base_dev` y `prodigos_base_pruebas`                                                                                                                    |
| Integración (`--testsuite=Sqlsrv`) | 1 prueba y 11 aserciones aprobadas con `adm_base_pruebas` (contraseña descifrada con DPAPI en la sesión, sin mostrarla); `prodigos_base_pruebas` sin objetos de usuario al terminar                          |
| Integridad (`prodigos_base_dev`)   | 9 claves foráneas y 4 CHECK habilitadas y confiables, ninguna en cascada; 0 membresías o permisos huérfanos, 0 membresías duplicadas; 1 administrador activo                                                 |
| Roles                              | `adm_aplicacion`: SELECT, INSERT y UPDATE por tabla, DELETE solo en las tablas de relación y DENY UPDATE y DELETE en `adm_auditoria`; `adm_base_app` y `adm_base_ddl` solo en su rol                         |
| Autorización                       | Prueba nueva: toda ruta exige `auth` y un middleware de autorización salvo la lista explícita de rutas propias                                                                                               |
| Navegador (focalizado)             | 403 en español (escritorio y móvil, sin desborde); «Saltar al contenido» como primer Tab y foco en `main`; foco en el primer campo con error tras la alerta; «1 usuario encontrado»; «Seleccione un usuario» |

Playwright solo revisó las cinco correcciones (cuentas ficticias, secretos con nombre, `127.0.0.1:8010`, assets compilados). La consola solo registró el 403 provocado a propósito.

## 9. Instalación limpia (Etapa 9)

**2026-11-14.** Validación de que la plantilla se instala y se reutiliza desde cero siguiendo solo el [manual de instalación](MANUAL_INSTALACION_Y_CONFIGURACION.md), sin datos ni credenciales del proyecto.

**Condiciones:** copia limpia del repositorio (`git clone`) en un directorio temporal, sin `.env`, `vendor` ni `node_modules`; instancia `T01003\PRODIGOSBASE`; base temporal `prodigos_base_instalacion_tmp` y logins `adm_inst_app` y `adm_inst_ddl` creados para la prueba, con contraseñas aleatorias cifradas con DPAPI; administrador ficticio `admin.instalacion`. `prodigos_base_dev` y `prodigos_base_pruebas` no se tocaron. Al terminar, tras comprobar servidor, fecha de creación, tablas y cuentas, se eliminaron la base, los logins, el clon y las credenciales temporales.

Se hicieron dos recorridos: el primero, sobre el commit `8e4c1fc`, sirvió para encontrar los problemas; el segundo, sobre el contenido final y con el manual reescrito, se completó sin intervenciones.

| Paso (manual)                  | Resultado del segundo recorrido                                                                                                                                                                             |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1–2. Requisitos y dependencias | `composer install` y `npm ci` según los lock; `npm run build` correcto incluso antes de crear `.env`                                                                                                        |
| 3. SQL Server                  | Base `Modern_Spanish_CI_AS` con compatibilidad 160; logins con contraseñas pasadas como variables de `sqlcmd`; `roles.sql` con acentos correctos; cuentas sin roles de servidor y con acceso solo a su base |
| 4. `.env`                      | `adm:entorno`: destino coherente, escritura `HABILITADA`, migraciones `permitidas`                                                                                                                          |
| 5. Esquema y datos iniciales   | Nueve migraciones con la cuenta de despliegue; segunda ejecución de `roles.sql` con permisos en las nueve tablas; 7 funcionalidades; administrador creado con `--leer-contrasena`; 24 auditorías sin hashes |
| 6. Ejecución local             | Por HTTP: contraseña incorrecta rechazada; contraseña temporal → cambio obligatorio → Dashboard; Administración de Usuarios; cierre de sesión; nueva contraseña → Dashboard; 404 en español                 |
| 7. Verificaciones              | PHPUnit 172 pruebas (171 aprobadas, 1 omitida), Pint, Larastan, `test:js` 14/14, `check` y `types:check` en la copia limpia                                                                                 |

**Reutilización** (en el clon, sin incorporarla a la plantilla): `APP_NAME` y `ADM_APP_DESCRIPCION` nuevos, visibles en el inicio de sesión y compartidos con la interfaz; paleta sustituida en `tokens.css` y compilada; funcionalidad ficticia `operaciones.inventario.articulos` en un área nueva, con su ruta, su permiso y una pantalla. La sincronización la auditó (1 funcionalidad y 2 acciones). El administrador la vio en el menú y el Dashboard y la abrió; una cuenta ficticia sin permisos no la vio y recibió 403. La suite de la plantilla pasó con el área añadida.

**Problemas encontrados y corregidos:**

| Problema                                                                                                                                                                                    | Corrección                                                                                                  |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| El manual ejecutaba `roles.sql` sin `-f 65001`: los mensajes salían como «aÃºn»                                                                                                             | `-f 65001` en todos los ejemplos                                                                            |
| `adm:crear-administrador` no se puede usar sin una consola interactiva: con la entrada redirigida, la confirmación nunca coincide                                                           | Opción `--leer-contrasena`: una línea de la entrada estándar, sin argumentos en la línea de comandos ni eco |
| PowerShell 5.1 antepone la marca BOM al canalizar texto y la contraseña guardada no coincidía                                                                                               | `--leer-contrasena` descarta la marca BOM inicial (prueba en `CrearAdministradorTest`)                      |
| Las pruebas de la plantilla fallaban en un proyecto derivado con un área nueva (exigían que todo el catálogo fuera `administracion` y en primera posición)                                  | Localizan el área `administracion` por su nombre y comprueban solo sus funcionalidades                      |
| `composer setup` usaba `npm install`, que puede reescribir el lock                                                                                                                          | `npm ci`                                                                                                    |
| Instalación repartida entre el manual y este documento, con pasos distintos, un ejemplo con la contraseña en la consola (`ARQUITECTURA_SQL_SERVER.md`) y un ejemplo de `.mcp.json` obsoleto | Procedimiento único en el manual; este documento y la arquitectura enlazan a él                             |
| El `.env` de la prueba exige `DB_PASSWORD`: es lo esperado (`.env` no se versiona y se borró con el clon)                                                                                   | Sin cambios                                                                                                 |

**Observaciones del entorno:** el límite de 5 intentos de inicio de sesión por minuto respondió 429 al repetir la verificación con contraseñas mal enviadas (PowerShell 5.1 no codifica `+` ni `%` en un cuerpo de formulario en hashtable); es el comportamiento esperado. La publicación en IIS no se probó (sección 9 del manual).
