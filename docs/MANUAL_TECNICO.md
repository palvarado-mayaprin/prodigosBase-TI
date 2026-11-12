# Manual técnico

Arquitectura de la aplicación. La instalación está en [MANUAL_INSTALACION_Y_CONFIGURACION.md](MANUAL_INSTALACION_Y_CONFIGURACION.md) y el modelo de datos en [ARQUITECTURA_SQL_SERVER.md](ARQUITECTURA_SQL_SERVER.md).

## 1. Cadena de una petición web

```
ControlarAcceso (origen, destino SQL, bloqueo de mutaciones)
  → sesión, CSRF, auth (Fortify / Usuario)
  → ExigirCambioContrasena
  → AutorizarPermiso::para('codigo:ACCION') | AutorizarAdministrador
  → controlador → servicio → DestinoSql::exigirEscritura() → transacción → Auditor
```

`ControlarAcceso` va al inicio del grupo `web`; `ExigirCambioContrasena` al final. Los dos se aplican a todas las rutas web sin declararlos en cada una.

## 2. Entorno y escritura

`App\Entorno\DestinoSql` es la única autoridad sobre el destino SQL Server de la conexión predeterminada (`DB_CONNECTION`) y sobre la escritura. `App\Entorno\GuardaDdl` autoriza las migraciones por separado. Detalle en el manual de instalación, sección 3.

## 3. Autenticación

- **Modelo:** `App\Models\Usuario` sobre `adm_usuarios`. Contraseña con cast `hashed`; sin `remember_token`.
- **Fortify:** solo inicio y cierre de sesión (`features => []`, `Fortify::ignoreRoutes()`; rutas en `routes/web.php`). Autentica por `usuario` y contraseña (`FortifyServiceProvider::authenticateUsing`):
    - rechaza cuentas inexistentes o inactivas y «recordarme»;
    - compara siempre con un hash (uno ficticio si la cuenta no existe) para no revelar por el tiempo de respuesta si existe;
    - 5 intentos por minuto por usuario e IP; CSRF y regeneración de la sesión los aplican Laravel y Fortify;
    - no escribe en la base: funciona con la producción en solo lectura.
- **Política de contraseñas** (`Password::defaults()` en `AppServiceProvider`): 12 a 72 caracteres (bcrypt usa los primeros 72 bytes), letras, mayúsculas y minúsculas, números y símbolos, en todos los entornos.
- **Cambio obligatorio:** con `debe_cambiar_password = 1`, `ExigirCambioContrasena` solo permite `contrasena.editar`, `contrasena.actualizar` y `logout`; redirige el resto o responde 403 en JSON. `App\Services\Admin\CambiarContrasena` comprueba la contraseña actual, exige una distinta, actualiza el hash, libera la marca y audita en la misma transacción; después se regenera la sesión.

## 4. Autorización

- **Catálogo** (`App\Autorizacion\CatalogoPermisos`): área → módulo → funcionalidad, código `area.modulo.funcionalidad` en camelCase y acciones `VER`, `CREAR`, `EDITAR` y `ELIMINAR`. Se valida al registrar las rutas: un permiso mal escrito impide arrancar. Contiene solo funcionalidades administrativas.
- **Cálculo** (`App\Autorizacion\Autorizacion`):
    - relee en cada petición la cuenta (cierra la sesión si está inactiva) y sus grupos **activos**; la memoria dura solo la petición;
    - un grupo activo con `es_administrador = 1` tiene acceso universal; no hay identificadores de grupo especiales;
    - deniega por defecto; los demás grupos solo obtienen las acciones asignadas en `adm_grupos_permisos` sobre funcionalidades activas;
    - las funcionalidades `soloAdministrador` no se obtienen por asignación;
    - `rutasPermitidas()` alimentará el menú y el Dashboard (solo presentación);
    - `quedariaSinAdministradores()` protege del último administrador activo; se usa dentro de la transacción del cambio y bloquea las membresías administrativas.
- **Middleware:** `AutorizarPermiso::para('codigo:ACCION', …)` (basta uno) y `AutorizarAdministrador`. Un fallo de SQL responde 503.

### 4.1 Registrar una funcionalidad nueva

1. Declararla en `CatalogoPermisos::areas()`.
2. Proteger sus rutas con `AutorizarPermiso::para()` o `AutorizarAdministrador`.
3. Ejecutar `php artisan adm:sincronizar-permisos` en cada entorno.
4. Asignar sus acciones a los grupos desde Administración de Permisos (Etapa 5).

## 5. Auditoría

`App\Auditoria\Auditor::registrar($db, $entidad, $registro, $accion, $cambios)`:

- exige una transacción abierta en la conexión del cambio; si la auditoría falla, se revierte todo;
- usuario e IP salen de la sesión y la petición (vacíos desde la consola); la fecha la pone SQL Server en UTC;
- acciones de la lista cerrada (`CREAR`, `EDITAR`, `HABILITAR`, `DESHABILITAR`, `ASIGNAR`, `RETIRAR`, `REVOCAR`);
- enmascara (`********`) los valores de claves que contienen `password`, `contrasena`, `clave`, `token`, `secret` o `secreto`, también anidadas.

## 6. Módulos administrativos

### 6.1 Administración de Usuarios

- **Rutas** (`administracion.accesos.usuarios`, `soloAdministrador`): `GET admin/usuarios` (VER), `POST admin/usuarios` (CREAR), `PUT admin/usuarios/{usuario}` y `PUT admin/usuarios/{usuario}/estado` (EDITAR).
- **Servicio** `App\Services\Admin\AdministrarUsuarios`: cada operación en una transacción con `DestinoSql::exigirEscritura()` y `Auditor`.
    - alta con hash y `debe_cambiar_password = 1`; el nombre de usuario (letras, números, `.`, `_`, `-`) no se edita después;
    - edición de nombre, apellidos, correo y teléfono; una contraseña nueva asignada por el administrador vuelve a obligar a cambiarla; vacía conserva la actual;
    - usuario y correo únicos: comprobación legible dentro de la transacción y, ante una carrera, la restricción única se informa como error de validación;
    - las cuentas no se eliminan: `HABILITAR` y `DESHABILITAR`. Nadie deshabilita su propia cuenta y nunca se deja el sistema sin un administrador activo (`Autorizacion::quedariaSinAdministradores`);
    - la auditoría registra solo los campos cambiados y enmascara la contraseña.
- **Pantalla** `admin/Usuarios.vue`: búsqueda y orden locales, formulario de alta y edición, confirmación de cambio de estado y mensajes con `lib/alertas.ts`.

### 6.2 Mi Perfil

- **Rutas** (cualquier cuenta activa, sin permiso del catálogo): `GET mi-perfil`, `PUT mi-perfil` (correo y teléfono) y `PUT mi-perfil/contrasena` (6 intentos por minuto, contador propio).
- La cuenta sale solo de la sesión; se ignoran identificadores y campos no permitidos. El cambio de contraseña reutiliza `CambiarContrasena` y regenera la sesión.
- **Pantalla** `MiPerfil.vue`: datos de solo lectura, contacto editable y cambio de contraseña con los requisitos en vivo (`lib/politicaContrasena.ts`, mismas expresiones que el servidor).

### 6.3 Administración de Grupos

- **Rutas** (`administracion.accesos.grupos`, `soloAdministrador`): `GET admin/grupos` (VER), `POST admin/grupos` (CREAR), `PUT admin/grupos/{grupo}` y `PUT admin/grupos/{grupo}/estado` (EDITAR).
- **Servicio** `App\Services\Admin\AdministrarGrupos`:
    - alta, edición de nombre y descripción, activación y desactivación (`HABILITAR`/`DESHABILITAR`); los grupos no se eliminan;
    - nombre único (comprobación en la transacción y restricción única como garantía final);
    - `es_administrador` se fija al crear y no se edita;
    - un grupo administrador no se desactiva si es el único que da la administración a quien actúa ni si el sistema quedaría sin administradores activos (`quedariaSinAdministradores($db, grupoId: …)`).
- **Pantalla** `admin/Grupos.vue`: búsqueda local, formulario de alta y edición, confirmación de cambio de estado y detalle de consulta con integrantes y permisos.

### 6.4 Usuarios y grupos (membresías)

- **Rutas** (`administracion.accesos.membresias`, asignable): `GET admin/membresias` exige VER; `PUT admin/membresias/{usuario}` exige un grupo administrador (`AutorizarAdministrador`).
- **Servicio** `App\Services\Admin\AdministrarMembresias::sincronizarGruposDeUsuario($actor, $usuario, $base, $grupos)`:
    - concurrencia optimista (`Relaciones::diferencias`): si las membresías actuales ya no coinciden con la `base` que leyó la pantalla, responde el error `conflicto` y no guarda nada; una inserción concurrente duplicada (clave primaria) también se informa como conflicto;
    - a un grupo inactivo no se agrega a nadie; sí se retira;
    - nadie retira su propia capacidad administrativa ni deja el sistema sin administradores activos;
    - cada alta y retiro se audita (`ASIGNAR`/`RETIRAR`, entidad `adm_usuarios_grupos`, registro `usuario:grupo`).
- **Pantalla** `admin/Membresias.vue`: modalidades Usuarios → Grupos (edición con cambios pendientes, confirmación y recarga) y Grupos → Usuarios (consulta), como en PRODIGOS V2. Quien solo tiene VER la abre en modo consulta.

### 6.5 Administración de Permisos

- **Rutas** (`administracion.accesos.permisos`, `soloAdministrador`): `GET admin/permisos` (VER) y `PUT admin/permisos/{grupo}` (EDITAR).
- **Servicio** `App\Services\Admin\AdministrarPermisos::sincronizar($grupo, $base, $permisos)`:
    - solo asigna permisos sincronizados que estén declarados en `CatalogoPermisos`, de funcionalidades activas y no `soloAdministrador`; retirar se permite siempre (limpia asignaciones de funcionalidades retiradas);
    - los permisos de un grupo administrador no se editan (acceso universal);
    - misma concurrencia optimista y auditoría (`adm_grupos_permisos`, registro `grupo:permiso`, valor `codigo:ACCION`).
- **Pantalla** `admin/Permisos.vue`: árbol área → módulo → funcionalidad con búsqueda, contadores, indicadores de cambios pendientes, panel expandible y confirmación con el detalle de los cambios.
- La denegación por defecto no cambia: `Autorizacion` solo concede lo asignado a grupos activos.

### 6.6 Base compartida

- **Idioma:** `lang/es` (validación y autenticación) y `es` como idioma por defecto.
- **Sistema visual:** `resources/css/tema/tokens.css` (único archivo con colores, `--adm-*`) y `componentes.css` (medidas de PRODIGOS V2 con prefijo `adm-`). Incluye contenido, formularios, botones, tablas, avisos, insignias, diálogos y, desde la Etapa 5.2, el selector de modalidad, las listas de selección, las opciones de grupo, los indicadores de cambios y el árbol de permisos; la Etapa 6 completa el resto.
- **Alertas:** `resources/js/lib/alertas.ts` (SweetAlert2 con carga diferida); los colores de los iconos se leen de los tokens.
- **Selección:** `resources/js/lib/seleccion.ts` calcula agregados, retirados y cambios pendientes de membresías y permisos.

## 7. Comandos

| Comando                    | Función                                                                                                                               |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `adm:entorno`              | Diagnóstico de solo lectura del destino y la escritura                                                                                |
| `adm:sincronizar-permisos` | Sincroniza el catálogo; idempotente y no destructivo; audita cada cambio                                                              |
| `adm:crear-administrador`  | Cuenta administradora, grupo administrador (si falta) y membresía en una transacción; contraseña temporal oculta y cambio obligatorio |

Los comandos que escriben usan `DestinoSql::exigirEscritura()`: en producción requieren `ADM_PRODUCCION_ESCRITURA=true`.

## 8. Pruebas

Se ejecutan con SQLite en memoria y las mismas migraciones (`RefreshDatabase`). `tests/Concerns/CreaDatosAdministrativos.php` crea usuarios, grupos y asignaciones ficticios. `tests/js` contiene pruebas de la lógica del frontend (`npm run test:js`) y `tests/Feature/Visual/PaletaTest.php` vigila que no haya colores fuera de los tokens. Las validaciones propias de SQL Server están en el grupo `sqlsrv` (ver [ARQUITECTURA_SQL_SERVER.md](ARQUITECTURA_SQL_SERVER.md#6-pruebas)).
