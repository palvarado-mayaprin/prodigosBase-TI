# Validación integral en SQL Server (Etapa 8)

Procedimiento para crear un entorno SQL Server de desarrollo **propio de prodigosBase-TI** y validar en él el esquema, los roles, las pruebas de integración y la aplicación. Preparado en la Etapa 8.1 y **ejecutado en la Etapa 8.2 sobre una instancia local dedicada** (sección 8). Cualquier otro servidor requiere autorización expresa (sección 7).

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

PHP 8.4.1 o superior (`composer.json`: `^8.4.1`) con `pdo_sqlsrv` y `sqlsrv`, Microsoft ODBC Driver 17 o 18 y `sqlcmd`. Comprobación:

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
       -v BaseEsperada="prodigos_base_dev" UsuarioAplicacion="adm_base_app" UsuarioDespliegue="adm_base_ddl"
```

`BaseEsperada` debe coincidir con la base de `-d` (`DB_NAME()`); si falta, está vacío o no coincide, el script se detiene sin crear roles ni tocar permisos.

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
php artisan migrate --pretend  # muestra el DDL; con el destino autorizado crea dbo.migrations (vacía)
php artisan migrate            # esperado: las nueve migraciones DONE
Remove-Item Env:DB_USERNAME, Env:DB_PASSWORD
```

No usar `--force`. `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` y `db:wipe` están bloqueados en esta base (sección 6). En desarrollo no hace falta `ADM_DESPLIEGUE_DDL`.

`GuardaDdl` actúa en dos momentos: al iniciar el comando (evento `CommandStarting`, antes de que `migrate` o `migrate --pretend` creen `dbo.migrations`) y al empezar el Migrator (evento `MigrationsStarted`). Una configuración incorrecta o no autorizada no llega a escribir nada.

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
sqlcmd -S <servidor> -d prodigos_base_pruebas -E -b -i database\sql\roles.sql `
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

Con el administrador inicial, recorrer la tabla de la sección 9 del [manual de instalación](MANUAL_INSTALACION_Y_CONFIGURACION.md#9-verificación-de-módulos) con cuentas de prueba ficticias. Además, en SQL Server real:

- **Sesiones:** iniciar sesión en dos navegadores, revocar una desde Sesiones Activas y comprobar que su siguiente petición vuelve al inicio de sesión.
- **Concurrencia:** abrir la misma membresía o los mismos permisos en dos pestañas, guardar en una y después en la otra; la segunda debe recibir el aviso de conflicto y no aplicar sus cambios.
- **Auditoría:** como `adm_base_app`, `UPDATE adm_auditoria SET accion = accion;` debe fallar por permisos.

## 5. Revisión en navegador con Playwright MCP

**Ejecutada en la Etapa 8.2** sobre la instancia local (resultados en la sección 8.5). Solo procede con la base de desarrollo de este documento funcionando y autorizada.

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

| Riesgo                                                                              | Salvaguarda                                                                                                                                                                                                                                                                   |
| ----------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Un comando actúa sobre la base de otro sistema                                      | Las cuentas nuevas solo tienen usuario en su base (paso 2.4): cualquier conexión a otra base falla en SQL Server. Además `DestinoSql` exige que `DB_DATABASE`, `DB_NAME()` y `@@SERVERNAME` coincidan con el destino configurado                                              |
| `migrate` o `migrate --pretend` escriben antes de comprobar el destino              | **Corregido (Etapa 8.1, correcciones):** `GuardaDdl` se comprueba al iniciar el comando, antes de crear `dbo.migrations`. Con un destino incoherente o, en producción, sin `ADM_DESPLIEGUE_DDL`, no se escribe nada                                                           |
| `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` y `migrate:rollback` | **Corregido:** bloqueados salvo en una base de desarrollo cuyo nombre termine en `_pruebas`, con el destino real coincidente y la variable de proceso `ADM_PRUEBAS_DESTRUCTIVAS` igual a esa base. Nunca en producción ni en `prodigos_base_dev`. Ya no dependen de `APP_ENV` |
| `roles.sql` sobre una base equivocada                                               | **Corregido:** exige `-v BaseEsperada` igual a `DB_NAME()`; si falta o no coincide, se detiene antes de cualquier cambio. Con `-b`, `sqlcmd` termina con error                                                                                                                |
| La prueba de integración vacía la base que se le indique                            | Exige sufijo `_pruebas`, base vacía y `ADM_PRUEBAS_DESTRUCTIVAS`; solo revierte el esquema que creó                                                                                                                                                                           |
| Credenciales en el historial de la consola o en archivos                            | Contraseñas pedidas con `Read-Host -AsSecureString`; nunca en `.env.example`, documentos ni capturas                                                                                                                                                                          |
| Intercalación sensible a mayúsculas                                                 | Crear la base con `Modern_Spanish_CI_AS` (paso 2.2) y verificarla                                                                                                                                                                                                             |
| Versión de PHP                                                                      | **Corregido:** `composer.json` exige `^8.4.1`, coherente con `composer.lock` y con el CI (PHP 8.4)                                                                                                                                                                            |

**Confirmado en la Etapa 8.2 (instancia local):** `roles.sql` con `BaseEsperada` distinta de `-d` se detiene con 50005 sin crear roles, y `GuardaDdl` bloquea contra el servidor real un destino incorrecto (`migrate` y `migrate --pretend`) y los comandos destructivos sobre `prodigos_base_dev`, sin crear `dbo.migrations`.

## 7. Condiciones para autorizar la creación de la base

1. Servidor SQL Server de desarrollo identificado (nombre y `@@SERVERNAME`), distinto de cualquier servidor productivo o, si se comparte instancia, con aprobación expresa del DBA.
2. Nombres `prodigos_base_dev` y `prodigos_base_pruebas` libres (paso 2.1).
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

Se siguen los pasos 2.2 a 2.9 y la sección 3 con `-S tcp:127.0.0.1,14330`, `DB_HOST=127.0.0.1`, `DB_PORT=14330`, `ADM_DESARROLLO_SERVIDOR=<EQUIPO>\PRODIGOSBASE` y `ADM_PRUEBAS_SQLSRV_PORT=14330`. Notas prácticas:

- **Contraseñas:** se generan aleatoriamente y se guardan cifradas con DPAPI del usuario de Windows (`ConvertFrom-SecureString`) fuera del repositorio; se pasan a `sqlcmd` con `SQLCMDPASSWORD` o como variables de entorno del proceso, nunca en la línea de comandos ni en archivos del proyecto.
- **`sqlcmd -I`:** `sqlcmd` arranca con `QUOTED_IDENTIFIER` desactivado; las escrituras manuales sobre tablas con índices filtrados fallan con el error 1934 si no se añade `-I`.
- **Parámetro vacío en PowerShell 5.1:** `UsuarioAplicacion=""` se pierde al pasar a `sqlcmd` (error «Invalid argument»); ejecutar esa llamada con `cmd /c '<comando>'`.
- **`adm:crear-administrador`:** pide la contraseña oculta en la consola de Windows y no admite redirección de la entrada; ejecutarlo en una consola interactiva.
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

**Observaciones sin corregir (mejoras, fuera del alcance de la etapa):** no hay páginas de error propias (los 403 muestran la página genérica en inglés de Laravel, también al deshabilitar una cuenta con la sesión abierta); no hay enlace «Saltar al contenido»; tras cerrar la alerta de validación, el foco vuelve a `body`; los contadores dicen «1 usuarios encontrados»; Membresías mezcla tuteo («Selecciona un usuario») con el trato de usted.
