# prodigosBase-TI

Plantilla administrativa reutilizable para aplicaciones internas sobre **Laravel 13, Vue 3, TypeScript, Inertia.js, Tailwind CSS y Microsoft SQL Server**. Aporta autenticación, usuarios, grupos, permisos, sesiones, auditoría, seguimiento de uso, navegación dinámica y un sistema visual con paleta personalizable. No contiene reglas de negocio de ningún sistema: toma como referencia la arquitectura administrativa y el lenguaje visual de PRODIGOS V2, sin sus datos, módulos ni infraestructura.

## Contenido

- **Acceso:** inicio de sesión por usuario, cambio de contraseña obligatorio, política de contraseñas, límite de intentos y páginas de error en español.
- **Administración:** Usuarios, Mi Perfil, Grupos, Usuarios y grupos (membresías), Permisos, Sesiones Activas, Auditoría de Modificaciones y Seguimiento de Uso, con exportaciones CSV y XLSX.
- **Seguridad:** denegación por defecto con un catálogo de permisos único, auditoría transaccional sin secretos, revocación de sesiones, control centralizado del destino SQL y de la escritura (`DestinoSql`) y de las migraciones (`GuardaDdl`).
- **Interfaz:** Dashboard y menú generados desde el catálogo, clases `adm-*`, alertas reutilizables, accesibilidad con teclado y colores solo en `tokens.css`.

## Estado

| Fase / etapa | Contenido                                                                                                           | Estado      |
| ------------ | ------------------------------------------------------------------------------------------------------------------- | ----------- |
| 1–7          | Proyecto base, entornos, esquema SQL Server, autenticación, permisos, auditoría, módulos, sistema visual y limpieza | Completadas |
| 8            | Validación en SQL Server real, en navegador, de sesiones, de concurrencia y de seguridad                            | Completada  |
| 9            | Documentación definitiva e instalación limpia reproducible                                                          | Completada  |
| Fase 4       | Auditoría independiente y corrección de A1, A2, A4, A5, A6, A8, A9 y A10                                            | Completada  |
| Publicación  | A3, A7 y A11, IIS, producción y versión 1.0.0 ([estado y pendientes](docs/ESTADO_PROYECTO_Y_PENDIENTES.md))         | Pendiente   |

## Inicio rápido

Requisitos: Windows, PHP 8.4 (`^8.4.1`) con `pdo_sqlsrv`, Composer 2, Node 24, SQL Server 2016 o superior y `sqlcmd`.

```powershell
composer install
npm ci
npm run build
Copy-Item .env.example .env
php artisan key:generate
composer test
```

La aplicación necesita después su base SQL Server, sus cuentas, las migraciones y el primer administrador: seguir el [manual de instalación](docs/MANUAL_INSTALACION_Y_CONFIGURACION.md) de principio a fin.

## Documentación

| Documento                                                                           | Para qué                                                                                        |
| ----------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| [MANUAL_INSTALACION_Y_CONFIGURACION.md](docs/MANUAL_INSTALACION_Y_CONFIGURACION.md) | Instalar y configurar desde cero: SQL Server, `.env`, migraciones, administrador, pruebas e IIS |
| [MANUAL_TECNICO.md](docs/MANUAL_TECNICO.md)                                         | Arquitectura, carpetas, autorización, auditoría, módulos y cómo registrar funcionalidades       |
| [ARQUITECTURA_SQL_SERVER.md](docs/ARQUITECTURA_SQL_SERVER.md)                       | Tablas, relaciones, roles SQL y control de migraciones                                          |
| [GUIA_DISENO_UI.md](docs/GUIA_DISENO_UI.md)                                         | Lenguaje visual, clases `adm-*`, patrones, accesibilidad y cambio de paleta                     |
| [VALIDACION_SQL_SERVER.md](docs/VALIDACION_SQL_SERVER.md)                           | Pruebas sobre SQL Server real y con Playwright, y resultados de cada validación                 |
| [ESTADO_PROYECTO_Y_PENDIENTES.md](docs/ESTADO_PROYECTO_Y_PENDIENTES.md)             | Estado verificado, hallazgos pendientes, riesgos y condiciones para publicar                    |
| [HISTORIAL_CAMBIOS.md](docs/HISTORIAL_CAMBIOS.md)                                   | Historial por etapa                                                                             |
| [AGENTS.md](AGENTS.md) y [CLAUDE.md](CLAUDE.md)                                     | Reglas permanentes y arquitectónicas, también para asistentes de código                         |
