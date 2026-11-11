# Instrucciones para Claude Code en prodigosBase-TI

Lee primero [AGENTS.md](AGENTS.md); sus reglas permanentes también rigen tu trabajo. La instalación está en [docs/MANUAL_INSTALACION_Y_CONFIGURACION.md](docs/MANUAL_INSTALACION_Y_CONFIGURACION.md).

## Reglas arquitectónicas (obligatorias)

- **SQL Server.** Toda la persistencia de la aplicación usa `sqlsrv` y migraciones de Laravel. El SQL propio de SQL Server (CHECK, índices filtrados) se condiciona al driver para que las migraciones también corran en SQLite durante las pruebas.
- **Entornos y escrituras centralizados.** El destino SQL y la escritura se controlan solo con `App\Entorno\DestinoSql` (`exigirEscritura()`, `escrituraPermitida()`, `escrituraConfigurada()`). No validar `DB_NAME()`, `@@SERVERNAME`, `DB_URL`, driver ni base en servicios o controladores. `APP_ENV` no autoriza escrituras. Las mutaciones HTTP ya pasan por `App\Http\Middleware\ControlarAcceso` (grupo `web`); una ruta POST que solo consulte se declara en `ControlarAcceso::RUTAS_SIN_ESCRITURA`. Cambiar de entorno es editar `.env` (`ADM_*`, `DB_*`), `php artisan config:clear` y verificar con `php artisan adm:entorno`; nunca cambiar código.
- **Sin interruptores por módulo.** Prohibido crear variables de escritura por módulo. El acceso por módulo se controla con permisos de la aplicación y roles SQL.
- **DDL separado.** El interruptor de escritura nunca habilita migraciones ni DDL. `App\Entorno\GuardaDdl` detiene cualquier migración si el destino real no coincide y, en producción, si falta la variable de proceso `ADM_DESPLIEGUE_DDL` (solo en la consola de despliegue, nunca en `.env`).
- **Catálogo de permisos.** Toda funcionalidad se declara en el catálogo centralizado y cada ruta exige su permiso (`AutorizarPermiso::para('codigo:ACCION')`) o el grupo administrador. No hay permisos fuera del catálogo; tras cambiarlo se sincroniza con `adm:sincronizar-permisos`.
- **Servicios administrativos.** Usuarios, grupos, permisos, sesiones, auditoría y seguimiento se gestionan solo mediante sus servicios. Otros módulos no escriben en las tablas `adm_*`.
- **Auditoría transaccional.** Toda escritura administrativa se hace en una transacción que registra la auditoría; si la auditoría falla, se revierte. Nunca se auditan contraseñas ni secretos.
- **Módulos nuevos.** Se registran en el catálogo, la ruta, la navegación y el encabezado, y se construyen por fases: backend con pruebas, frontend, pruebas funcionales.

## Lenguaje visual

- Usar las clases `adm-`, los layouts y el helper de alertas existentes. No rediseñar estructura, medidas, tipografía ni jerarquías.
- Los colores solo existen como tokens en `resources/css/tema/tokens.css`. Personalizar la paleta no altera la geometría.
- Conservar accesibilidad (foco visible, teclado, `aria-*`), estados de carga y error, y comportamiento responsive.

## Verificación y control de versiones

- Antes de cada commit: `composer test` (Pint, Larastan y PHPUnit), `npm run check`, `npm run types:check` y `npm run build`. Revisar el diff y los datos sensibles y añadir solo rutas explícitas.
- Mensajes de commit en español con el formato `tipo(alcance): descripción`.
- No hacer push, crear repositorios remotos ni desplegar sin autorización.
- Una sola sesión de asistente trabaja en la carpeta a la vez.

Cualquier excepción a estas reglas es una decisión arquitectónica y requiere aprobación previa.
