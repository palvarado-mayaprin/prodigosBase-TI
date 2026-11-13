# Validación integral en SQL Server (Etapa 8)

Procedimiento para crear un entorno SQL Server de desarrollo **propio de prodigosBase-TI** y validar en él el esquema, los roles, las pruebas de integración y la aplicación. Preparado en la Etapa 8.1; **nada de esto se ha ejecutado todavía**. Requiere autorización expresa (sección 7).

Los valores entre `<>` se sustituyen al ejecutar. Los nombres de base y de login son los propuestos; si se cambian, se cambian en todos los pasos. Las contraseñas nunca se escriben en archivos, documentos ni en el historial de la consola.

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

PHP 8.4.1 o superior (lo exige `composer.lock`) con `pdo_sqlsrv` y `sqlsrv`, Microsoft ODBC Driver 17 o 18 y `sqlcmd`. Comprobación:

```powershell
php -v
php -m | Select-String sqlsrv     # pdo_sqlsrv y sqlsrv
sqlcmd -?                         # cualquier versión
```

## 2. Instalación de la base de desarrollo

### 2.1 Comprobaciones previas (DBA)

Con una cuenta que pueda crear bases y logins (`sysadmin`, o `dbcreator` más `securityadmin`):

```sql
SELECT @@SERVERNAME AS servidor,                                   -- valor para ADM_DESARROLLO_SERVIDOR
       SERVERPROPERTY('ProductVersion') AS version,                -- 13.x (2016) o superior
       SERVERPROPERTY('IsIntegratedSecurityOnly') AS solo_windows; -- 0 para usar logins SQL
SELECT name FROM sys.databases
WHERE name IN (N'prodigos_base_dev', N'prodigos_base_pruebas');   -- 0 filas: los nombres están libres
SELECT name FROM sys.server_principals
WHERE name IN (N'adm_base_app', N'adm_base_ddl', N'adm_base_pruebas'); -- 0 filas
```

Si alguna consulta devuelve filas, detenerse: no se reutilizan bases ni logins existentes.

### 2.2 Base y logins (DBA)

```sql
USE master;
CREATE DATABASE prodigos_base_dev COLLATE Modern_Spanish_CI_AS;
GO
SELECT name, compatibility_level, collation_name
FROM sys.databases WHERE name = N'prodigos_base_dev';   -- nivel >= 130; Modern_Spanish_CI_AS
GO
CREATE LOGIN adm_base_app WITH PASSWORD = N'<contraseña segura>', DEFAULT_DATABASE = prodigos_base_dev, CHECK_POLICY = ON;
CREATE LOGIN adm_base_ddl WITH PASSWORD = N'<contraseña segura>', DEFAULT_DATABASE = prodigos_base_dev, CHECK_POLICY = ON;
GO
USE prodigos_base_dev;
CREATE USER adm_base_app FOR LOGIN adm_base_app;
CREATE USER adm_base_ddl FOR LOGIN adm_base_ddl;
GO
```

Si el nivel de compatibilidad fuera menor que 130: `ALTER DATABASE prodigos_base_dev SET COMPATIBILITY_LEVEL = 130;`.

### 2.3 Roles (primera ejecución)

Desde la raíz del proyecto, con la cuenta del DBA (`-E` es autenticación de Windows). `-b` hace que `sqlcmd` termine con error ante cualquier fallo, y `-d` fija la base: **comprobar el nombre antes de pulsar Intro**.

```powershell
sqlcmd -S <servidor> -d prodigos_base_dev -E -b -i database\sql\roles.sql `
       -v UsuarioAplicacion="adm_base_app" UsuarioDespliegue="adm_base_ddl"
```

Esperado: nueve avisos «Omitida (aún no existe…)» y «Roles adm_aplicacion y adm_despliegue actualizados.».

### 2.4 Aislamiento de las cuentas

Como DBA, las cuentas no tienen roles de servidor:

```sql
SELECT l.name AS login, r.name AS rol_servidor
FROM sys.server_role_members m
JOIN sys.server_principals r ON r.principal_id = m.role_principal_id
JOIN sys.server_principals l ON l.principal_id = m.member_principal_id
WHERE l.name IN (N'adm_base_app', N'adm_base_ddl');     -- 0 filas
```

Con cada cuenta (`sqlcmd` pide la contraseña al omitir `-P`):

```powershell
sqlcmd -S <servidor> -U adm_base_app -d prodigos_base_dev -b -Q "SET NOCOUNT ON; SELECT name FROM sys.databases WHERE database_id > 4 AND HAS_DBACCESS(name) = 1;"
sqlcmd -S <servidor> -U adm_base_ddl -d prodigos_base_dev -b -Q "SET NOCOUNT ON; SELECT name FROM sys.databases WHERE database_id > 4 AND HAS_DBACCESS(name) = 1;"
```

Esperado en ambas: **solo** `prodigos_base_dev`. Si aparece cualquier otra base (por ejemplo, por un usuario `guest` habilitado en ella), detenerse y avisar al DBA.

### 2.5 Configurar `.env`

Lo edita la persona responsable del equipo (respaldar antes el archivo):

| Variable                                       | Valor                                                   |
| ---------------------------------------------- | ------------------------------------------------------- |
| `DB_CONNECTION`                                | `sqlsrv`                                                |
| `DB_HOST`, `DB_PORT`                           | `<servidor>`, `1433`                                    |
| `DB_DATABASE`                                  | `prodigos_base_dev`                                     |
| `DB_USERNAME`, `DB_PASSWORD`                   | `adm_base_app` y su contraseña                          |
| `DB_URL`                                       | vacío                                                   |
| `DB_ENCRYPT`, `DB_TRUST_SERVER_CERTIFICATE`    | según el controlador ODBC y el certificado del servidor |
| `ADM_ENTORNO`                                  | `desarrollo`                                            |
| `ADM_DESARROLLO_SERVIDOR`                      | valor exacto de `SELECT @@SERVERNAME` (paso 2.1)        |
| `ADM_DESARROLLO_BD`                            | `prodigos_base_dev`                                     |
| `ADM_PRODUCCION_SERVIDOR`, `ADM_PRODUCCION_BD` | vacíos (este equipo no tiene destino productivo)        |
| `ADM_PRODUCCION_ESCRITURA`                     | `false`                                                 |

```powershell
php artisan config:clear
php artisan adm:entorno
```

Esperado: base y servidor reales iguales a los esperados, escritura `HABILITADA`, migraciones `permitidas` y «Destino coherente con el entorno seleccionado.». Si termina con error, no continuar.

### 2.6 Migraciones (cuenta de despliegue)

En una consola PowerShell nueva, desde la raíz del proyecto. Las variables de proceso prevalecen sobre `.env` y desaparecen al cerrar la consola; la contraseña se pide oculta para que no quede en el historial.

```powershell
php artisan config:clear
$env:DB_USERNAME = 'adm_base_ddl'
$env:DB_PASSWORD = [Net.NetworkCredential]::new('', (Read-Host 'Contraseña de adm_base_ddl' -AsSecureString)).Password
php artisan adm:entorno        # misma verificación, ahora con la cuenta de despliegue
php artisan migrate:status     # solo lectura; esperado: «Migration table not found»
php artisan migrate --pretend  # muestra el DDL; crea la tabla dbo.migrations (vacía)
php artisan migrate            # esperado: las nueve migraciones DONE
Remove-Item Env:DB_USERNAME, Env:DB_PASSWORD
```

No usar `--force`, `migrate:fresh`, `migrate:reset`, `migrate:rollback` ni `db:wipe`. En desarrollo no hace falta `ADM_DESPLIEGUE_DDL`.

### 2.7 Roles (segunda ejecución)

Repetir el comando del paso 2.3. Esperado: «Permisos … sobre dbo.adm_…» para las nueve tablas.

### 2.8 Comprobación del esquema y de los permisos (DBA o despliegue)

```sql
USE prodigos_base_dev;
SELECT name FROM sys.tables WHERE name LIKE N'adm[_]%' ORDER BY name;   -- 9 tablas
SELECT migration FROM dbo.migrations ORDER BY id;                        -- 9 migraciones
SELECT OBJECT_NAME(major_id) AS tabla, permission_name, state_desc
FROM sys.database_permissions
WHERE grantee_principal_id = DATABASE_PRINCIPAL_ID(N'adm_aplicacion')
ORDER BY tabla, permission_name;   -- DENY UPDATE y DELETE en adm_auditoria
```

Las nueve tablas: `adm_actividad`, `adm_auditoria`, `adm_funcionalidades`, `adm_grupos`, `adm_grupos_permisos`, `adm_permisos`, `adm_sesiones`, `adm_usuarios` y `adm_usuarios_grupos`.

### 2.9 Catálogo y primer administrador (cuenta de la aplicación)

En una consola sin las variables del paso 2.6 (usa la cuenta de `.env`):

```powershell
php artisan config:clear
php artisan adm:entorno
php artisan adm:sincronizar-permisos
php artisan adm:sincronizar-permisos   # segunda vez: sin cambios (idempotente)
php artisan adm:crear-administrador --usuario=<usuario> --nombre="<Nombre>" --apellidos="<Apellidos>"
```

La contraseña temporal se pide oculta y dos veces. Comprobación en SQL: sección 5.4 del [manual de instalación](MANUAL_INSTALACION_Y_CONFIGURACION.md#54-catálogo-de-permisos-y-primer-administrador).

## 3. Prueba de integración SQL Server

`tests/Sqlsrv/EsquemaSqlServerTest.php` (grupo `sqlsrv`) comprueba lo que SQLite no reproduce: índice filtrado de email, CHECK con `ISJSON`, `SYSUTCDATETIME()`, CHECK de segundos no negativos y tipos reales (`datetime2`, `nvarchar`).

Requisitos y salvaguardas:

- base **dedicada, vacía** y cuyo nombre termine en `_pruebas`; si no termina así, falla sin conectarse a ella, y si no está vacía, falla antes de migrar;
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
sqlcmd -S <servidor> -d prodigos_base_pruebas -E -b -i database\sql\roles.sql `
       -v UsuarioAplicacion="" UsuarioDespliegue="adm_base_pruebas"
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
php artisan test --testsuite=Sqlsrv
Remove-Item Env:ADM_PRUEBAS_SQLSRV*
```

Si el certificado lo exige, definir también `$env:DB_ENCRYPT` y `$env:DB_TRUST_SERVER_CERTIFICATE` en esa consola. Esperado: 1 prueba aprobada y la base de nuevo vacía (`SELECT name FROM sys.tables;` sin filas). Si `migrate` fallara a medias, el esquema parcial queda en `prodigos_base_pruebas` y se limpia a mano antes de repetir.

## 4. Validación funcional

Con el administrador inicial, recorrer la tabla de la sección 9 del [manual de instalación](MANUAL_INSTALACION_Y_CONFIGURACION.md#9-verificación-de-módulos) con cuentas de prueba ficticias. Además, en SQL Server real:

- **Sesiones:** iniciar sesión en dos navegadores, revocar una desde Sesiones Activas y comprobar que su siguiente petición vuelve al inicio de sesión.
- **Concurrencia:** abrir la misma membresía o los mismos permisos en dos pestañas, guardar en una y después en la otra; la segunda debe recibir el aviso de conflicto y no aplicar sus cambios.
- **Auditoría:** como `adm_base_app`, `UPDATE adm_auditoria SET accion = accion;` debe fallar por permisos.

## 5. Revisión en navegador con Playwright MCP

Preparada, **no ejecutada**. Solo procede con la base de desarrollo de este documento funcionando y autorizada.

Configuración del proyecto: crear `.mcp.json` en la raíz (está en `.gitignore`, no se versiona). Versión fija, perfil en memoria y navegación limitada al servidor local:

```json
{
    "mcpServers": {
        "playwright": {
            "type": "stdio",
            "command": "npx",
            "args": [
                "@playwright/mcp@0.0.85",
                "--isolated",
                "--allowed-origins",
                "http://127.0.0.1:8000",
                "--viewport-size",
                "1366x768",
                "--output-dir",
                "storage/app/revision-visual"
            ]
        }
    }
}
```

`--allowed-origins` no es una barrera de seguridad; la protección real es que la aplicación solo admite conexiones del propio equipo y apunta a la base de desarrollo. `storage/app` está excluido del control de versiones.

Guion de la revisión:

1. `composer run dev` y `php artisan adm:entorno` coherente.
2. Escritorio (1366×768) y móvil (390×844, con `browser_resize`): inicio de sesión (error y correcto), cambio de contraseña obligatorio, Dashboard, menú lateral (contraído y buscador), cada módulo administrativo con sus filtros, formularios, confirmaciones y estados vacíos.
3. Teclado: recorrido con Tab y Mayús+Tab, foco visible, Esc en diálogos.
4. Con la paleta de `docs/ejemplos/paleta-ejemplo.css` aplicada temporalmente (y restaurada al terminar), repetir inicio de sesión, Dashboard y una tabla.
5. Consola del navegador sin errores (`browser_console_messages`).

Solo cuentas y datos ficticios; nunca credenciales reales en capturas.

## 6. Riesgos y salvaguardas

| Riesgo                                                                                               | Salvaguarda                                                                                                                                                                                                                      |
| ---------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Un comando actúa sobre la base de otro sistema                                                       | Las cuentas nuevas solo tienen usuario en su base (paso 2.4): cualquier conexión a otra base falla en SQL Server. Además `DestinoSql` exige que `DB_DATABASE`, `DB_NAME()` y `@@SERVERNAME` coincidan con el destino configurado |
| `migrate` y `migrate --pretend` crean `dbo.migrations` antes de que `GuardaDdl` verifique el destino | Ejecutar `adm:entorno` con la cuenta de despliegue en la misma consola antes de migrar (paso 2.6); esa cuenta no tiene acceso a otras bases                                                                                      |
| `db:wipe`, `migrate:fresh` y `migrate:reset` solo están prohibidos con `APP_ENV=production`          | No se usan (AGENTS.md, regla 4). Con `adm_base_app` fallan por falta de DDL; con la cuenta de despliegue solo alcanzarían `prodigos_base_dev`                                                                                    |
| `roles.sql` solo rechaza las bases del sistema; actúa sobre la base indicada en `-d`                 | Comprobar `-d` antes de ejecutar; con `-b` se detiene ante errores. Si los usuarios indicados no existen en esa base, revierte todo (`THROW` con `XACT_ABORT`)                                                                   |
| La prueba de integración vacía la base que se le indique                                             | Exige sufijo `_pruebas` y base vacía; solo revierte el esquema que creó (corregido en la Etapa 8.1: antes, un fallo de la comprobación de base vacía dejaba ejecutar `migrate:reset` en `tearDown`)                              |
| Credenciales en el historial de la consola o en archivos                                             | Contraseñas pedidas con `Read-Host -AsSecureString`; nunca en `.env.example`, documentos ni capturas                                                                                                                             |
| Intercalación sensible a mayúsculas                                                                  | Crear la base con `Modern_Spanish_CI_AS` (paso 2.2) y verificarla                                                                                                                                                                |
| `composer.json` declara PHP `^8.3`, pero `composer.lock` exige 8.4.1                                 | Usar PHP 8.4 (documentado); alinear `composer.json` queda pendiente de decisión                                                                                                                                                  |

## 7. Condiciones para autorizar la creación de la base

1. Servidor SQL Server de desarrollo identificado (nombre y `@@SERVERNAME`), distinto de cualquier servidor productivo o, si se comparte instancia, con aprobación expresa del DBA.
2. Nombres `prodigos_base_dev` y `prodigos_base_pruebas` libres (paso 2.1).
3. DBA responsable de crear bases y logins y de custodiar las contraseñas.
4. Aceptación de las tres cuentas sin roles de servidor y con acceso a una sola base cada una.
5. Respaldo del `.env` actual del equipo antes de editarlo.
6. Autorización expresa para ejecutar las migraciones y `roles.sql` en esas bases, y para la revisión con Playwright cuando la aplicación funcione.
