# Instrucciones permanentes para prodigosBase-TI

prodigosBase-TI es una plantilla administrativa reutilizable: autenticación, usuarios, grupos, permisos, sesiones, auditoría, seguimiento de uso, navegación y sistema visual. No contiene reglas de negocio de ningún sistema concreto.

1. **SQL Server es obligatorio.** La aplicación solo usa la conexión `sqlsrv`. SQLite se usa únicamente en PHPUnit. Las migraciones de Laravel son la única fuente del esquema.
2. **Sin reglas de negocio ajenas.** No trasladar a esta plantilla módulos, tablas, reglas, textos, servidores, bases ni datos de PRODIGOS V2 ni de ningún otro sistema. PRODIGOS V2 es solo referencia de arquitectura y diseño.
3. **Seguridad por defecto.** Denegación por defecto, contraseñas con hash, sesión y usuario activo comprobados en el servidor en cada petición, CSRF y control de origen. Ninguna página administrativa sin autenticación y autorización.
4. **Sin cambios destructivos sin aprobación.** No ejecutar migraciones destructivas, `migrate:fresh`, seeders ni cambios de datos fuera de una base de desarrollo propia.
5. **Sin secretos.** No incluir contraseñas, claves, tokens, cadenas de conexión ni contenido de `.env` en código, documentos, logs ni respuestas. Los datos de prueba son siempre ficticios.
6. **Cambios pequeños y verificables.** Inspeccionar el código real antes de afirmar que algo existe; distinguir evidencia de hipótesis.
7. **Documentar cada cambio significativo** en `docs/HISTORIAL_CAMBIOS.md` y en el manual correspondiente, en el mismo trabajo y en español.
8. **Explicar** los cambios realizados, las pruebas ejecutadas y sus límites.
9. **Decisiones arquitectónicas.** Antes de cambiar la arquitectura aprobada o ampliar el alcance, pedir aprobación.
10. **Sin acciones irreversibles sin autorización.** No hacer commits, push, etiquetas ni despliegues sin autorización expresa.

## Antes de empezar

1. Leer [docs/ia/00-INICIO.md](docs/ia/00-INICIO.md): contexto ampliado y qué documento de `docs/ia/` consultar según la tarea.
2. Revisar [docs/ESTADO_PROYECTO_Y_PENDIENTES.md](docs/ESTADO_PROYECTO_Y_PENDIENTES.md): qué está verificado y qué hallazgos siguen pendientes. Los pendientes no se corrigen sin una instrucción expresa.
3. Comprobar `git status` y los últimos commits.
