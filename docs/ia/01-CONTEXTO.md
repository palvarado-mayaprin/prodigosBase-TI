# Contexto del proyecto

## Objetivo

Ofrecer la base administrativa común de las aplicaciones internas: quien la reutiliza solo añade sus módulos de negocio y hereda acceso, permisos, auditoría, seguimiento, navegación y aspecto visual ya validados. Presentación general en [`README.md`](../../README.md).

## Alcance

- **Incluye:** lo descrito en «Funcionalidades disponibles», sus pruebas y la documentación de instalación, arquitectura, diseño y validación.
- **No incluye:** módulos de negocio, datos reales, recuperación de contraseña por correo, registro público, doble factor ni un despliegue validado en IIS (ver [`06-ESTADO.md`](06-ESTADO.md)).

## Tecnologías

| Capa            | Tecnología (restricción de versión del proyecto)                                                     |
| --------------- | ---------------------------------------------------------------------------------------------------- |
| Backend         | PHP `^8.4.1`, Laravel `^13.17`, Fortify `^1.37.2` (solo inicio y cierre de sesión)                   |
| Puente          | Inertia.js 3 (`inertiajs/inertia-laravel` y `@inertiajs/vue3`), Wayfinder (rutas tipadas)            |
| Frontend        | Vue `^3.5`, TypeScript, Tailwind CSS 4, componentes base shadcn-vue (`reka-ui`), Lucide, SweetAlert2 |
| Herramientas JS | `vite-plus` (compilación, formato con oxfmt y lint con oxlint), `vue-tsc`, `node --test`             |
| Base de datos   | Microsoft SQL Server (única base de la aplicación); SQLite en memoria solo en PHPUnit                |
| Calidad PHP     | PHPUnit, Pint y Larastan                                                                             |

Versiones exactas en `composer.json`, `package.json` y sus archivos de bloqueo. Dependencias y su justificación: [`MANUAL_TECNICO.md`, sección 9](../MANUAL_TECNICO.md#9-dependencias).

## Funcionalidades disponibles

Catálogo actual (`App\Autorizacion\CatalogoPermisos`): área `administracion`, 7 funcionalidades y 13 acciones.

| Funcionalidad (código)                    | Ruta de entrada      | Acciones                              | Solo administrador |
| ----------------------------------------- | -------------------- | ------------------------------------- | ------------------ |
| `administracion.accesos.usuarios`         | `/admin/usuarios`    | VER, CREAR, EDITAR                    | Sí                 |
| `administracion.accesos.grupos`           | `/admin/grupos`      | VER, CREAR, EDITAR                    | Sí                 |
| `administracion.accesos.membresias`       | `/admin/membresias`  | VER (modificar: solo administradores) | No                 |
| `administracion.accesos.permisos`         | `/admin/permisos`    | VER, EDITAR                           | Sí                 |
| `administracion.sesiones.activas`         | `/admin/sesiones`    | VER, ELIMINAR                         | Sí                 |
| `administracion.auditoria.modificaciones` | `/admin/auditoria`   | VER (y exportar)                      | Sí                 |
| `administracion.seguimiento.uso`          | `/admin/seguimiento` | VER (y exportar)                      | Sí                 |

Además, sin permiso del catálogo: inicio y cierre de sesión, cambio de contraseña (obligatorio o desde Mi Perfil), Dashboard (`/dashboard`) y Mi Perfil (`/mi-perfil`). Detalle de cada módulo: [`MANUAL_TECNICO.md`, sección 6](../MANUAL_TECNICO.md#6-módulos-administrativos).

## Comandos propios

| Comando                                | Uso                                                                        |
| -------------------------------------- | -------------------------------------------------------------------------- |
| `php artisan adm:entorno`              | Diagnóstico de solo lectura del destino SQL y la escritura                 |
| `php artisan adm:sincronizar-permisos` | Carga el catálogo en la base; idempotente, nunca borra                     |
| `php artisan adm:crear-administrador`  | Crea la primera cuenta administradora con cambio de contraseña obligatorio |

## Documentación de referencia

[Manual de instalación](../MANUAL_INSTALACION_Y_CONFIGURACION.md), [manual técnico](../MANUAL_TECNICO.md), [arquitectura SQL Server](../ARQUITECTURA_SQL_SERVER.md), [guía de diseño](../GUIA_DISENO_UI.md), [validación](../VALIDACION_SQL_SERVER.md) e [historial](../HISTORIAL_CAMBIOS.md).
