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

_Pendiente (Etapa 3):_ creación de la base vacía, logins de aplicación y de despliegue, roles mínimos y prueba de conexión.

## 5. Esquema y datos iniciales

_Pendiente (Etapas 3 y 4):_ migraciones, sincronización del catálogo de permisos y creación del primer administrador. En esta etapa **no** se ejecutan migraciones.

## 6. Ejecución local

```bash
composer run dev
```

Inicia el servidor de Laravel y Vite. Solo se admiten conexiones desde el propio equipo (`127.0.0.1` o `::1`).

Mientras no se configuren los destinos `ADM_*` (sección 3.1), toda la aplicación responde 403 «Base de datos no permitida.»: es el comportamiento seguro por defecto. Hasta la Etapa 4 no existen usuarios, por lo que todavía no es posible iniciar sesión.

## 7. Compilación y verificaciones

```bash
npm run build          # compilación de producción (genera public/build)
npm run types:check    # TypeScript (vue-tsc)
npm run check          # formato y lint (vite-plus: oxfmt y oxlint)
composer test          # Pint, Larastan (nivel del proyecto) y PHPUnit
```

Las pruebas usan SQLite en memoria (`phpunit.xml`); nunca se conectan a SQL Server.

Resultado esperado en la Etapa 2: compilación correcta, sin errores de tipos ni de formato, Pint y Larastan sin errores y PHPUnit 24/24 (entornos, diagnóstico, DDL y acceso inicial).

## 8. Personalizar la paleta

_Pendiente (Etapa 6)._

## 9. Verificación de módulos

_Pendiente (Etapa 5)._

## 10. Publicación en IIS

_Pendiente._ Requisitos previstos: URL Rewrite 2.1, FastCGI con PHP 8.4 NTS, sitio apuntando a `public`, permiso de modificación en `storage` y `bootstrap\cache`.

## 11. Problemas frecuentes

| Síntoma                                                          | Causa                                                                                   | Solución                                                                                                               |
| ---------------------------------------------------------------- | --------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Larastan termina con «reached configured PHP memory limit: 128M» | Límite de memoria de PHP                                                                | El script `composer types:check` ya usa `--memory-limit=512M`; si se ejecuta `phpstan` directamente, añadir esa opción |
| La primera compilación tarda cerca de un minuto                  | Wayfinder genera las rutas tipadas al iniciar el build                                  | Normal; las siguientes compilaciones son más rápidas                                                                   |
| Cambios en `.env` sin efecto                                     | Configuración en caché                                                                  | `php artisan config:clear`                                                                                             |
| Todo responde 403 «Base de datos no permitida.»                  | `ADM_ENTORNO`, `ADM_*_SERVIDOR`, `ADM_*_BD` o `DB_DATABASE` incoherentes                | `php artisan adm:entorno` muestra el motivo                                                                            |
| 403 «Origen no autorizado.»                                      | En local, acceso desde otro equipo; en producción, IP fuera de `ADM_REDES_CORPORATIVAS` | Acceder desde el propio equipo o ajustar las redes                                                                     |
| 403 «La base productiva solo permite consultas.» al guardar      | `ADM_PRODUCCION_ESCRITURA=false`                                                        | Comportamiento esperado; habilitar solo con autorización                                                               |
| `adm:entorno`: «Error de conexión»                               | Servidor, credenciales o certificado ODBC                                               | Revisar `DB_*`, `DB_ENCRYPT` y `DB_TRUST_SERVER_CERTIFICATE`                                                           |
