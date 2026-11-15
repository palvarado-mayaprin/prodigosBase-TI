# Estado del proyecto y pendientes

Documento de cierre previo a la publicación. Corte: **2026-11-15**, commit `21996cb`. Resume lo construido, lo verificado, lo corregido y lo pendiente, y remite a los manuales para el detalle; no los sustituye.

Cada afirmación lleva su tipo de evidencia:

| Marca                | Significado                                                                                     |
| -------------------- | ----------------------------------------------------------------------------------------------- |
| **Automatizada**     | Cubierta por la suite (`composer test`, `npm run test:js`), que se ejecuta antes de cada commit |
| **SQL Server real**  | Ejecutada contra `T01003\PRODIGOSBASE` (instancia local dedicada, SQL Server 2022 Developer)    |
| **Navegador**        | Recorrida con Playwright MCP sobre la aplicación en `127.0.0.1`                                 |
| **Solo documentada** | Procedimiento o política escrita, sin ejecución que la respalde                                 |
| **Pendiente**        | Sin corregir o sin verificar                                                                    |

## 1. Objetivo y alcance

Plantilla administrativa reutilizable para aplicaciones internas: autenticación, usuarios, grupos, permisos, sesiones, auditoría, seguimiento de uso, navegación dinámica y sistema visual con paleta personalizable. No contiene reglas de negocio; PRODIGOS V2 es solo referencia de arquitectura y diseño ([README.md](../README.md), [AGENTS.md](../AGENTS.md)).

Fuera del alcance de la plantilla: módulos de negocio, publicación en un servidor concreto y datos reales.

## 2. Arquitectura y tecnologías

Laravel 13 (PHP `^8.4.1`), Inertia.js con Vue 3 y TypeScript, Tailwind CSS, Fortify (solo inicio y cierre de sesión) y SQL Server como única base de la aplicación; SQLite solo en PHPUnit. Piezas centrales:

- `DestinoSql`: única autoridad sobre el destino SQL y la escritura; `GuardaDdl`: autorización separada de migraciones y DDL.
- Catálogo de permisos único, denegación por defecto y auditoría transaccional sin secretos.
- Nueve tablas `adm_*` creadas solo por migraciones; roles SQL `adm_aplicacion` y `adm_despliegue` (`database/sql/roles.sql`).

Detalle: [MANUAL_TECNICO.md](MANUAL_TECNICO.md) y [ARQUITECTURA_SQL_SERVER.md](ARQUITECTURA_SQL_SERVER.md); dependencias en la sección 9 del manual técnico.

## 3. Funcionalidades administrativas terminadas

| Funcionalidad                                                                                            | Evidencia                                                                        | Referencia                             |
| -------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------- | -------------------------------------- |
| Inicio y cierre de sesión, cambio obligatorio de contraseña, política de contraseñas, límite de intentos | Automatizada, SQL Server real y navegador                                        | Manual técnico, sección 3              |
| Autorización por grupos y catálogo de permisos                                                           | Automatizada (toda ruta exige permiso o está en una lista explícita) y navegador | Manual técnico, sección 4              |
| Administración de Usuarios y Mi Perfil                                                                   | Automatizada y navegador                                                         | Manual técnico, 6.1 y 6.2              |
| Grupos, membresías y permisos                                                                            | Automatizada, navegador y concurrencia en SQL Server real                        | Manual técnico, 6.3 a 6.5              |
| Sesiones Activas y revocación                                                                            | Automatizada, navegador y dos sesiones por HTTP en SQL Server real               | Manual técnico, 6.6                    |
| Auditoría de Modificaciones y exportaciones                                                              | Automatizada y navegador                                                         | Manual técnico, 6.7                    |
| Seguimiento de Uso y reportes                                                                            | Automatizada y navegador                                                         | Manual técnico, 6.8                    |
| Dashboard y menú desde el catálogo                                                                       | Automatizada y navegador (escritorio y móvil)                                    | Manual técnico, 6.9                    |
| Sistema visual con paleta única                                                                          | Automatizada (`PaletaTest`) y navegador con la paleta de ejemplo                 | [GUIA_DISENO_UI.md](GUIA_DISENO_UI.md) |
| Páginas de error en español                                                                              | Automatizada (`PaginasErrorTest`) y navegador (403)                              | Manual técnico, sección 4              |

## 4. Etapas ejecutadas

El detalle de cada etapa está en [HISTORIAL_CAMBIOS.md](HISTORIAL_CAMBIOS.md).

| Etapa             | Contenido                                                                                   | Commits               |
| ----------------- | ------------------------------------------------------------------------------------------- | --------------------- |
| 1–3               | Proyecto base, control de entornos y esquema SQL Server con roles mínimos                   | `35379e9`, `39eff2a`  |
| 4                 | Autenticación, autorización, catálogo y auditoría                                           | `5fd5e87`             |
| 5.1–5.5           | Módulos administrativos, Dashboard y navegación                                             | `27cc3ce` a `d5d12ed` |
| 6–7               | Lenguaje visual y limpieza del kit inicial                                                  | `96d6be2`, `334d5ef`  |
| 8.1–8.3           | Validación en SQL Server real, en navegador, de sesiones, concurrencia y seguridad          | `296495d` a `8e4c1fc` |
| 9                 | Documentación definitiva e instalación limpia reproducible                                  | `ca68707`             |
| Fase 4, auditoría | Auditoría independiente sobre `ca68707` (sin cambios): dictamen «no apto» hasta corregir A1 | —                     |
| Fase 4, 4.1 y 4.2 | Corrección de A1 y de A2, A4, A5, A6, A8, A9 y A10                                          | `934814f`, `21996cb`  |

## 5. Evidencia de instalación limpia y pruebas

| Verificación                                 | Resultado                                                                                                                                                                                   | Fecha y fuente                                                                          |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Suite automatizada actual                    | PHPUnit 184 pruebas: 183 aprobadas y 1 omitida (la integración SQL Server, que se ejecuta aparte); Pint y Larastan sin errores; `test:js` 14/14; `check`, `types:check` y `build` correctos | 2026-11-15, `21996cb`                                                                   |
| Integración SQL Server (`--group sqlsrv`)    | 1 prueba y 11 aserciones aprobadas; la base de pruebas queda vacía                                                                                                                          | Etapas 8.2, 8.3 y 4.2 ([VALIDACION_SQL_SERVER.md](VALIDACION_SQL_SERVER.md), 8.4 y 8.6) |
| Instalación limpia                           | Dos recorridos desde un clon limpio siguiendo solo el manual; el segundo, sin intervenciones; base y logins temporales eliminados después                                                   | 2026-11-14, Etapa 9 ([VALIDACION_SQL_SERVER.md](VALIDACION_SQL_SERVER.md), sección 9)   |
| Reutilización                                | Nombre, descripción, paleta y una funcionalidad ficticia en un área nueva, en el clon y sin incorporarla                                                                                    | 2026-11-14, Etapa 9                                                                     |
| Navegador                                    | Recorrido completo (8.5) y focalizado en las correcciones de accesibilidad (8.6); escritorio, móvil y teclado                                                                               | 2026-11-13 y 2026-11-14                                                                 |
| Correcciones de la Fase 4 en SQL Server real | Base temporal eliminada al terminar; detalle en la sección 6                                                                                                                                | 2026-11-15                                                                              |

La instalación limpia y la suite de la Etapa 9 (172 pruebas) son anteriores a las correcciones de la Fase 4; estas se verificaron con la suite actual y sobre SQL Server real, pero no se repitió la instalación limpia completa.

## 6. Hallazgos corregidos en la Fase 4

| ID                    | Hallazgo                                                                                                                    | Corrección                                                                                                                            | Evidencia                                                                                               |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| A1 (alto, bloqueante) | Inertia DevTools, activo en local, grababa peticiones con `contrasena_actual` en texto plano y las servía sin autenticación | Deshabilitado en todos los entornos (`config/inertia.php`), registros locales eliminados y credenciales ficticias rotadas (`934814f`) | Automatizada (`DevToolsInertiaTest`) y HTTP: rutas en 404, también con sesión                           |
| A2                    | `ADM_DESPLIEGUE_DDL` y `ADM_PRUEBAS_DESTRUCTIVAS` autorizaban si estaban en `.env`                                          | `GuardaDdl` rechaza la autorización si un archivo `.env*` declara la variable (`21996cb`)                                             | Automatizada y SQL Server real, con y sin `config:cache`                                                |
| A4                    | Cambiar la contraseña no revocaba las demás sesiones                                                                        | Revocación en la misma transacción, conservando la sesión actual                                                                      | Automatizada y HTTP con dos sesiones en SQL Server real                                                 |
| A5                    | Sin procedimiento de permisos SQL para tablas nuevas                                                                        | Manual técnico, sección 4.2                                                                                                           | SQL Server real: el script del manual, ejecutado tal cual sobre una tabla ficticia en una base temporal |
| A6                    | `PaletaTest` rechazaba una paleta igual a la de ejemplo                                                                     | Se retiró solo esa condición                                                                                                          | Automatizada, con la paleta de ejemplo copiada y restaurada                                             |
| A8                    | `/up` respondía fuera del control de origen                                                                                 | Retirada; responde 404                                                                                                                | Automatizada y HTTP                                                                                     |
| A9                    | `ultimo_acceso_utc` nunca se escribía                                                                                       | Se registra tras un inicio de sesión correcto, en UTC                                                                                 | Automatizada y SQL Server real                                                                          |
| A10                   | Sin política de retención                                                                                                   | Manual técnico, sección 10                                                                                                            | **Solo documentada** (plazos propuestos, sin aprobar)                                                   |

## 7. Hallazgos pendientes

| ID  | Severidad | Hallazgo                                                                                                                                                                                                     | Estado                                                            |
| --- | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------- |
| A3  | Medio     | La cuenta de despliegue (`adm_despliegue`, en `db_ddladmin` y con DML sobre `dbo`) puede modificar, borrar y alterar `adm_auditoria`; el manual técnico (sección 5) afirma que nunca se modifica ni se borra | **Pendiente.** Comprobado en la auditoría con `HAS_PERMS_BY_NAME` |
| A7  | Medio     | No se envían cabeceras de seguridad (`X-Frame-Options` o `frame-ancestors`, `X-Content-Type-Options`, `Referrer-Policy`)                                                                                     | **Pendiente.** Depende de IIS (B1)                                |
| A11 | Bajo      | README, manual de instalación, arquitectura y validación indican «SQL Server 2016 o superior»; Laravel 13 soporta oficialmente 2017 o superior                                                               | **Pendiente**                                                     |

## 8. Riesgos de infraestructura (sin verificar)

| ID  | Severidad | Riesgo                                                                                                                                                                                        | Comprobación necesaria                                          |
| --- | --------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- |
| B1  | Alto      | `web.config`, FastCGI con PHP 8.4 NTS, permisos de `storage` y `bootstrap\cache` y reciclado no probados en IIS; la regla de barra final fallaría en un subdirectorio                         | Publicar en un IIS de prueba (manual de instalación, sección 9) |
| B2  | Alto      | Sin ensayo de producción: `composer install --no-dev`, `php artisan optimize`, `APP_ENV=production` con `ADM_REDES_CORPORATIVAS`, escritura productiva y migraciones con `ADM_DESPLIEGUE_DDL` | Ensayo completo en preproducción                                |
| B3  | Medio     | Sin proxies de confianza: detrás de un balanceador o ARR fallarían el filtro de redes, el límite de intentos y las IP de Sesiones                                                             | Definir la topología y, si hay proxy, declararlo                |
| B4  | Medio     | El CI (`.github/workflows/tests.yml`) nunca se ha ejecutado en un remoto                                                                                                                      | Primera ejecución tras el push autorizado                       |
| B5  | Bajo      | Solo se probó SQL Server 2022 (compatibilidad 150 y 160)                                                                                                                                      | Opcional: instalación limpia en 2017 o 2019                     |

## 9. Mejoras opcionales

- **C1:** el límite de inicio de sesión es por usuario e IP, sin bloqueo de cuenta; reconsiderar si la aplicación se expone fuera de la red corporativa.
- **C2:** advertir en el manual técnico (4.1) que quitar `soloAdministrador` a Usuarios permitiría a una cuenta no administradora restablecer la contraseña de un administrador.
- **C3:** añadir `composer audit` y `npm audit` al CI.
- **C4:** en Sesiones Activas, no contar como abiertas las sesiones vencidas por inactividad.

## 10. Limitaciones conocidas

- **Producción en solo lectura:** no se registran sesiones, último acceso ni uso; esas sesiones no aparecen en Sesiones Activas ni se pueden revocar (manual técnico, 6.6 y 6.8).
- **Sesiones en archivos** (`SESSION_DRIVER=file`), con caché en archivos y cola síncrona: previsto para un único servidor; varios nodos exigirían otra configuración (sin verificar, relacionado con B2 y B3).
- **Sesiones vencidas** por inactividad quedan abiertas en `adm_sesiones` hasta una depuración autorizada (C4 y manual técnico, sección 10).
- **Permisos SQL de módulos nuevos:** se conceden a mano por tabla con el procedimiento de la sección 4.2; `roles.sql` solo cubre las tablas `adm_*`.
- **Servidor de desarrollo:** `php artisan serve` atiende una petición cada vez en Windows (manual de instalación, sección 11).
- **Plataforma validada:** solo Windows con SQL Server 2022 local; IIS y producción sin validar (B1, B2).

## 11. Estado de Git y condiciones para publicar

**Estado:** rama `main`, sin remoto configurado ni push, sin etiquetas. Los commits de la Fase 4 (`934814f`, `21996cb`) y el de este documento son locales.

**Antes de publicar el repositorio** (push autorizado):

1. Decidir si A3, A7 y A11 se corrigen antes de la versión 1.0.0 (la auditoría recomendaba corregir los de severidad media).
2. Revisar las inconsistencias documentales de la sección 12.
3. Configurar el remoto y comprobar la primera ejecución del CI (B4).

**Antes de etiquetar la versión 1.0.0:** con A1 corregido, la auditoría califica la plantilla como «apto con observaciones». La observación es que IIS y producción (B1, B2) y la topología de proxy (B3) siguen sin validar: el primer despliegue real debe tratarse como piloto.

## 12. Inconsistencias documentales para revisar

No se corrigieron en esta etapa:

- **Requisito de SQL Server 2016** en `README.md`, manual de instalación (sección 1 y comprobación 3.1), `ARQUITECTURA_SQL_SERVER.md` y `VALIDACION_SQL_SERVER.md` (1.1): es el hallazgo A11.
- **Inmutabilidad de la auditoría:** el manual técnico (sección 5) afirma que los registros nunca se modifican ni se borran porque `roles.sql` lo deniega; solo se deniega a `adm_aplicacion` (hallazgo A3).
- **Nombre de la instancia sin barra invertida:** `T01003PRODIGOSBASE` en `VALIDACION_SQL_SERVER.md` (8.6) y en la entrada de la Etapa 8.3 del historial; el nombre real es `T01003\PRODIGOSBASE`.
- **Nomenclatura de fases:** el README llamaba «Fase 4» a la publicación en IIS, mientras que la Fase 4 ejecutada fue la auditoría y sus correcciones; la siguiente «Etapa 2» coincide en nombre con la Etapa 2 histórica (limpieza del starter).
- **Manual de instalación, sección 9:** «Pendiente de validar en un servidor IIS (Fase 4)» sigue pendiente (B1), ahora sin fase asignada.
