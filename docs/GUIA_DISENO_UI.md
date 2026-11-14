# Guía de diseño de interfaz

Lenguaje visual de prodigosBase-TI, trasladado de las pantallas administrativas de PRODIGOS V2 con las mismas medidas, jerarquías, tipografía y comportamiento. **El único aspecto personalizable es la paleta de colores.** Esta guía permite construir pantallas nuevas sin rediseñar nada.

## 1. Principios

1. **Una sola fuente de color:** `resources/css/tema/tokens.css`. Ningún otro archivo contiene colores literales (hexadecimales, `rgb()`, `hsl()`, `oklch()`) ni clases de color de Tailwind (`text-red-600`, `bg-white`…). `tests/Feature/Visual/PaletaTest.php` lo verifica en todo el frontend.
2. **Estructura fija:** medidas, espaciados, radios, tipografía y comportamiento están en `resources/css/tema/componentes.css` (prefijo `adm-`) y no se personalizan.
3. **Reutilizar antes que crear:** una pantalla nueva se compone con las clases `adm-` y los componentes existentes. Una clase nueva solo se agrega si ningún patrón existente resuelve el caso, y siempre con tokens.
4. **Paleta única y clara:** no hay modo oscuro. Las variantes `dark:` de los componentes base quedan inactivas.

## 2. Archivos del sistema visual

| Archivo                                         | Contenido                                                                                                                                                                    |
| ----------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `resources/css/tema/tokens.css`                 | Paleta (tokens `--adm-*`). Único archivo con colores                                                                                                                         |
| `resources/css/tema/componentes.css`            | Clases `adm-*`, medidas estructurales (`--adm-fuente`, `--adm-radio-*`, `--adm-transicion`) y colores derivados con `color-mix` (`--adm-sombra`, `--adm-foco`, `--adm-velo`) |
| `resources/css/app.css`                         | Tailwind y variables de los componentes base (shadcn-vue: botones, diálogos, menú, avisos emergentes), todas derivadas de los tokens                                         |
| `resources/js/lib/alertas.ts`                   | Confirmaciones, carga y mensajes con SweetAlert2                                                                                                                             |
| `resources/js/lib/fechas.ts`                    | Fechas UTC en la zona configurada, días y duraciones                                                                                                                         |
| `resources/js/lib/iconos.ts`                    | Iconos de Lucide que puede declarar el catálogo de permisos                                                                                                                  |
| `resources/js/layouts/app/AppSidebarLayout.vue` | Estructura de las pantallas autenticadas                                                                                                                                     |
| `resources/js/layouts/auth/AuthSplitLayout.vue` | Inicio de sesión y cambio de contraseña                                                                                                                                      |
| `docs/ejemplos/paleta-ejemplo.css`              | Segunda paleta, solo para verificar la personalización                                                                                                                       |

## 3. Estructura de las páginas

```
┌ Menú lateral (.adm-sidebar) ┐┌ Barra superior (.adm-topbar): ☰  Inicio › Página ──────┐
│ Marca (APP_NAME)            ││ .adm-main                                                │
│ Buscador del menú           ││   Encabezado: .adm-page-title / -subtitle / -note        │
│ GENERAL · Inicio            ││   Avisos de modo (.adm-alert-warning)                    │
│ ÁREA                        ││   Tarjetas .adm-card: filtros, tabla, formulario…        │
│   Módulo                    ││   Exportaciones (al final)                               │
│   · Funcionalidad           ││                                                          │
│ Usuario (menú)              ││                                                          │
└─────────────────────────────┘└──────────────────────────────────────────────────────────┘
```

- **Layout:** toda página autenticada usa `AppLayout` (asignado por `app.ts`); las de `auth/`, `AuthLayout`.
- **Migas de pan:** cada página declara `defineOptions({ layout: { breadcrumbs: [{ title: 'Inicio', href: dashboard() }, { title: 'Nombre', href: ruta() }] } })`. La barra superior las muestra.
- **Menú lateral y Dashboard:** se generan solos desde `CatalogoPermisos` y los permisos de la cuenta (prop compartida `navegacion`). Una funcionalidad nueva con `ruta` e `icono` aparece sin tocar el frontend.
- **Contenido:** un único contenedor raíz `<div class="adm-main min-h-full"><div class="w-full min-w-0">…</div></div>`.
- **Encabezado de página:**

```html
<div class="mb-6">
    <h1 class="adm-page-title">ADMINISTRACIÓN DE EJEMPLO</h1>
    <p class="adm-page-subtitle">Descripción breve</p>
    <p class="adm-page-note mt-3 text-sm">
        Reglas que el usuario debe conocer.
    </p>
</div>
```

## 4. Jerarquía visual y tipografía

Fuente única: **Instrument Sans** (`--adm-fuente`).

| Elemento                      | Clase                             | Medida                       |
| ----------------------------- | --------------------------------- | ---------------------------- |
| Título de página (MAYÚSCULAS) | `.adm-page-title`                 | 26 px, 700 (22 px en móvil)  |
| Subtítulo                     | `.adm-page-subtitle`              | 14 px                        |
| Bienvenida del Dashboard      | `.adm-welcome-title`              | 30 px, 800                   |
| Nombre en el inicio de sesión | `.adm-login-title`                | 48 px, 800                   |
| Título de tarjeta             | `.adm-card-title`                 | 16 px, 600                   |
| Etiqueta de área / antetítulo | `.adm-area-title`, `.adm-eyebrow` | 10–12 px, mayúsculas         |
| Etiqueta de campo             | `.adm-label`                      | 13 px, 500                   |
| Cuerpo de tabla / encabezado  | `.adm-table`                      | 13 px / 12 px                |
| Valor de indicador            | `.adm-stat-value`                 | 28 px, 700, cifras tabulares |
| Texto secundario              | `.adm-muted`, `.adm-page-note`    | color suave                  |

Radios: 6, 8 y 12 px (`--adm-radio-sm|md|lg`). Controles y botones: 42 px de alto mínimo; en tablas, 32 px.

## 5. Tokens de color

| Grupo        | Tokens                                                                                           | Uso                                                                |
| ------------ | ------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------ |
| Marca        | `--adm-color-marca`, `--adm-color-primario`, `--adm-color-acento`                                | Títulos; botón principal y elemento activo; foco y bordes al pasar |
| Superficies  | `--adm-fondo`, `--adm-superficie`, `--adm-superficie-hover`, `--adm-seleccion`, `--adm-borde`    | Fondo de página, tarjetas, hover, selección, bordes                |
| Menú lateral | `--adm-menu-fondo`, `--adm-menu-texto`, `--adm-menu-texto-suave`, `--adm-menu-activo`            | Menú lateral y panel de marca del inicio de sesión                 |
| Texto        | `--adm-texto`, `--adm-texto-suave`, `--adm-texto-inverso`                                        | Texto principal, secundario y sobre fondos oscuros                 |
| Estados      | `--adm-exito`, `--adm-advertencia`, `--adm-peligro` y sus variantes `-fondo`, `-borde`, `-texto` | Avisos, insignias, iconos de SweetAlert2, errores                  |

Derivan de los tokens, sin colores propios:

- las variables de los componentes base (`--primary`, `--border`, `--sidebar`…, en `app.css`);
- la sombra, el anillo de foco y el velo de los diálogos (`componentes.css`);
- los iconos y el fondo de SweetAlert2;
- la barra de progreso de Inertia (`var(--adm-color-acento)` en `app.ts`);
- el fondo inicial del documento (`app.blade.php`).

## 6. Clases `adm-*`

| Familia                | Clases                                                                                                                                                                                                                                                                                                                                    |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Contenido              | `adm-saltar`, `adm-contenido`, `adm-main`, `adm-page-title`, `adm-page-subtitle`, `adm-page-note`, `adm-muted`, `adm-card`, `adm-card-title`, `adm-requerido`                                                                                                                                                                             |
| Formularios            | `adm-label`, `adm-input` (también `select`), `adm-checkbox`, `adm-field-error`, `adm-requisito-cumplido`                                                                                                                                                                                                                                  |
| Botones                | `adm-btn` + `adm-btn-primary`, `-secondary`, `-neutral`, `-danger`; `adm-icon-btn`                                                                                                                                                                                                                                                        |
| Tablas                 | `adm-table-scroll`, `adm-table` (`tr.is-inactive`, `.is-num`, `td.is-wrap`), `adm-table-actions`, `adm-table-pagination`                                                                                                                                                                                                                  |
| Avisos e insignias     | `adm-alert` + `-info`, `-success`, `-warning`, `-danger`; `adm-badge` + `-neutral`, `-success`, `-warning`, `-danger`                                                                                                                                                                                                                     |
| Selección              | `adm-view-switch(-button)`, `adm-access-card`, `adm-access-list`, `adm-access-groups`, `adm-user-item`, `adm-member-item`, `adm-group-option`, `adm-identity(-text)`, `adm-avatar`, `adm-count-tag`, `adm-change-chip` (`is-add`, `is-remove`, `is-warning`, `is-clear`), `adm-group-filter`, `adm-selected-header`, `adm-selected-title` |
| Árbol de permisos      | `adm-tree-area`, `adm-tree-area-toggle`, `adm-tree-module-toggle`, `adm-tree-count`, `adm-tree-feature(-description)`                                                                                                                                                                                                                     |
| Indicadores y reportes | `adm-stat-card`, `adm-stat-label`, `adm-stat-value`, `adm-chart`, `adm-chart-bar`, `adm-meter`, `adm-details-summary`, `adm-export-item`                                                                                                                                                                                                  |
| Navegación             | `adm-sidebar`, `adm-sidebar-header`, `adm-brand-link`, `adm-brand-wordmark`, `adm-brand-monogram`, `adm-menu-search(-icon, -input)`, `adm-menu-empty`, `adm-sidebar-section`, `adm-sidebar-module`, `adm-nav-link`, `adm-topbar`                                                                                                          |
| Dashboard              | `adm-eyebrow`, `adm-welcome-title`, `adm-area-title`, `adm-module-card(-icon, -title, -desc, -open)`                                                                                                                                                                                                                                      |
| Acceso                 | `adm-login`, `adm-login-brand`, `adm-login-monogram`, `adm-login-content`, `adm-login-inner`, `adm-login-title`, `adm-login-card(-title)`, `adm-login-field(-icon, -control, -label, -input)`, `adm-login-error`, `adm-login-submit`                                                                                                      |
| Diálogos               | `adm-swal-container`, `adm-swal-popup`, `adm-swal-description`, `adm-swal-confirm`, `adm-swal-cancel`, `adm-swal-loader` (los asigna `alertas.ts`)                                                                                                                                                                                        |

Tailwind se usa solo para distribución (`grid`, `flex`, `gap-*`, `mb-*`, `sm:`/`lg:`), nunca para colores.

## 7. Patrones

### 7.1 Formularios

Referencia: `pages/admin/Usuarios.vue`, `pages/MiPerfil.vue`.

- **Estructura:** tarjeta `.adm-card` con `.adm-card-title` y la nota «Los campos marcados con * son obligatorios». Los campos se agrupan en `fieldset` con `legend.adm-label`, en una rejilla de 1 columna (2 desde `md`).
- **Campo:** `label.adm-label[for]` + `input.adm-input`. Si es obligatorio, `span.adm-requerido` con `*`, `required` y `aria-required`.
- **Error:** `aria-invalid="true"` y `aria-describedby` en el campo, apuntando a `span.adm-field-error`. El texto del error lo da el servidor, en español.
- **Acciones:** al final, alineadas a la derecha: «Cancelar» (`adm-btn-neutral`) y la acción principal (`adm-btn-primary`, con `aria-busy` mientras guarda).
- **Envío:** con `useForm` y `enviar()`. Un solo envío a la vez: los controles se deshabilitan y aparece `mostrarCarga()`.

### 7.2 Tablas, filtros y paginación

Referencia: `pages/admin/Auditoria.vue`, `pages/admin/Seguimiento.vue`, `pages/admin/Usuarios.vue`.

- **Filtros:** tarjeta «Filtros» (icono `Filter`) con una rejilla responsive. Debajo, a la izquierda un texto de estado en `aria-live` («Hay cambios sin aplicar», «Filtros aplicados»…) y a la derecha «Limpiar filtros» (`adm-btn-neutral`) y «Buscar» (`adm-btn-primary`, con `LoaderCircle` mientras carga).
- **Tabla:**
    - siempre dentro de `.adm-table-scroll`, que desplaza horizontalmente en pantallas estrechas;
    - columnas numéricas con `.is-num` y texto largo con `td.is-wrap`;
    - las filas inactivas llevan `tr.is-inactive`;
    - los encabezados ordenables son botones con `aria-sort` y flechas (`ArrowUp`, `ArrowDown`, `ArrowUpDown`).
- **Paginación:** del servidor, con `nav.adm-table-pagination` («Anterior», «Página X de Y», «Siguiente») y el rango «1–25 de N registros» junto al título.
- **Exportaciones:** tarjeta propia al final de la página. Usan los filtros aplicados.

### 7.3 Asignaciones (selección con cambios pendientes)

Referencia: `pages/admin/Membresias.vue`, `pages/admin/Permisos.vue`.

- **Distribución:** rejilla de dos paneles, `lg:grid-cols-[minmax(0,3fr)_minmax(0,7fr)]`, con la lista seleccionable a la izquierda (`.adm-user-item.is-selected`) y el detalle a la derecha.
- **Opciones:** `label.adm-group-option` con su `input.adm-checkbox`.
- **Cambios pendientes:** `.adm-change-chip` en `role="status"`, calculados con `lib/seleccion.ts`.
- **Guardar:** pide confirmación con el detalle de lo que se agrega y se retira. Un conflicto con otro cambio se muestra con `mostrarAdvertencia`.

### 7.4 Alertas, carga y estados

| Situación                      | Patrón                                                                                                |
| ------------------------------ | ----------------------------------------------------------------------------------------------------- |
| Confirmar una acción           | `confirmarAccion({ titulo, descripcion, confirmar })` (el foco queda en «Cancelar»)                   |
| Operación en curso             | `mostrarCarga()` … `cerrarCarga()`, con los botones deshabilitados y `aria-busy`                      |
| Éxito / error / conflicto      | `mostrarExito`, `mostrarError`, `mostrarAdvertencia` (al cerrar, el foco vuelve al control de origen) |
| Lista vacía                    | `.adm-alert.adm-alert-info` con `role="status"` y un texto que diga qué hacer                         |
| Sin datos por filtros          | El mismo aviso, sugiriendo ampliar o limpiar los filtros                                              |
| Error de validación del filtro | `.adm-alert.adm-alert-danger` con `role="alert"`                                                      |
| Modo solo lectura              | `.adm-alert.adm-alert-warning` «Consulta de producción: solo lectura.»; se ocultan las acciones       |
| Estado de una fila             | `.adm-badge-success` (activo), `-neutral` (inactivo), `-warning` (pendiente), `-danger` (revocado)    |

Los textos son en español y describen la causa y la acción, sin detalles técnicos.

- **Trato:** siempre de usted («Seleccione un grupo», «Podrá consultar…», «Revise los campos…»).
- **Contadores:** con concordancia de número mediante `plural()` de `lib/textos.ts` («1 usuario encontrado», «2 usuarios encontrados»).
- **Errores HTTP:** la página 403 (`resources/views/errors/403.blade.php`) usa la familia `adm-login-*`, con el título «Acceso denegado» y «Volver al inicio».

## 8. Comportamiento responsive

- **Contenido:** `.adm-main` tiene 24 px de relleno (16 px por debajo de 768 px). Las rejillas pasan de 1 columna a 2 o 3 con `sm:`, `md:`, `lg:` y `xl:`.
- **Tablas:** se desplazan dentro de `.adm-table-scroll`, sin desbordar la página.
- **Paneles de selección:** se apilan por debajo de `lg`, y sus listas tienen altura máxima con desplazamiento desde `lg`.
- **Menú lateral:**
    - en escritorio se contrae a iconos con ayuda emergente; en móvil es un panel que se cierra al elegir una opción;
    - el buscador del menú solo aparece expandido.
- **Inicio de sesión:** en móvil, franja de marca arriba (120 px) y formulario debajo; desde 1024 px, dos columnas (58/42) con el panel de marca en diagonal.
- **Selector de modalidad:** por debajo de 480 px, los botones de `.adm-view-switch` ocupan el ancho disponible.

## 9. Cambiar la paleta

1. Editar solo los valores de `resources/css/tema/tokens.css`, conservando todos sus nombres: el contraste de texto sobre fondo debe cumplir WCAG AA (4,5:1). `docs/ejemplos/paleta-ejemplo.css` sirve de plantilla.
2. Ejecutar `npm run build` (o `npm run dev`).
3. Recorrer el inicio de sesión, el Dashboard, una tabla con filtros, un formulario y una confirmación.
4. Ejecutar `php artisan test --filter=PaletaTest`. Comprueba que:
    - la paleta solo contenga colores;
    - tenga los mismos tokens que la de ejemplo;
    - no existan colores fuera de ella.

Para verificar con la paleta de ejemplo: copiar su bloque `:root` sobre el de `tokens.css`, compilar, revisar y restaurar con `git checkout resources/css/tema/tokens.css`. Las medidas no cambian porque `tokens.css` no contiene ninguna.

El nombre y la descripción que muestran el inicio de sesión, el menú y el Dashboard se configuran con `APP_NAME` y `ADM_APP_DESCRIPCION`; no hay logotipos fijos ni favicon de marca. Para usar un favicon propio, colocarlo en `public/` y sustituir el enlace `data:,` de `resources/views/app.blade.php`.

## 10. Accesibilidad

- **Foco visible:** en todos los controles: anillo `--adm-foco` en campos y contorno `--adm-color-acento` en botones, enlaces y tarjetas.
- **Teclado:**
    - todo es operable con el teclado, incluido mostrar u ocultar la contraseña;
    - el primer elemento enfocable es el enlace «Saltar al contenido» (`.adm-saltar`, oculto hasta recibir el foco), que lleva el foco a `main#contenido`;
    - los diálogos devuelven el foco al botón que los abrió; tras un guardado, `mostrarCarga()` recuerda el control de origen y, al cerrar el mensaje, el foco vuelve al primer campo con `aria-invalid` de ese formulario o, si no hay, al control;
    - las confirmaciones enfocan «Cancelar».
- **Atributos ARIA:**
    - `aria-invalid` y `aria-describedby` en campos con error;
    - `aria-busy` durante la carga;
    - `aria-pressed` en los conmutadores, `aria-sort` en las columnas ordenables y `aria-expanded` en el árbol;
    - `aria-live` o `role="status"` en contadores y estados, y `role="alert"` en errores.
- **Iconos:** los decorativos llevan `aria-hidden="true"`. Un botón solo con icono necesita `aria-label` y `title`.
- **Movimiento reducido:** con `prefers-reduced-motion` se anulan las transiciones.
- **Contraste:** los tokens de partida cumplen AA en texto normal; una paleta nueva debe mantenerlo.

## 11. Reglas para pantallas nuevas

1. Declarar la funcionalidad en `CatalogoPermisos` con `ruta` e `icono`; proteger la ruta con su permiso. El menú y el Dashboard la muestran solos.
2. Crear la página en `resources/js/pages/<area>/` con:
    - migas que empiecen en «Inicio»;
    - `.adm-main`;
    - el encabezado de la sección 3.
3. Componerla con los patrones de la sección 7. No crear colores, sombras ni medidas nuevas.
4. Si falta una clase, agregarla a `componentes.css` en su familia, usando solo tokens y con su comportamiento responsive y de movimiento reducido.
5. Para las fechas, usar `lib/fechas.ts` con la zona compartida (`zonaHoraria`).
6. Antes del commit, ejecutar `npm run check`, `npm run types:check`, `npm run test:js`, `npm run build` y `composer test`, que incluye `PaletaTest`.
