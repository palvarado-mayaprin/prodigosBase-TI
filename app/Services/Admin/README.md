# app/Services/Admin

Servicios de los módulos administrativos. Cada escritura usa `DestinoSql::exigirEscritura()` y `Auditor`.

Un módulo nuevo agrega aquí su servicio y sigue las reglas de `CLAUDE.md` (catálogo, ruta protegida, auditoría transaccional y `DestinoSql`).

Ya disponibles: `CambiarContrasena` (contraseña propia), `AdministrarUsuarios` (cuentas y datos de contacto propios), `AdministrarGrupos`, `AdministrarMembresias`, `AdministrarPermisos`, `RegistroSesiones` (registro técnico de sesiones), `AdministrarSesiones` (consulta y revocación), `ConsultaAuditoria` (consulta y exportación de la auditoría) y `SeguimientoUso` (registro y reportes de uso). `Relaciones` aplica la concurrencia optimista de membresías y permisos.
