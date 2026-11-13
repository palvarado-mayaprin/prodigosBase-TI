# Arquitectura SQL Server

Esquema administrativo propio de prodigosBase-TI. No reutiliza tablas ni datos de ningún sistema anterior.

## 1. Principios

- **Fuente única:** las migraciones de `database/migrations`. No existe otro DDL de tablas; `database/sql/roles.sql` solo concede permisos.
- **Requisitos:** SQL Server 2016 o superior, con nivel de compatibilidad 130 o superior (`ISJSON`). Las conexiones usan `ANSI_NULLS` y `QUOTED_IDENTIFIER` activados, que exigen los índices filtrados; es el comportamiento predeterminado de `pdo_sqlsrv`.
- **Identificadores:** `bigint IDENTITY` (`id()` de Laravel) y relaciones por FK con `foreignId()`. Ninguna FK borra en cascada: los usuarios y grupos no se eliminan, se deshabilitan con `activo`.
- **Fechas:** siempre UTC (`config/app.php` usa `UTC`), en `datetime2(3)`. Las columnas propias llevan el sufijo `_utc`; `created_at` y `updated_at` los fija Laravel.
- **Texto:** `nvarchar` (Unicode). Los valores cerrados usan `enum` de Laravel, que crea un CHECK tanto en SQL Server como en SQLite.
- **Contraseñas:** solo hashes de Laravel (`Hash::make`, bcrypt o argon2) en `nvarchar(255)`.
- **Compatibilidad con las pruebas:** el SQL propio de SQL Server (índices filtrados, `INCLUDE`, `ISJSON`, CHECK de segundos) se aplica solo con el driver `sqlsrv`. En SQLite el email único usa un índice único normal, que ya admite varios `NULL`.

## 2. Tablas

### 2.1 `adm_usuarios`

| Columna                    | Tipo            | Restricciones                                                                |
| -------------------------- | --------------- | ---------------------------------------------------------------------------- |
| `id`                       | bigint IDENTITY | PK                                                                           |
| `usuario`                  | nvarchar(50)    | NOT NULL, único                                                              |
| `nombre`                   | nvarchar(100)   | NOT NULL                                                                     |
| `apellidos`                | nvarchar(100)   | NOT NULL                                                                     |
| `email`                    | nvarchar(255)   | NULL; único entre los no nulos (índice filtrado `adm_usuarios_email_unique`) |
| `telefono`                 | nvarchar(30)    | NULL                                                                         |
| `password`                 | nvarchar(255)   | NOT NULL; hash de Laravel                                                    |
| `activo`                   | bit             | NOT NULL, DEFAULT 1                                                          |
| `debe_cambiar_password`    | bit             | NOT NULL, DEFAULT 0                                                          |
| `ultimo_acceso_utc`        | datetime2(3)    | NULL                                                                         |
| `created_at`, `updated_at` | datetime2(3)    | NULL                                                                         |

### 2.2 `adm_grupos`

| Columna                    | Tipo            | Restricciones                                                                      |
| -------------------------- | --------------- | ---------------------------------------------------------------------------------- |
| `id`                       | bigint IDENTITY | PK                                                                                 |
| `nombre`                   | nvarchar(100)   | NOT NULL, único                                                                    |
| `descripcion`              | nvarchar(300)   | NULL                                                                               |
| `es_administrador`         | bit             | NOT NULL, DEFAULT 0. Acceso universal; sin identificadores especiales en el código |
| `activo`                   | bit             | NOT NULL, DEFAULT 1                                                                |
| `created_at`, `updated_at` | datetime2(3)    | NULL                                                                               |

### 2.3 `adm_usuarios_grupos`

| Columna      | Tipo         | Restricciones                |
| ------------ | ------------ | ---------------------------- |
| `usuario_id` | bigint       | FK → `adm_usuarios.id`       |
| `grupo_id`   | bigint       | FK → `adm_grupos.id`; índice |
| `created_at` | datetime2(3) | NULL                         |

PK compuesta (`usuario_id`, `grupo_id`).

### 2.4 `adm_funcionalidades`

| Columna                    | Tipo            | Restricciones                                 |
| -------------------------- | --------------- | --------------------------------------------- |
| `id`                       | bigint IDENTITY | PK                                            |
| `codigo`                   | nvarchar(150)   | NOT NULL, único (`area.modulo.funcionalidad`) |
| `area`, `modulo`           | nvarchar(60)    | NOT NULL                                      |
| `nombre`                   | nvarchar(150)   | NOT NULL                                      |
| `descripcion`              | nvarchar(300)   | NULL                                          |
| `ruta`                     | nvarchar(150)   | NULL; nombre de la ruta de entrada            |
| `solo_administrador`       | bit             | NOT NULL, DEFAULT 0                           |
| `activo`                   | bit             | NOT NULL, DEFAULT 1                           |
| `created_at`, `updated_at` | datetime2(3)    | NULL                                          |

La llena solo el comando de sincronización del catálogo (`adm:sincronizar-permisos`).

### 2.5 `adm_permisos`

| Columna            | Tipo            | Restricciones                                 |
| ------------------ | --------------- | --------------------------------------------- |
| `id`               | bigint IDENTITY | PK                                            |
| `funcionalidad_id` | bigint          | FK → `adm_funcionalidades.id`                 |
| `accion`           | nvarchar(255)   | CHECK en `VER`, `CREAR`, `EDITAR`, `ELIMINAR` |

Único (`funcionalidad_id`, `accion`).

### 2.6 `adm_grupos_permisos`

| Columna      | Tipo         | Restricciones                  |
| ------------ | ------------ | ------------------------------ |
| `grupo_id`   | bigint       | FK → `adm_grupos.id`           |
| `permiso_id` | bigint       | FK → `adm_permisos.id`; índice |
| `created_at` | datetime2(3) | NULL                           |

PK compuesta (`grupo_id`, `permiso_id`).

### 2.7 `adm_auditoria`

| Columna      | Tipo            | Restricciones                                                                            |
| ------------ | --------------- | ---------------------------------------------------------------------------------------- |
| `id`         | bigint IDENTITY | PK                                                                                       |
| `fecha_utc`  | datetime2(3)    | NOT NULL, DEFAULT `SYSUTCDATETIME()`                                                     |
| `usuario_id` | bigint          | NULL (comandos de consola); FK → `adm_usuarios.id`                                       |
| `usuario`    | nvarchar(50)    | NULL; copia del nombre en el momento del cambio                                          |
| `ip`         | nvarchar(45)    | NULL                                                                                     |
| `entidad`    | nvarchar(100)   | NOT NULL                                                                                 |
| `registro`   | nvarchar(200)   | NULL                                                                                     |
| `accion`     | nvarchar(255)   | CHECK en `CREAR`, `EDITAR`, `HABILITAR`, `DESHABILITAR`, `ASIGNAR`, `RETIRAR`, `REVOCAR` |
| `cambios`    | nvarchar(max)   | NULL; CHECK `ISJSON(cambios) = 1` (`adm_auditoria_cambios_json`)                         |

Índices: `fecha_utc`; (`entidad`, `fecha_utc`); (`usuario_id`, `fecha_utc`). La aplicación solo inserta y consulta: `UPDATE` y `DELETE` están denegados en `roles.sql`.

### 2.8 `adm_sesiones`

| Columna                              | Tipo            | Restricciones                |
| ------------------------------------ | --------------- | ---------------------------- |
| `id`                                 | bigint IDENTITY | PK                           |
| `usuario_id`                         | bigint          | FK → `adm_usuarios.id`       |
| `ip`                                 | nvarchar(45)    | NULL                         |
| `agente`                             | nvarchar(255)   | NULL                         |
| `inicio_utc`, `ultima_actividad_utc` | datetime2(3)    | NOT NULL                     |
| `cierre_utc`, `revocada_utc`         | datetime2(3)    | NULL                         |
| `revocada_por_id`                    | bigint          | NULL; FK → `adm_usuarios.id` |

Índices: (`usuario_id`, `inicio_utc`) y el filtrado `adm_sesiones_activas_index` (`usuario_id` INCLUDE `ultima_actividad_utc` WHERE `cierre_utc IS NULL AND revocada_utc IS NULL`). La sesión de Laravel vive en archivos; esta tabla registra su ciclo de vida y su revocación.

### 2.9 `adm_actividad`

| Columna            | Tipo            | Restricciones                                                      |
| ------------------ | --------------- | ------------------------------------------------------------------ |
| `id`               | bigint IDENTITY | PK                                                                 |
| `sesion_id`        | bigint          | FK → `adm_sesiones.id`; índice                                     |
| `modulo`           | nvarchar(150)   | NOT NULL                                                           |
| `ruta`             | nvarchar(255)   | NOT NULL                                                           |
| `visita_utc`       | datetime2(3)    | NOT NULL; índice                                                   |
| `segundos_activos` | int             | NOT NULL, DEFAULT 0; CHECK `>= 0` (`adm_actividad_segundos_check`) |

## 3. Relaciones

```
adm_usuarios ─┬─< adm_usuarios_grupos >─── adm_grupos ───< adm_grupos_permisos >─── adm_permisos >─── adm_funcionalidades
              ├─< adm_sesiones ───< adm_actividad
              ├─< adm_sesiones (revocada_por_id)
              └─< adm_auditoria (usuario_id, opcional)
```

## 4. Permisos SQL (`database/sql/roles.sql`)

| Rol              | Permisos                                                                                                                                                                                  |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `adm_aplicacion` | `SELECT, INSERT, UPDATE` en las tablas `adm_*`; `DELETE` solo en `adm_usuarios_grupos` y `adm_grupos_permisos`; en `adm_auditoria` solo `SELECT, INSERT` y `DENY UPDATE, DELETE`. Sin DDL |
| `adm_despliegue` | `db_ddladmin` más `SELECT, INSERT, UPDATE, DELETE, REFERENCES` sobre el esquema `dbo` (migraciones y `dbo.migrations`). Se usa solo en la consola de despliegue                           |

El script es idempotente: crea los roles, concede los permisos de las tablas que existan (avisa de las que falten) y añade los usuarios indicados con `-v BaseEsperada=… UsuarioAplicacion=… UsuarioDespliegue=…`. Antes de cualquier cambio comprueba que `BaseEsperada` coincide con `DB_NAME()`. Se ejecuta antes de las migraciones y otra vez después.

## 5. Control de migraciones

`App\Entorno\GuardaDdl` se ejecuta al iniciar los comandos de esquema (`migrate`, `migrate:install`, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` y `db:wipe`; evento `CommandStarting`, antes de cualquier escritura, incluida `dbo.migrations` con `--pretend`) y otra vez al empezar el Migrator (`MigrationsStarted`). Detiene la operación si:

- la migración no usa la conexión predeterminada (`DB_CONNECTION`), que es la que valida `DestinoSql` (impide desviarla con `--database`), o esa conexión no usa el driver `sqlsrv`;
- el destino real (`DB_NAME()`, `@@SERVERNAME`) no coincide con `ADM_ENTORNO`;
- en producción falta la variable de proceso `ADM_DESPLIEGUE_DDL` con el nombre exacto de la base de producción.

`ADM_PRODUCCION_ESCRITURA` nunca habilita DDL.

**Operaciones destructivas** (`db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` y cualquier migración hacia atrás): además de lo anterior, solo en el entorno de desarrollo, con una base cuyo nombre termine en `_pruebas` y con la variable de proceso `ADM_PRUEBAS_DESTRUCTIVAS` igual a esa base. No dependen de `APP_ENV`.

**Pruebas sobre SQLite.** `DestinoSql` y `GuardaDdl` validan la conexión predeterminada, la misma que usan la aplicación, sus servicios y las migraciones. La única excepción a «solo driver `sqlsrv`» es PHPUnit con SQLite en memoria (`phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), donde el destino se simula. Exige a la vez PHPUnit cargado y una base `:memory:`, así que no puede darse en una instalación real (que se instala sin dependencias de desarrollo y nunca usa `:memory:`). Fuera de esa simulación, cualquier conexión que no sea `sqlsrv` se rechaza. Las restricciones propias de SQL Server se prueban en el grupo `sqlsrv`. Laravel no emite `CommandStarting` en PHPUnit; las pruebas que lo necesitan lo activan con `activarEventosDeComandos()` (`tests/TestCase.php`). En producción Laravel prohíbe además los comandos destructivos (`DB::prohibitDestructiveCommands`).

## 6. Pruebas

| Grupo                  | Dónde                                                  | Qué cubre                                                                                                                                                                    |
| ---------------------- | ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Esquema en SQLite      | `tests/Feature/Esquema`                                | Nueve tablas, hash, valores por defecto, unicidad, PK compuestas, FK sin cascada, CHECK de acciones, auditoría y reversión completa. Se ejecuta siempre                      |
| Guarda de DDL          | `tests/Feature/Entorno/MigracionesProductivasTest.php` | `migrate` en producción sin `ADM_DESPLIEGUE_DDL` no crea tablas aunque la escritura esté habilitada; con la variable sí; solo la conexión predeterminada con driver `sqlsrv` |
| Integración SQL Server | `tests/Sqlsrv` (grupo `sqlsrv`)                        | Índice filtrado de email, `ISJSON`, `SYSUTCDATETIME()`, CHECK de segundos y tipos reales. Se omite salvo habilitación expresa                                                |

Para la integración se necesita una base **vacía y dedicada** cuyo nombre termine en `_pruebas`. La prueba crea el esquema con las migraciones y, solo si lo creó ella, lo elimina al terminar. Preparación de la base y de su cuenta: [VALIDACION_SQL_SERVER.md](VALIDACION_SQL_SERVER.md#3-prueba-de-integración-sql-server).

```powershell
$env:ADM_PRUEBAS_SQLSRV = '1'
$env:ADM_PRUEBAS_SQLSRV_HOST = '<servidor>'
$env:ADM_PRUEBAS_SQLSRV_PORT = '1433'
$env:ADM_PRUEBAS_SQLSRV_BD = 'prodigos_base_pruebas'
$env:ADM_PRUEBAS_SQLSRV_SERVIDOR = '<valor de SELECT @@SERVERNAME>'
$env:ADM_PRUEBAS_SQLSRV_USUARIO = '<usuario con rol adm_despliegue>'
$env:ADM_PRUEBAS_SQLSRV_CLAVE = '<contraseña>'
$env:ADM_PRUEBAS_DESTRUCTIVAS = 'prodigos_base_pruebas'   # autoriza la limpieza final
php artisan test --testsuite=Sqlsrv
Remove-Item Env:ADM_PRUEBAS_SQLSRV*, Env:ADM_PRUEBAS_DESTRUCTIVAS
```

## 6.1 Revisar el DDL antes de aplicarlo

Con la conexión de desarrollo configurada, `php artisan migrate --pretend` muestra las sentencias sin ejecutarlas. `GuardaDdl` lo comprueba antes de que Laravel cree `dbo.migrations`; con el destino autorizado, esa tabla se crea vacía. `php artisan migrate:status` es de solo lectura.
