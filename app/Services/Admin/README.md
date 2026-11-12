# app/Services/Admin

Servicios de los módulos administrativos. Cada escritura usa `DestinoSql::exigirEscritura()` y `Auditor`.

Orden de incorporación (Etapa 5): Usuarios, Mi Perfil, Grupos y membresías, Permisos, Sesiones Activas, Auditoría, Seguimiento de Uso, Dashboard y navegación.

Ya disponibles: `CambiarContrasena` (contraseña propia), `AdministrarUsuarios` (cuentas y datos de contacto propios), `AdministrarGrupos`, `AdministrarMembresias` y `AdministrarPermisos`. `Relaciones` aplica la concurrencia optimista de membresías y permisos.
