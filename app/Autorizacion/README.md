# app/Autorizacion

Catálogo centralizado de permisos (`CatalogoPermisos`) y cálculo de autorización (`Autorizacion`): usuarios, grupos, funcionalidades y acciones (`VER`, `CREAR`, `EDITAR`, `ELIMINAR`).

- Etapa 4: catálogo con el área `administracion`, middleware `AutorizarPermiso` y `AutorizarAdministrador`, y el comando `adm:sincronizar-permisos`.
- Denegación por defecto. Toda ruta declara su permiso del catálogo.
