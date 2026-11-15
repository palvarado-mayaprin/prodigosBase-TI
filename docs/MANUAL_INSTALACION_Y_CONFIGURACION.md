# Manual de instalación y configuración

Procedimiento único para instalar prodigosBase-TI desde un repositorio limpio. Se validó el 2026-11-14 desde una copia limpia del repositorio, con una base y cuentas nuevas (resultados en la [sección 9 de VALIDACION_SQL_SERVER.md](VALIDACION_SQL_SERVER.md#9-instalación-limpia-etapa-9)).

Los valores entre `<>` se sustituyen al ejecutar. Los nombres de base y de login son ejemplos; si se cambian, se cambian en todos los pasos. **Las contraseñas nunca se escriben en archivos versionados, documentos ni en la línea de comandos**: se piden ocultas con `Read-Host -AsSecureString` y viven solo en variables de la consola.

Otros documentos: arquitectura de la aplicación en [MANUAL_TECNICO.md](MANUAL_TECNICO.md), modelo de datos y roles en [ARQUITECTURA_SQL_SERVER.md](ARQUITECTURA_SQL_SERVER.md), lenguaje visual en [GUIA_DISENO_UI.md](GUIA_DISENO_UI.md) y pruebas sobre SQL Server real y en navegador en [VALIDACION_SQL_SERVER.md](VALIDACION_SQL_SERVER.md).

## 1. Requisitos

| Software                             | Requisito                          | Verificado                   | Notas                                                                                                                        |
| ------------------------------------ | ---------------------------------- | ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| Windows                              | 10/11 o Windows Server 2019+       | Windows 11 Pro               | Los comandos de este manual son de PowerShell 5.1                                                                            |
| PHP                                  | 8.4 NTS x64 (`^8.4.1`)             | 8.4.26                       | Extensiones `pdo_sqlsrv`, `sqlsrv`, `mbstring`, `openssl`, `fileinfo`, `intl`, `zip`, `curl`; `pdo_sqlite` solo para pruebas |
| Composer                             | 2                                  | 2.10.3                       |                                                                                                                              |
| Node.js y npm                        | Node 24, npm 11                    | 24.21, 11.19                 | `.npmrc` desactiva los scripts de instalación (`ignore-scripts=true`)                                                        |
| Git                                  | 2                                  | 2.51                         |                                                                                                                              |
| Microsoft ODBC Driver for SQL Server | 17 o 18                            | 18                           | Con el 18, revisar `DB_ENCRYPT` y `DB_TRUST_SERVER_CERTIFICATE`                                                              |
| `sqlcmd`                             | Cualquier versión                  | 16.0                         | Para `roles.sql` y las comprobaciones                                                                                        |
| SQL Server                           | 2016 o superior, cualquier edición | 2022 Developer (16.0.1000.6) | Compatibilidad de la base 130 o superior; detalle en [ARQUITECTURA_SQL_SERVER.md](ARQUITECTURA_SQL_SERVER.md#1-principios)   |

```powershell
php -v
php -m | Select-String 'sqlsrv|intl|zip|sqlite'
composer -V; node -v; npm -v; git --version; sqlcmd -?
```

## 2. Obtener el proyecto e instalar dependencias

```powershell
git clone <url-del-repositorio> prodigosBase-TI
cd prodigosBase-TI
composer install
npm ci            # exactamente lo de package-lock.json
npm run build     # genera public/build (lo exigen las pantallas y las páginas de error)
```

`composer run setup` instala y compila, pero **no** ejecuta migraciones: el esquema se crea de forma controlada (sección 5).

## 3. Preparar SQL Server

Lo hace quien administre el servidor (DBA), o el propio desarrollador en su instancia local, con una cuenta que pueda crear bases y logins (`sysadmin`, o `dbcreator` más `securityadmin`). Para montar una instancia local dedicada (SQL Server 2022 Developer, TCP solo en `127.0.0.1`), ver la [sección 8 de VALIDACION_SQL_SERVER.md](VALIDACION_SQL_SERVER.md#8-instancia-local-dedicada-etapa-82), que incluye los scripts de `docs/sql/preparacion`.

Se usan dos cuentas separadas: la de la **aplicación** (va en `.env`, solo DML acotado) y la de **despliegue** (solo en la consola de migraciones, DDL).

### 3.1 Comprobaciones previas

```sql
SELECT @@SERVERNAME AS servidor,                                    -- valor para ADM_DESARROLLO_SERVIDOR
       SERVERPROPERTY('ProductVersion') AS version,                 -- 13.x (2016) o superior
       SERVERPROPERTY('IsIntegratedSecurityOnly') AS solo_windows;  -- 0 para usar logins SQL
SELECT name FROM sys.databases WHERE name = N'prodigos_base_dev';                        -- 0 filas
SELECT name FROM sys.server_principals WHERE name IN (N'adm_base_app', N'adm_base_ddl'); -- 0 filas
```

Si alguna consulta devuelve filas, detenerse: no se reutilizan bases ni logins existentes.

### 3.2 Base, logins y usuarios

Las contraseñas se pasan como variables de entorno, que `sqlcmd` usa como variables de script (`$(CLAVE_APP)`), sin escribirlas en el archivo ni en la línea de comandos:

```powershell
$env:CLAVE_APP = [Net.NetworkCredential]::new('', (Read-Host 'Contraseña de adm_base_app' -AsSecureString)).Password
$env:CLAVE_DDL = [Net.NetworkCredential]::new('', (Read-Host 'Contraseña de adm_base_ddl' -AsSecureString)).Password
sqlcmd -S <servidor> -E -b -f 65001 -i base_y_logins.sql
Remove-Item Env:CLAVE_APP, Env:CLAVE_DDL
```

Con `base_y_logins.sql` (fuera del repositorio o en una carpeta temporal):

```sql
CREATE DATABASE prodigos_base_dev COLLATE Modern_Spanish_CI_AS;
GO
SELECT name, compatibility_level, collation_name FROM sys.databases WHERE name = N'prodigos_base_dev'; -- nivel >= 130
CREATE LOGIN adm_base_app WITH PASSWORD = N'$(CLAVE_APP)', DEFAULT_DATABASE = prodigos_base_dev, CHECK_POLICY = ON;
CREATE LOGIN adm_base_ddl WITH PASSWORD = N'$(CLAVE_DDL)', DEFAULT_DATABASE = prodigos_base_dev, CHECK_POLICY = ON;
GO
USE prodigos_base_dev;
CREATE USER adm_base_app FOR LOGIN adm_base_app;
CREATE USER adm_base_ddl FOR LOGIN adm_base_ddl;
```

Se recomienda una intercalación `*_CI_AS` (los nombres de usuario y de grupo son únicos sin distinguir mayúsculas). Con autenticación de Windows, sustituir los logins por `CREATE LOGIN [DOMINIO\cuenta] FROM WINDOWS` con la misma separación.

### 3.3 Roles (primera ejecución)

Desde la raíz del proyecto. `-d` fija la base y `BaseEsperada` debe coincidir con ella: si falta o no coincide, el script se detiene sin cambios. `-b` hace que `sqlcmd` termine con error y `-f 65001` muestra bien los acentos.

```powershell
sqlcmd -S <servidor> -d prodigos_base_dev -E -b -f 65001 -i database\sql\roles.sql `
       -v BaseEsperada="prodigos_base_dev" UsuarioAplicacion="adm_base_app" UsuarioDespliegue="adm_base_ddl"
```

Esperado: nueve avisos «Omitida (aún no existe…)» (todavía no hay tablas) y «Roles adm_aplicacion y adm_despliegue actualizados.». Los permisos de cada rol están en [ARQUITECTURA_SQL_SERVER.md](ARQUITECTURA_SQL_SERVER.md#4-permisos-sql-databasesqlrolessql).

### 3.4 Aislamiento de las cuentas

Ninguna cuenta debe tener roles de servidor ni acceso a otra base:

```sql
SELECT l.name, r.name AS rol_servidor
FROM sys.server_role_members m
JOIN sys.server_principals r ON r.principal_id = m.role_principal_id
JOIN sys.server_principals l ON l.principal_id = m.member_principal_id
WHERE l.name IN (N'adm_base_app', N'adm_base_ddl');   -- 0 filas
```

```powershell
# sqlcmd pide la contraseña al omitir -P. Esperado: solo prodigos_base_dev.
sqlcmd -S <servidor> -U adm_base_app -d prodigos_base_dev -b -Q "SET NOCOUNT ON; SELECT name FROM sys.databases WHERE database_id > 4 AND HAS_DBACCESS(name) = 1;"
```

## 4. Configurar `.env`

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

`.env` no se versiona. Editarlo con la cuenta de la aplicación:

| Variable                                            | Valor                                                      | Uso                                                                              |
| --------------------------------------------------- | ---------------------------------------------------------- | -------------------------------------------------------------------------------- |
| `APP_URL`                                           | `http://127.0.0.1:8000` (o el puerto que se use)           | URL de la aplicación                                                             |
| `DB_CONNECTION`                                     | `sqlsrv`                                                   | Motor obligatorio                                                                |
| `DB_HOST`, `DB_PORT`                                | `<servidor>`, `1433`                                       | Servidor SQL Server                                                              |
| `DB_DATABASE`                                       | `prodigos_base_dev`                                        | Base de desarrollo                                                               |
| `DB_USERNAME`, `DB_PASSWORD`                        | `adm_base_app` y su contraseña                             | Solo la cuenta de la aplicación                                                  |
| `DB_URL`                                            | vacío                                                      | Debe permanecer vacío                                                            |
| `DB_ENCRYPT`, `DB_TRUST_SERVER_CERTIFICATE`         | comentadas                                                 | Según el controlador ODBC y el certificado del servidor                          |
| `ADM_ENTORNO`                                       | `desarrollo`                                               | Destino seleccionado                                                             |
| `ADM_DESARROLLO_SERVIDOR`, `ADM_DESARROLLO_BD`      | valor exacto de `@@SERVERNAME` (3.1) y `prodigos_base_dev` | Destino autorizado de desarrollo                                                 |
| `ADM_PRODUCCION_SERVIDOR`, `ADM_PRODUCCION_BD`      | vacíos en un equipo de desarrollo                          | Destino autorizado de producción                                                 |
| `ADM_PRODUCCION_ESCRITURA`                          | `false`                                                    | Único interruptor de escritura en producción (sin efecto en desarrollo)          |
| `ADM_REDES_CORPORATIVAS`                            | vacío en desarrollo                                        | Con `APP_ENV=production`, redes CIDR admitidas; vacío rechaza todo               |
| `APP_NAME`, `ADM_APP_DESCRIPCION`                   | nombre y descripción de la aplicación                      | Inicio de sesión, menú, Dashboard y páginas de error (sección 8)                 |
| `ADM_ZONA_HORARIA`                                  | `UTC` o p. ej. `America/Guatemala`                         | Zona en que se muestran y filtran las fechas (se guardan en UTC). No válida: UTC |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `file`, `file`, `sync`                                     | No requieren tablas en SQL Server                                                |

Verificar:

```powershell
php artisan config:clear
php artisan adm:entorno
```

`adm:entorno` es de solo lectura y no muestra credenciales. Esperado: base y servidor reales iguales a los esperados, escritura `HABILITADA`, migraciones `permitidas` y «Destino coherente con el entorno seleccionado.». Si termina con error, no continuar: el motivo aparece en la tabla.

### 4.1 Control centralizado de entornos

`App\Entorno\DestinoSql` es la única autoridad sobre el destino SQL Server y la escritura (`config/entorno.php`); el middleware `ControlarAcceso` lo aplica a todo el grupo `web`.

- `ADM_ENTORNO` debe coincidir con `DB_DATABASE`; si no, **toda** la aplicación responde 403 «Base de datos no permitida.».
- Desarrollo y producción no pueden ser el mismo servidor y base. Servidor y base se comparan sin distinguir mayúsculas.
- Toda petición que no sea GET, HEAD u OPTIONS exige escritura permitida: destino configurado y real (`DB_NAME()` y `@@SERVERNAME`) coincidentes; en producción, además, `ADM_PRODUCCION_ESCRITURA=true` y, con `APP_ENV=production`, `APP_DEBUG=false`. Solo el inicio y el cierre de sesión están exentos.
- `APP_ENV` gobierna Laravel (origen, CSRF, errores), pero no autoriza ni impide escribir en producción.
- `DB_URL` debe quedar vacío y el driver ser `sqlsrv`.

**Cambiar de entorno** nunca requiere modificar código: respaldar `.env`; editar solo `ADM_ENTORNO`, `DB_DATABASE`, las credenciales `DB_*` y, para escribir en producción, `ADM_PRODUCCION_ESCRITURA=true`; ejecutar `php artisan config:clear` y `php artisan adm:entorno` (si falla, restaurar el respaldo). En IIS, además, `php artisan optimize` y reciclar el grupo de aplicaciones.

## 5. Esquema y datos iniciales

### 5.1 Migraciones (cuenta de despliegue)

En una consola PowerShell nueva, desde la raíz del proyecto. Las variables de proceso prevalecen sobre `.env` y desaparecen al cerrar la consola. No usar `config:cache` antes: la configuración en caché ignora estas variables.

```powershell
php artisan config:clear
$env:DB_USERNAME = 'adm_base_ddl'
$env:DB_PASSWORD = [Net.NetworkCredential]::new('', (Read-Host 'Contraseña de adm_base_ddl' -AsSecureString)).Password
php artisan adm:entorno         # misma verificación, con la cuenta de despliegue
php artisan migrate --pretend   # muestra el DDL; con el destino autorizado crea dbo.migrations (vacía)
php artisan migrate             # esperado: nueve migraciones DONE
Remove-Item Env:DB_USERNAME, Env:DB_PASSWORD
```

`GuardaDdl` detiene el comando antes de escribir nada si el destino real no coincide con `ADM_ENTORNO`. En producción exige además `$env:ADM_DESPLIEGUE_DDL = '<base de producción>'` en esa misma consola (nunca en `.env`). No usar `--force` en desarrollo.

### 5.2 Roles (segunda ejecución)

Repetir el comando de la sección 3.3. Ahora concede los permisos de la aplicación sobre las nueve tablas `adm_*` y deniega `UPDATE` y `DELETE` en `adm_auditoria`. Esperado: «Permisos … sobre dbo.adm_…» nueve veces.

```sql
SELECT name FROM sys.tables WHERE name LIKE N'adm[_]%' ORDER BY name;   -- 9 tablas
SELECT migration FROM dbo.migrations ORDER BY id;                        -- 9 migraciones
```

### 5.3 Catálogo de permisos y primer administrador (cuenta de la aplicación)

En una consola sin las variables de la sección 5.1 (usa la cuenta de `.env`):

```powershell
php artisan config:clear
php artisan adm:sincronizar-permisos
php artisan adm:crear-administrador --usuario=<usuario> --nombre="<Nombre>" --apellidos="<Apellidos>"
```

- `adm:sincronizar-permisos` carga el catálogo (7 funcionalidades y 13 acciones en la plantilla). Es idempotente; nunca borra ni asigna permisos a grupos; lo que sale del catálogo queda inactivo.
- `adm:crear-administrador` pide la contraseña temporal oculta y dos veces (12 a 72 caracteres, con mayúsculas, minúsculas, números y símbolos). Crea el grupo `Administradores` (`es_administrador = 1`) si no existe, la cuenta y su membresía en una sola transacción auditada, sin duplicados.
- **Instalación desatendida:** en Windows la petición oculta no funciona con la entrada redirigida. `--leer-contrasena` lee la contraseña de una única línea de la entrada estándar (sin confirmación, sin mostrarla y descartando la marca BOM que añade PowerShell 5.1):

    ```powershell
    $clave = [Net.NetworkCredential]::new('', (Read-Host 'Contraseña temporal' -AsSecureString)).Password
    $clave | php artisan adm:crear-administrador --usuario=<usuario> --nombre="<Nombre>" --apellidos="<Apellidos>" --leer-contrasena
    $clave = $null
    ```

- Al iniciar sesión, la cuenta debe cambiar la contraseña antes de usar la aplicación.

Comprobación:

```sql
SELECT codigo, activo FROM adm_funcionalidades ORDER BY codigo;
SELECT u.usuario, u.debe_cambiar_password, g.nombre, g.es_administrador
FROM adm_usuarios u JOIN adm_usuarios_grupos ug ON ug.usuario_id = u.id JOIN adm_grupos g ON g.id = ug.grupo_id;
SELECT entidad, accion, fecha_utc FROM adm_auditoria ORDER BY id;   -- sin contraseñas ni hashes
```

## 6. Ejecución local

```powershell
php artisan serve --host=127.0.0.1 --port=8000   # con los assets compilados (sección 2)
# o bien, para desarrollar con recarga en caliente:
composer run dev                                  # servidor de Laravel y Vite
```

Abrir `APP_URL`, iniciar sesión con el administrador y cambiar la contraseña temporal; después se muestra el Dashboard. Solo se admiten conexiones desde el propio equipo (`127.0.0.1` o `::1`). Mientras los destinos `ADM_*` no sean coherentes, toda la aplicación responde 403 «Base de datos no permitida.»: es el comportamiento seguro por defecto.

## 7. Verificaciones y pruebas

```powershell
composer test          # Pint, Larastan y PHPUnit (SQLite en memoria; nunca se conecta a SQL Server)
npm run test:js        # lógica del frontend (node --test)
npm run check          # formato y lint (vite-plus: oxfmt y oxlint)
npm run types:check    # TypeScript (vue-tsc)
npm run build          # compilación de producción
```

Resultado esperado (Fase 4, intervención 4.1): PHPUnit con 175 pruebas, 174 aprobadas y 1 omitida (la integración con SQL Server), `test:js` 14/14 y el resto sin errores. Las mismas comprobaciones se ejecutan en GitHub Actions (`.github/workflows/tests.yml`).

- **SQL Server real:** la suite `Sqlsrv` se ejecuta aparte, sobre una base dedicada `_pruebas`: [VALIDACION_SQL_SERVER.md, sección 3](VALIDACION_SQL_SERVER.md#3-prueba-de-integración-sql-server).
- **Navegador:** revisión con Playwright MCP: [VALIDACION_SQL_SERVER.md, sección 5](VALIDACION_SQL_SERVER.md#5-revisión-en-navegador-con-playwright-mcp).
- **Funcional:** con el primer administrador, recorrer la tabla siguiente con cuentas ficticias.

| Módulo                      | Ruta                           | Comprobación                                                                                                                                                                                                                                                                                           |
| --------------------------- | ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Administración de Usuarios  | `/admin/usuarios`              | Crear una cuenta de prueba; editarla; restablecerle la contraseña (queda «Cambio de contraseña pendiente»); deshabilitarla y comprobar que no puede iniciar sesión; habilitarla. El botón de deshabilitar la propia cuenta está desactivado. Cada operación aparece en `adm_auditoria` sin contraseñas |
| Mi Perfil                   | `/mi-perfil` (menú de usuario) | Cambiar correo y teléfono; cambiar la contraseña con la actual. Nombre y usuario son de solo lectura                                                                                                                                                                                                   |
| Administración de Grupos    | `/admin/grupos`                | Crear un grupo de prueba; editar su nombre y descripción; consultar su detalle; desactivarlo y activarlo. Desactivar el único grupo administrador propio se rechaza                                                                                                                                    |
| Usuarios y grupos           | `/admin/membresias`            | En Usuarios → Grupos, asignar y retirar el grupo de prueba a una cuenta (confirmación con el detalle). Retirarse a sí mismo el grupo administrador se rechaza. En Grupos → Usuarios, consultar los integrantes                                                                                         |
| Administración de Permisos  | `/admin/permisos`              | Asignar a un grupo no administrador `Usuarios y grupos · Consultar membresías` y comprobar que sus integrantes abren `/admin/membresias` en modo consulta; retirarlo. Las funcionalidades «Solo administrador» no se pueden marcar                                                                     |
| Sesiones Activas            | `/admin/sesiones`              | Iniciar sesión con una cuenta de prueba en otro navegador; verla como «Activa»; revocarla y comprobar que su siguiente acción vuelve al inicio de sesión. La propia sesión aparece como «Esta sesión» y no se puede revocar                                                                            |
| Auditoría de Modificaciones | `/admin/auditoria`             | Filtrar por usuario, acción y fechas; abrir el detalle de una edición (contraseñas como `********`); exportar CSV y Excel con los filtros aplicados                                                                                                                                                    |
| Seguimiento de Uso          | `/admin/seguimiento`           | Navegar unos minutos con otra cuenta; comprobar visitas y tiempo activo por usuario, módulo y día; exportar los cuatro reportes. Las fechas usan `ADM_ZONA_HORARIA`                                                                                                                                    |
| Navegación y Dashboard      | `/dashboard` y menú lateral    | El administrador ve los siete módulos; contraer el menú deja los iconos; el buscador filtra. Una cuenta con solo «Consultar membresías» ve únicamente Usuarios y grupos y recibe 403 (página en español) en los demás módulos                                                                          |

**Solo lectura.** Con la producción en solo lectura (`ADM_PRODUCCION_ESCRITURA=false`) se puede iniciar sesión y consultar, pero no se registran sesiones (`adm_sesiones`) ni uso (`adm_actividad`): esas sesiones no aparecen en Sesiones Activas ni se pueden revocar hasta que la escritura se habilite. Auditoría y Seguimiento se consultan y exportan con normalidad.

## 8. Personalización

- **Nombre y descripción:** `APP_NAME` y `ADM_APP_DESCRIPCION` en `.env`, seguidos de `php artisan config:clear` (en IIS, `php artisan optimize`). Aparecen en el inicio de sesión, el menú, el Dashboard, los títulos y las páginas de error.
- **Paleta:** solo `resources/css/tema/tokens.css` (variables `--adm-*`), conservando todos sus nombres; `docs/ejemplos/paleta-ejemplo.css` es una segunda paleta completa de referencia. Después, `npm run build` y `php artisan test --filter=PaletaTest`. Medidas, tipografía y estructura no cambian. Procedimiento y contraste mínimo: [GUIA_DISENO_UI.md, sección 9](GUIA_DISENO_UI.md#9-cambiar-la-paleta).
- **Funcionalidades nuevas:** se declaran en el catálogo y aparecen solas en el menú y el Dashboard: [MANUAL_TECNICO.md, sección 4.1](MANUAL_TECNICO.md#41-registrar-una-funcionalidad-nueva).

## 9. Preparación para IIS

_Pendiente de validar en un servidor IIS (Fase 4)._ Lo que la plantilla ya exige y lo previsto:

1. **Servidor:** IIS con URL Rewrite 2.1 y FastCGI con PHP 8.4 NTS x64 (mismas extensiones de la sección 1) y el controlador ODBC.
2. **Código:** copia limpia del repositorio; `composer install --no-dev --optimize-autoloader`; `npm ci` y `npm run build` (en el equipo de compilación; al servidor solo hace falta `public/build`).
3. **Sitio:** apuntar la raíz a `public`; copiar [docs/ejemplos/web.config](ejemplos/web.config) a `public\web.config` (reescritura a `index.php` y páginas de error de Laravel). Dar permiso de modificación a la identidad del grupo de aplicaciones sobre `storage` y `bootstrap\cache`, y solo lectura al resto.
4. **`.env` de producción:** `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` con HTTPS, `SESSION_SECURE_COOKIE=true`, `ADM_ENTORNO=produccion`, `ADM_PRODUCCION_SERVIDOR`, `ADM_PRODUCCION_BD`, `ADM_PRODUCCION_ESCRITURA` según lo autorizado y `ADM_REDES_CORPORATIVAS` con las redes admitidas. Cuenta de la aplicación con el rol `adm_aplicacion` solamente.
5. **Esquema:** desde la consola de despliegue, secciones 5.1 y 5.2 con `$env:ADM_DESPLIEGUE_DDL = '<base de producción>'`; después, sección 5.3.
6. **Caché:** `php artisan optimize` tras cada cambio de `.env` o de código, y reciclar el grupo de aplicaciones. `php artisan adm:entorno` debe terminar sin error.

## 10. Restricciones de entornos y operaciones destructivas

Las migraciones tienen una autorización propia (`App\Entorno\GuardaDdl`), independiente del interruptor de escritura:

- siempre exigen que el destino real coincida con `ADM_ENTORNO`; se comprueban al iniciar el comando, antes de cualquier escritura (también con `--pretend`);
- en producción exigen además la variable de proceso `ADM_DESPLIEGUE_DDL` con el nombre exacto de la base de producción, solo en la consola de despliegue y nunca en `.env`;
- `ADM_PRODUCCION_ESCRITURA` nunca habilita DDL;
- `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` y `migrate:rollback` solo se permiten en una base de desarrollo cuyo nombre termine en `_pruebas` y con la variable de proceso `ADM_PRUEBAS_DESTRUCTIVAS` igual a esa base; nunca en producción ni en la base normal de desarrollo. Una base que deba eliminarse por completo la elimina el DBA con `DROP DATABASE`, después de comprobar servidor, nombre y contenido.

Más detalle en [ARQUITECTURA_SQL_SERVER.md, sección 5](ARQUITECTURA_SQL_SERVER.md#5-control-de-migraciones).

## 11. Problemas frecuentes

| Síntoma                                                                                            | Causa                                                                                   | Solución                                                                                   |
| -------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Todo responde 403 «Base de datos no permitida.»                                                    | `ADM_ENTORNO`, `ADM_*_SERVIDOR`, `ADM_*_BD` o `DB_DATABASE` incoherentes                | `php artisan adm:entorno` muestra el motivo                                                |
| 403 «Origen no autorizado.»                                                                        | En local, acceso desde otro equipo; en producción, IP fuera de `ADM_REDES_CORPORATIVAS` | Acceder desde el propio equipo o ajustar las redes                                         |
| 403 «La base productiva solo permite consultas.» al guardar                                        | `ADM_PRODUCCION_ESCRITURA=false`                                                        | Comportamiento esperado; habilitar solo con autorización                                   |
| `adm:entorno`: «Error de conexión»                                                                 | Servidor, puerto, credenciales o certificado ODBC                                       | Revisar `DB_*`, `DB_ENCRYPT` y `DB_TRUST_SERVER_CERTIFICATE`                               |
| Cambios en `.env` sin efecto                                                                       | Configuración en caché                                                                  | `php artisan config:clear`                                                                 |
| `sqlcmd` muestra «aÃºn» en lugar de «aún»                                                          | Falta `-f 65001` (los scripts están en UTF-8)                                           | Añadir `-f 65001`                                                                          |
| Escritura manual con `sqlcmd` falla con el error 1934                                              | `sqlcmd` arranca con `QUOTED_IDENTIFIER` desactivado (índices filtrados)                | Añadir `-I`                                                                                |
| `UsuarioAplicacion=""` da «Invalid argument» en PowerShell 5.1                                     | PowerShell pierde el parámetro vacío                                                    | Ejecutar esa llamada con `cmd /c '<comando>'`                                              |
| `adm:crear-administrador` con la entrada redirigida: «La confirmación … no coincide»               | La petición oculta de Windows no admite entrada redirigida                              | Consola interactiva, o `--leer-contrasena` (sección 5.3)                                   |
| El inicio de sesión responde 429 o «Demasiados intentos»                                           | 5 intentos por minuto por usuario e IP                                                  | Esperar un minuto                                                                          |
| Tras iniciar sesión siempre se vuelve a «Cambiar contraseña»                                       | La cuenta tiene `debe_cambiar_password = 1`                                             | Cambiar la contraseña o cerrar la sesión                                                   |
| `php artisan serve` dice que arrancó, pero responde otra aplicación                                | El puerto ya está en uso                                                                | Otro puerto (`--port=8010`) y ajustar `APP_URL`                                            |
| Con `php artisan serve`, una segunda pestaña deja el servidor bloqueado hasta un minuto            | El servidor integrado atiende una conexión cada vez en Windows                          | Limitación del servidor de desarrollo; no ocurre en IIS                                    |
| Larastan: «reached configured PHP memory limit» o «Cannot redeclare class ComposerAutoloaderInit…» | Límite de memoria u OPcache de la CLI compartido entre procesos                         | Usar `composer types:check`, que ya fija `--memory-limit=512M` y `-d opcache.enable_cli=0` |
| La primera compilación tarda cerca de un minuto                                                    | Wayfinder genera las rutas tipadas                                                      | Normal                                                                                     |
