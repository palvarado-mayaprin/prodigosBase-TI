# prodigosBase-TI

Plantilla administrativa reutilizable para aplicaciones internas sobre **Laravel 13, Vue 3, TypeScript, Inertia.js, Tailwind CSS y Microsoft SQL Server**. Toma como referencia la arquitectura administrativa y el lenguaje visual de PRODIGOS V2, sin sus reglas de negocio, datos ni infraestructura.

## Estado

**Etapa 5.3 completada:** control de entornos, esquema SQL Server, autenticación, autorización, auditoría y los módulos de Usuarios, Mi Perfil, Grupos, Usuarios y grupos, Permisos y Sesiones Activas, con el subconjunto correspondiente del sistema visual. Faltan Auditoría, Seguimiento de Uso, Dashboard y navegación.

| Etapa | Contenido                                                                                             | Estado     |
| ----- | ----------------------------------------------------------------------------------------------------- | ---------- |
| 1     | Proyecto base y configuración                                                                         | Completada |
| 2     | Dependencias definitivas, limpieza del starter kit, control de entornos (`DestinoSql`, `adm:entorno`) | Completada |
| 3     | Esquema SQL Server (migraciones `adm_*`, roles SQL, control de DDL)                                   | Completada |
| 4     | Autenticación, catálogo de permisos y auditoría                                                       | Completada |
| 5     | Módulos administrativos                                                                               | En curso   |
| 6     | Sistema visual `adm-` con paleta configurable                                                         | Pendiente  |
| 7–9   | Limpieza, verificación integral y documentación final                                                 | Pendiente  |

## Alcance previsto

Autenticación, Usuarios, Mi Perfil, Grupos y membresías, Permisos, Sesiones Activas, Auditoría de Modificaciones, Seguimiento de Uso, Dashboard y navegación dinámica, control centralizado de entornos, exportaciones CSV/XLSX y sistema visual con alertas reutilizables.

## Inicio rápido

Ver [docs/MANUAL_INSTALACION_Y_CONFIGURACION.md](docs/MANUAL_INSTALACION_Y_CONFIGURACION.md).

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
npm run build
composer test
```

## Documentación y reglas

- [AGENTS.md](AGENTS.md) y [CLAUDE.md](CLAUDE.md): reglas permanentes y arquitectónicas.
- [docs/MANUAL_TECNICO.md](docs/MANUAL_TECNICO.md): autenticación, autorización, auditoría y comandos.
- [docs/ARQUITECTURA_SQL_SERVER.md](docs/ARQUITECTURA_SQL_SERVER.md): modelo de datos, permisos SQL y pruebas de integración.
- [docs/HISTORIAL_CAMBIOS.md](docs/HISTORIAL_CAMBIOS.md): historial.
