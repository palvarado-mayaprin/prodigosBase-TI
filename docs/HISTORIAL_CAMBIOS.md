# Historial de cambios

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
