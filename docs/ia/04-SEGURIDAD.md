# Seguridad

Controles que cualquier cambio debe conservar. Detalle en [`MANUAL_TECNICO.md`](../MANUAL_TECNICO.md) (secciones 2 a 6) y [`ARQUITECTURA_SQL_SERVER.md`](../ARQUITECTURA_SQL_SERVER.md).

## Autenticación

- Fortify solo atiende inicio y cierre de sesión (`FortifyServiceProvider`); autentica por `usuario` y contraseña contra `adm_usuarios`.
- Cuentas inactivas rechazadas, sin «recordarme», 5 intentos por minuto por usuario e IP, comparación de hash aun si la cuenta no existe.
- Política única de contraseñas (`Password::defaults()` en `AppServiceProvider`): 12 a 72 caracteres con mayúsculas, minúsculas, números y símbolos.
- `debe_cambiar_password = 1` obliga a cambiarla (`ExigirCambioContrasena`). El cambio (`CambiarContrasena`) revoca las demás sesiones de la cuenta en la misma transacción.

## Autorización

- Denegación por defecto. Un permiso es `codigo:ACCION` de una funcionalidad de `CatalogoPermisos`; los grupos lo reciben en Administración de Permisos.
- Un grupo activo con `es_administrador = 1` tiene acceso universal. Las funcionalidades `soloAdministrador` no se obtienen por asignación.
- Cada ruta declara `AutorizarPermiso::para('codigo:ACCION')` o `AutorizarAdministrador`; un permiso fuera del catálogo impide arrancar. `AutorizacionTest` falla si una ruta queda sin autorización y no está en su lista explícita de rutas propias.
- Ocultar una opción del menú no protege nada: la protección es la ruta.

## Sesiones

- `adm_sesiones` registra cada inicio de sesión (sin identificador de sesión ni cookie); `ValidarSesion` rechaza en la siguiente petición una sesión revocada, cerrada o ajena.
- La interfaz usa una referencia HMAC de la fila, nunca su id. Nadie revoca su propia sesión actual desde Sesiones Activas.

## Auditoría

- `Auditor::registrar()` exige una transacción abierta; si falla, se revierte todo el cambio.
- Lista cerrada de acciones (`CREAR`, `EDITAR`, `HABILITAR`, `DESHABILITAR`, `ASIGNAR`, `RETIRAR`, `REVOCAR`); una nueva exige ampliar `Auditor::ACCIONES` y el CHECK de la tabla con una migración.
- Enmascara claves que contienen `password`, `contrasena`, `clave`, `token`, `secret` o `secreto`. Nunca auditar contraseñas ni secretos.

## Entornos y escritura (`DestinoSql`)

- `ADM_ENTORNO` (`desarrollo` o `produccion`) elige el destino; `ADM_*_SERVIDOR` y `ADM_*_BD` deben coincidir con `DB_DATABASE` y con el destino real (`DB_NAME()`, `@@SERVERNAME`). Si no, toda la aplicación responde 403.
- `ControlarAcceso` bloquea toda petición que no sea GET, HEAD u OPTIONS sin escritura permitida (excepto `ControlarAcceso::RUTAS_SIN_ESCRITURA`) y controla el origen: en local solo el propio equipo; con `APP_ENV=production`, las redes de `ADM_REDES_CORPORATIVAS`.
- En producción, escribir exige `ADM_PRODUCCION_ESCRITURA=true` (y `APP_DEBUG=false` con `APP_ENV=production`). `APP_ENV` no autoriza escrituras.
- **Prohibido:** validar destino, base o driver en servicios o controladores, o crear interruptores de escritura por módulo. Cambiar de entorno es editar `.env`, `php artisan config:clear` y comprobar con `php artisan adm:entorno`.

## Migraciones y DDL (`GuardaDdl`)

- Se comprueba al iniciar cualquier comando de esquema y al arrancar el Migrator, antes de escribir nada (también con `--pretend`).
- Producción exige la variable de proceso `ADM_DESPLIEGUE_DDL` igual a la base de producción.
- `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` y `migrate:rollback` solo en una base de desarrollo terminada en `_pruebas` y con `ADM_PRUEBAS_DESTRUCTIVAS` igual a esa base.
- Ambas variables son **autorizaciones temporales**: solo valen como variables del proceso de la consola; si un archivo `.env*` las declara, se rechazan.
- El interruptor de escritura nunca habilita DDL.

## Privilegios SQL

- `adm_aplicacion` (cuenta de `.env`): SELECT, INSERT y UPDATE en las tablas `adm_*`, DELETE solo en las de asignación, sin DDL; UPDATE y DELETE denegados en `adm_auditoria`.
- `adm_despliegue` (solo en la consola de migraciones): `db_ddladmin` y DML sobre `dbo`.
- Tablas nuevas de un proyecto derivado: permisos mínimos concedidos por el DBA ([`MANUAL_TECNICO.md`, sección 4.2](../MANUAL_TECNICO.md#42-permisos-sql-de-las-tablas-nuevas)); no se amplía `roles.sql`.

## No modificar sin autorización expresa

`DestinoSql`, `GuardaDdl`, `ControlarAcceso`, `ValidarSesion`, `Autorizacion`, `Auditor`, `roles.sql`, el esquema de las nueve tablas `adm_*`, el área `administracion` del catálogo, la política de contraseñas, `config/inertia.php` (Inertia DevTools debe seguir deshabilitado) y `.env` de cualquier entorno. Los hallazgos pendientes que afectan a estos controles están en [`06-ESTADO.md`](06-ESTADO.md).
