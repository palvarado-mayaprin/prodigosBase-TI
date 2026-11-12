# Manual de instalación y configuración

Este manual se completa etapa por etapa. Cada sección indica si ya es aplicable o si queda pendiente. Lo documentado aquí se verificó en un equipo Windows 11 el 2026-11-11.

## 1. Requisitos de software

| Software                             | Versión verificada          | Notas                                                                                                                             |
| ------------------------------------ | --------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| PHP                                  | 8.4 NTS x64 (mínimo `^8.3`) | Extensiones: `pdo_sqlsrv`, `sqlsrv`, `mbstring`, `openssl`, `fileinfo`, `intl`, `zip`, `curl`; `pdo_sqlite` solo para las pruebas |
| Composer                             | 2.10                        |                                                                                                                                   |
| Node.js y npm                        | Node 24, npm 11             | El proyecto incluye `.npmrc` con `ignore-scripts=true`                                                                            |
| Git                                  | 2.51                        |                                                                                                                                   |
| Microsoft ODBC Driver for SQL Server | 17 o 18                     | Con el 18, revisar `DB_ENCRYPT` y `DB_TRUST_SERVER_CERTIFICATE`                                                                   |
| Microsoft SQL Server                 | 2016 o superior             | Necesario a partir de la Etapa 3                                                                                                  |

Comprobaciones:

```bash
php -v
php -m        # debe listar pdo_sqlsrv, sqlsrv, intl, zip y pdo_sqlite
composer -V
node -v
npm -v
```

## 2. Obtener el proyecto e instalar dependencias

```bash
git clone <url-del-repositorio> prodigosBase-TI   # cuando exista el repositorio remoto
cd prodigosBase-TI
composer install
npm install
```

`composer run setup` también instala dependencias y compila, pero **no** ejecuta migraciones: el esquema se crea de forma controlada (sección 5).

## 3. Variables de entorno

```bash
cp .env.example .env
php artisan key:generate
```

| Variable                                            | Valor de ejemplo       | Uso                                         |
| --------------------------------------------------- | ---------------------- | ------------------------------------------- |
| `DB_CONNECTION`                                     | `sqlsrv`               | Motor obligatorio                           |
| `DB_HOST`, `DB_PORT`                                | `127.0.0.1`, `1433`    | Servidor SQL Server propio                  |
| `DB_DATABASE`                                       | `prodigos_base_dev`    | Base vacía de desarrollo propia             |
| `DB_USERNAME`, `DB_PASSWORD`                        | vacíos                 | Credenciales personales; nunca se versionan |
| `DB_URL`                                            | vacío                  | Debe permanecer vacío                       |
| `DB_ENCRYPT`, `DB_TRUST_SERVER_CERTIFICATE`         | comentadas             | Según el controlador ODBC y el certificado  |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `file`, `file`, `sync` | No requieren tablas en SQL Server           |

### 3.1 Control centralizado de entornos

`App\Entorno\DestinoSql` es la única autoridad sobre el destino SQL Server y la escritura (`config/entorno.php`). El middleware `ControlarAcceso` lo aplica a todo el grupo `web`.

| Variable                                       | Valor                                        | Uso                                                                                                                              |
| ---------------------------------------------- | -------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `ADM_ENTORNO`                                  | `desarrollo` o `produccion`                  | Destino seleccionado. Debe coincidir con `DB_DATABASE`; si no, **toda** la aplicación responde 403 «Base de datos no permitida.» |
| `ADM_PRODUCCION_ESCRITURA`                     | `false` o `true`                             | Único interruptor de escritura en producción, para todos los módulos. En desarrollo no tiene efecto                              |
| `ADM_DESARROLLO_SERVIDOR`, `ADM_DESARROLLO_BD` | Valor de `@@SERVERNAME` y base de desarrollo | Destino autorizado de desarrollo                                                                                                 |
| `ADM_PRODUCCION_SERVIDOR`, `ADM_PRODUCCION_BD` | Valor de `@@SERVERNAME` y base de producción | Destino autorizado de producción                                                                                                 |
| `ADM_REDES_CORPORATIVAS`                       | CIDR separados por comas                     | Con `APP_ENV=production`, únicas redes admitidas; vacío rechaza todo. En `local` solo se admite el propio equipo                 |

Reglas:

- Desarrollo y producción no pueden ser el mismo servidor y base.
- Servidor y base se comparan sin distinguir mayúsculas.
- Toda petición que no sea GET, HEAD u OPTIONS exige escritura permitida: destino configurado y real (`DB_NAME()` y `@@SERVERNAME`) coincidentes; en producción, además, `ADM_PRODUCCION_ESCRITURA=true` y, con `APP_ENV=production`, `APP_DEBUG=false`. Solo el inicio y el cierre de sesión están exentos.
- `APP_ENV` sigue gobernando Laravel (origen, CSRF, errores), pero no autoriza ni impide por sí solo escribir en producción.
- `DB_URL` debe quedar vacío y el driver ser `sqlsrv`.

Para conocer el valor de `@@SERVERNAME`, ejecutar en SQL Server `SELECT @@SERVERNAME;`.

### 3.2 Verificar el entorno

```bash
php artisan config:clear
php artisan adm:entorno
```

El comando es de solo lectura: muestra entorno, `APP_ENV`, `APP_DEBUG`, driver, `DB_URL`, destino esperado, base configurada, base y servidor reales, interruptor, estado de la escritura con su motivo y si las migraciones están permitidas. No muestra credenciales. Termina con error si el destino no es coherente; si la configuración no es válida, no intenta conectarse.

### 3.3 Cambiar de entorno

1. Respaldar `.env`.
2. Editar solo `ADM_ENTORNO`, `DB_DATABASE`, las credenciales `DB_*` si cambian y, para escribir en producción, `ADM_PRODUCCION_ESCRITURA=true`.
3. `php artisan config:clear` y `php artisan adm:entorno`. Si termina con error, no continuar.
4. En IIS, además, `php artisan optimize` y reciclar el grupo de aplicaciones.

Recuperación: restaurar el `.env` respaldado y repetir el paso 3. Cambiar de entorno nunca requiere modificar código.

### 3.4 Migraciones y DDL

Las migraciones tienen una autorización propia (`App\Entorno\GuardaDdl`), independiente del interruptor de escritura:

- siempre exigen que el destino real coincida con `ADM_ENTORNO`;
- en producción exigen además la variable de proceso `ADM_DESPLIEGUE_DDL` con el nombre exacto de la base de producción, definida solo en la consola de despliegue y nunca en `.env`;
- `ADM_PRODUCCION_ESCRITURA` nunca habilita DDL.

## 4. Preparar SQL Server

El modelo completo está en [ARQUITECTURA_SQL_SERVER.md](ARQUITECTURA_SQL_SERVER.md). Estos pasos los ejecuta quien administre el servidor (o el propio desarrollador en su instancia local), con una cuenta con permisos para crear bases y logins. Los valores entre `<>` son ejemplos y las contraseñas nunca se versionan.

### 4.1 Base vacía

```sql
CREATE DATABASE prodigos_base_dev;
GO
-- Requisito: nivel de compatibilidad 130 o superior (SQL Server 2016+).
SELECT name, compatibility_level, collation_name FROM sys.databases WHERE name = N'prodigos_base_dev';
SELECT @@SERVERNAME AS servidor;   -- valor para ADM_DESARROLLO_SERVIDOR
```

Se recomienda una intercalación que no distinga mayúsculas (`*_CI_AS`), coherente con la comparación de usuarios y nombres.

### 4.2 Logins y usuarios

Dos cuentas separadas: la de la aplicación (va en `.env`) y la de despliegue (solo en la consola, para migraciones).

```sql
CREATE LOGIN <adm_base_app> WITH PASSWORD = N'<contraseña segura>', CHECK_POLICY = ON;
CREATE LOGIN <adm_base_ddl> WITH PASSWORD = N'<contraseña segura>', CHECK_POLICY = ON;
GO
USE prodigos_base_dev;
CREATE USER <adm_base_app> FOR LOGIN <adm_base_app>;
CREATE USER <adm_base_ddl> FOR LOGIN <adm_base_ddl>;
```

Con autenticación de Windows, sustituir por `CREATE LOGIN [DOMINIO\cuenta] FROM WINDOWS`.

### 4.3 Roles mínimos (primera ejecución)

```powershell
sqlcmd -S <servidor> -d prodigos_base_dev -E -i database\sql\roles.sql `
       -v UsuarioAplicacion="<adm_base_app>" UsuarioDespliegue="<adm_base_ddl>"
```

Crea `adm_aplicacion` y `adm_despliegue` y añade los usuarios. Como aún no hay tablas, avisa de que omite sus permisos: es lo esperado.

### 4.4 Configurar y verificar

En `.env`: `DB_HOST`, `DB_PORT`, `DB_DATABASE=prodigos_base_dev`, `DB_USERNAME` y `DB_PASSWORD` de la cuenta de la aplicación, `ADM_ENTORNO=desarrollo`, `ADM_DESARROLLO_SERVIDOR` (valor de `@@SERVERNAME`) y `ADM_DESARROLLO_BD=prodigos_base_dev`.

```bash
php artisan config:clear
php artisan adm:entorno
```

Esperado: «Destino coherente con el entorno seleccionado.», escritura `HABILITADA` y migraciones `permitidas`.

## 5. Esquema y datos iniciales

### 5.1 Migraciones

Se ejecutan con la cuenta de despliegue, pasada como variable de proceso solo en esa consola (prevalece sobre `.env`). No usar `config:cache` antes: la configuración en caché ignora estas variables.

```powershell
php artisan config:clear
$env:DB_USERNAME = '<adm_base_ddl>'
$env:DB_PASSWORD = '<contraseña>'
php artisan migrate --pretend   # revisar el DDL sin ejecutarlo
php artisan migrate
Remove-Item Env:DB_USERNAME, Env:DB_PASSWORD
```

`GuardaDdl` detiene la migración si el destino real no coincide. En producción exige además `$env:ADM_DESPLIEGUE_DDL = '<base de producción>'` en la misma consola (y retirarla al terminar).

### 5.2 Roles (segunda ejecución)

Repetir el paso 4.3: ahora concede los permisos de la aplicación sobre las nueve tablas `adm_*` y deniega `UPDATE` y `DELETE` en `adm_auditoria`.

### 5.3 Comprobación

```sql
SELECT name FROM sys.tables WHERE name LIKE N'adm[_]%' ORDER BY name;   -- 9 tablas
SELECT migration FROM dbo.migrations ORDER BY id;                        -- 9 migraciones
```

### 5.4 Catálogo de permisos y primer administrador

Con la cuenta de la aplicación (los permisos de `adm_aplicacion` bastan) y la escritura permitida por `adm:entorno`:

```bash
php artisan adm:sincronizar-permisos
php artisan adm:crear-administrador --usuario=<usuario> --nombre="<Nombre>" --apellidos="<Apellidos>"
```

- `adm:sincronizar-permisos` carga el catálogo de funcionalidades administrativas. Es idempotente: repetirlo no cambia nada si el catálogo no cambió. Nunca borra ni asigna permisos a grupos; lo que sale del catálogo queda inactivo.
- `adm:crear-administrador` pide la contraseña temporal oculta y dos veces (mínimo 12 caracteres, con mayúsculas, minúsculas, números y símbolos); nunca se pasa como opción. Crea el grupo `Administradores` (`es_administrador = 1`) si no existe, la cuenta y su membresía en una sola transacción auditada. No crea duplicados.
- Al iniciar sesión, la cuenta debe cambiar la contraseña antes de usar la aplicación.

Comprobación:

```sql
SELECT codigo, activo FROM adm_funcionalidades ORDER BY codigo;
SELECT u.usuario, u.debe_cambiar_password, g.nombre, g.es_administrador
FROM adm_usuarios u JOIN adm_usuarios_grupos ug ON ug.usuario_id = u.id JOIN adm_grupos g ON g.id = ug.grupo_id;
SELECT entidad, accion, fecha_utc FROM adm_auditoria ORDER BY id;
```

## 6. Ejecución local

```bash
composer run dev
```

Inicia el servidor de Laravel y Vite. Solo se admiten conexiones desde el propio equipo (`127.0.0.1` o `::1`).

Mientras no se configuren los destinos `ADM_*` (sección 3.1), toda la aplicación responde 403 «Base de datos no permitida.»: es el comportamiento seguro por defecto. El inicio de sesión usa el nombre de usuario y la contraseña de `adm_usuarios`; requiere haber creado el primer administrador (sección 5.4). Iniciar sesión no escribe en la base, así que también funciona con la producción en solo lectura.

## 7. Compilación y verificaciones

```bash
npm run build          # compilación de producción (genera public/build)
npm run types:check    # TypeScript (vue-tsc)
npm run check          # formato y lint (vite-plus: oxfmt y oxlint)
npm run test:js        # pruebas de la lógica del frontend (node --test)
composer test          # Pint, Larastan (nivel del proyecto) y PHPUnit
```

Las pruebas usan SQLite en memoria (`phpunit.xml`); nunca se conectan a SQL Server.

Resultado esperado en la Etapa 5.2: compilación correcta, sin errores de tipos ni de formato, Pint y Larastan sin errores, `test:js` 7/7 y PHPUnit con 116 pruebas aprobadas y 1 omitida (la integración con SQL Server, que se habilita según [ARQUITECTURA_SQL_SERVER.md](ARQUITECTURA_SQL_SERVER.md#6-pruebas)).

## 8. Personalizar la paleta

La paleta vive solo en `resources/css/tema/tokens.css` (variables `--adm-*`). Para personalizarla se editan esos valores y se ejecuta `npm run build`; la estructura y las medidas están en `componentes.css` y no se modifican. La prueba `tests/Feature/Visual/PaletaTest.php` impide colores literales fuera de los tokens.

_Pendiente (Etapa 6):_ completar el sistema visual (navegación, encabezado, inicio de sesión, Dashboard) y la guía de personalización.

## 9. Verificación de módulos

Con el primer administrador (sección 5.4):

| Módulo                     | Ruta                           | Comprobación                                                                                                                                                                                                                                                                                           |
| -------------------------- | ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Administración de Usuarios | `/admin/usuarios`              | Crear una cuenta de prueba; editarla; restablecerle la contraseña (queda «Cambio de contraseña pendiente»); deshabilitarla y comprobar que no puede iniciar sesión; habilitarla. El botón de deshabilitar la propia cuenta está desactivado. Cada operación aparece en `adm_auditoria` sin contraseñas |
| Mi Perfil                  | `/mi-perfil` (menú de usuario) | Cambiar correo y teléfono; cambiar la contraseña con la actual. Nombre y usuario son de solo lectura                                                                                                                                                                                                   |
| Administración de Grupos   | `/admin/grupos`                | Crear un grupo de prueba; editar su nombre y descripción; consultar su detalle; desactivarlo y activarlo. Desactivar el único grupo administrador propio se rechaza                                                                                                                                    |
| Usuarios y grupos          | `/admin/membresias`            | En Usuarios → Grupos, asignar y retirar el grupo de prueba a una cuenta (confirmación con el detalle). Retirarse a sí mismo el grupo administrador se rechaza. En Grupos → Usuarios, consultar los integrantes                                                                                         |
| Administración de Permisos | `/admin/permisos`              | Asignar a un grupo no administrador `Usuarios y grupos · Consultar membresías` y comprobar que sus integrantes abren `/admin/membresias` en modo consulta; retirarlo. Las funcionalidades «Solo administrador» no se pueden marcar                                                                     |

Una cuenta sin grupo administrador recibe 403 en `/admin/usuarios`, `/admin/grupos` y `/admin/permisos`. Todas las operaciones aparecen en `adm_auditoria`. _Pendiente:_ Sesiones Activas, Auditoría, Seguimiento de Uso, Dashboard y navegación (Etapa 5).

## 10. Publicación en IIS

_Pendiente._ Requisitos previstos: URL Rewrite 2.1, FastCGI con PHP 8.4 NTS, sitio apuntando a `public`, permiso de modificación en `storage` y `bootstrap\cache`.

## 11. Problemas frecuentes

| Síntoma                                                                                   | Causa                                                                                                                              | Solución                                                                                                               |
| ----------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Larastan termina con «reached configured PHP memory limit: 128M»                          | Límite de memoria de PHP                                                                                                           | El script `composer types:check` ya usa `--memory-limit=512M`; si se ejecuta `phpstan` directamente, añadir esa opción |
| La primera compilación tarda cerca de un minuto                                           | Wayfinder genera las rutas tipadas al iniciar el build                                                                             | Normal; las siguientes compilaciones son más rápidas                                                                   |
| Cambios en `.env` sin efecto                                                              | Configuración en caché                                                                                                             | `php artisan config:clear`                                                                                             |
| Todo responde 403 «Base de datos no permitida.»                                           | `ADM_ENTORNO`, `ADM_*_SERVIDOR`, `ADM_*_BD` o `DB_DATABASE` incoherentes                                                           | `php artisan adm:entorno` muestra el motivo                                                                            |
| 403 «Origen no autorizado.»                                                               | En local, acceso desde otro equipo; en producción, IP fuera de `ADM_REDES_CORPORATIVAS`                                            | Acceder desde el propio equipo o ajustar las redes                                                                     |
| 403 «La base productiva solo permite consultas.» al guardar                               | `ADM_PRODUCCION_ESCRITURA=false`                                                                                                   | Comportamiento esperado; habilitar solo con autorización                                                               |
| `adm:entorno`: «Error de conexión»                                                        | Servidor, credenciales o certificado ODBC                                                                                          | Revisar `DB_*`, `DB_ENCRYPT` y `DB_TRUST_SERVER_CERTIFICATE`                                                           |
| Larastan falla con «Cannot redeclare class ComposerAutoloaderInit…» citando otro proyecto | En Windows, OPcache de la CLI comparte código compilado entre procesos PHP simultáneos; PHPStan carga su `.phar` con un alias fijo | `composer types:check` ya desactiva OPcache para PHPStan (`-d opcache.enable_cli=0`); usar ese script                  |
| Tras iniciar sesión siempre se vuelve a «Cambiar contraseña»                              | La cuenta tiene `debe_cambiar_password = 1`                                                                                        | Cambiar la contraseña o cerrar la sesión                                                                               |
