# app/Auditoria

Única vía de escritura de la auditoría administrativa (`Auditor`), dentro de la misma transacción que el cambio auditado.

- Etapa 4: tabla `adm_auditoria` (cambios en JSON, sin contraseñas ni secretos). Si la auditoría falla, la operación se revierte.
