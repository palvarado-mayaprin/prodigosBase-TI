# Estado para asistentes

La fuente es [`ESTADO_PROYECTO_Y_PENDIENTES.md`](../ESTADO_PROYECTO_Y_PENDIENTES.md): qué está verificado (y cómo), qué está solo documentado y qué falta. Leerlo antes de cualquier cambio y, al terminar un trabajo que cambie el estado, actualizarlo junto con [`HISTORIAL_CAMBIOS.md`](../HISTORIAL_CAMBIOS.md).

## Corregido

Los hallazgos A1, A2, A4, A5, A6, A8, A9 y A10 de la auditoría de la Fase 4 están corregidos, cada uno con su prueba o validación. No revertir esas correcciones: en particular, Inertia DevTools debe seguir deshabilitado, las autorizaciones de DDL solo valen como variables de proceso y `/up` no existe.

## Pendientes que no se corrigen por iniciativa propia

Requieren una instrucción expresa. Si una tarea los roza, informarlo en lugar de corregirlos:

- **A3:** la cuenta de despliegue puede modificar y borrar `adm_auditoria`; el manual técnico (sección 5) afirma lo contrario.
- **A7:** no se envían cabeceras HTTP de seguridad.
- **A11:** los documentos indican SQL Server 2016 o superior, pero Laravel 13 declara 2017 o superior.

## Riesgos sin verificar

- **B1 y B2:** IIS y producción nunca se han probado; no afirmar que el despliegue está validado.
- **B3:** sin proxies de confianza configurados.
- **B4:** el CI nunca se ha ejecutado en un remoto.
- **B5:** solo se probó SQL Server 2022.
- Mejoras opcionales C1 a C4: solo con aprobación.

## Al empezar una tarea

1. `git status` y `git log --oneline -5`: si hay cambios sin confirmar de otro trabajo, preguntar antes de seguir.
2. Comprobar en `ESTADO_PROYECTO_Y_PENDIENTES.md` y en el historial si la tarea ya se hizo o choca con un pendiente.
3. Al terminar, informar lo hecho, las pruebas con su resultado real y lo que queda pendiente.
