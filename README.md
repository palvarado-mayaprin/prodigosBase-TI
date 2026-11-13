# prodigosBase-TI

Plantilla administrativa reutilizable para aplicaciones internas sobre **Laravel 13, Vue 3, TypeScript, Inertia.js, Tailwind CSS y Microsoft SQL Server**. Toma como referencia la arquitectura administrativa y el lenguaje visual de PRODIGOS V2, sin sus reglas de negocio, datos ni infraestructura.

## Estado

**Etapa 7 completada:** control de entornos, esquema SQL Server, autenticación, autorización, auditoría y los módulos de Usuarios, Mi Perfil, Grupos, Usuarios y grupos, Permisos, Sesiones Activas, Auditoría de Modificaciones y Seguimiento de Uso, con el subconjunto correspondiente del sistema visual. Incluye el Dashboard, la navegación dinámica según los permisos de cada cuenta y el sistema visual consolidado (inicio de sesión incluido), con la paleta como único aspecto personalizable.

| Etapa | Contenido                                                                                             | Estado     |
| ----- | ----------------------------------------------------------------------------------------------------- | ---------- |
| 1     | Proyecto base y configuración                                                                         | Completada |
| 2     | Dependencias definitivas, limpieza del starter kit, control de entornos (`DestinoSql`, `adm:entorno`) | Completada |
| 3     | Esquema SQL Server (migraciones `adm_*`, roles SQL, control de DDL)                                   | Completada |
| 4     | Autenticación, catálogo de permisos y auditoría                                                       | Completada |
| 5     | Módulos administrativos                                                                               | Completada |
| 6     | Sistema visual `adm-` con paleta configurable                                                         | Completada |
| 7     | Limpieza y desacoplamiento                                                                            | Completada |
| 8–9   | Verificación integral y documentación final                                                           | Pendiente  |

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
- [docs/GUIA_DISENO_UI.md](docs/GUIA_DISENO_UI.md): lenguaje visual, clases `adm-*`, patrones y cambio de paleta.
- [docs/ARQUITECTURA_SQL_SERVER.md](docs/ARQUITECTURA_SQL_SERVER.md): modelo de datos, permisos SQL y pruebas de integración.
- [docs/HISTORIAL_CAMBIOS.md](docs/HISTORIAL_CAMBIOS.md): historial.
