# Contexto para asistentes de IA: inicio

Punto de entrada para un asistente de inteligencia artificial que vaya a continuar el desarrollo de prodigosBase-TI sin conocer conversaciones anteriores. Todo lo necesario está en el repositorio; este paquete resume y enlaza, no sustituye a los manuales ni al código.

## Qué es y qué no es

- **Es** una plantilla administrativa reutilizable: autenticación, usuarios, grupos, permisos, sesiones, auditoría, seguimiento de uso, navegación y sistema visual, sobre Laravel, Vue, Inertia y SQL Server. Los proyectos derivados añaden sus propios módulos encima.
- **No es** un sistema de negocio: no contiene reglas, tablas, módulos ni datos de PRODIGOS V2 ni de ningún otro sistema. PRODIGOS V2 solo fue referencia de arquitectura y diseño.

## Orden de lectura

1. [`AGENTS.md`](../../AGENTS.md) y [`CLAUDE.md`](../../CLAUDE.md): reglas obligatorias. Prevalecen sobre este paquete.
2. Este documento.
3. [`06-ESTADO.md`](06-ESTADO.md) y [`ESTADO_PROYECTO_Y_PENDIENTES.md`](../ESTADO_PROYECTO_Y_PENDIENTES.md): qué está verificado y qué no debe tocarse. **Siempre antes de modificar algo.**
4. Los documentos de la tabla siguiente según la tarea.
5. El código afectado: los documentos describen la implementación, pero el código es la fuente de verdad. Si no coinciden, informar la discrepancia antes de actuar.

| Documento                                  | Contenido                                                                           | Leer para                                    |
| ------------------------------------------ | ----------------------------------------------------------------------------------- | -------------------------------------------- |
| [`01-CONTEXTO.md`](01-CONTEXTO.md)         | Objetivos, alcance, tecnologías y funcionalidades disponibles                       | Cualquier tarea, la primera vez              |
| [`02-ARQUITECTURA.md`](02-ARQUITECTURA.md) | Backend, frontend, base de datos y recorrido de una petición                        | Cambios de código e investigación de errores |
| [`03-CONVENCIONES.md`](03-CONVENCIONES.md) | KISS, nombres, estilo, lenguaje visual y control de versiones                       | Cualquier cambio de código o de interfaz     |
| [`04-SEGURIDAD.md`](04-SEGURIDAD.md)       | Autenticación, autorización, sesiones, auditoría, privilegios SQL y entornos        | Permisos, sesiones, SQL, despliegue          |
| [`05-DESARROLLO.md`](05-DESARROLLO.md)     | Crear módulos, catálogo, rutas, páginas, componentes comunes y qué pruebas ejecutar | Funcionalidades nuevas y cambios             |
| [`06-ESTADO.md`](06-ESTADO.md)             | Hallazgos corregidos, pendientes que no se corrigen solos y riesgos                 | Antes de empezar cualquier tarea             |

## Reglas que más se olvidan

- No hacer commits, push, despliegues, migraciones ni operaciones destructivas sin autorización explícita.
- Nunca leer, mostrar ni pedir `.env`, contraseñas ni datos reales; los datos de prueba son ficticios.
- No corregir hallazgos pendientes (A3, A7, A11) ni ampliar el alcance por iniciativa propia.
- Toda escritura pasa por un servicio con `DestinoSql::exigirEscritura()`, transacción y `Auditor`.
- Toda ruta exige un permiso del catálogo o el grupo administrador.
- Colores solo en `resources/css/tema/tokens.css`; interfaz en español y con trato de usted.
- Ejecutar las pruebas proporcionales al cambio ([`05-DESARROLLO.md`](05-DESARROLLO.md#qué-pruebas-ejecutar)) e informar el resultado real.
