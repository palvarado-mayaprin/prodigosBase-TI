# Convenciones

Las reglas obligatorias están en [`AGENTS.md`](../../AGENTS.md) y [`CLAUDE.md`](../../CLAUDE.md); aquí se resume cómo aplicarlas.

## Principios

- **KISS y cambios pequeños:** la solución más simple que cumpla la tarea, sin refactorizaciones generales ni abstracciones para usos futuros.
- **Reutilizar lo existente:** servicios, middleware, clases `adm-*`, `lib/alertas.ts` y patrones de páginas parecidas antes de crear nada nuevo.
- **Evidencia, no suposiciones:** comprobar en el código que una clase, ruta o comando existe antes de usarlo o afirmarlo.
- **Sin dependencias nuevas** sin aprobación.
- Una excepción a una regla es una decisión arquitectónica y requiere aprobación previa.

## Nombres

| Elemento                | Convención y ejemplo real                                                                  |
| ----------------------- | ------------------------------------------------------------------------------------------ |
| Idioma                  | Español en clases, métodos, variables, tablas, textos, pruebas y documentación             |
| Servicios               | Verbo o sustantivo de la tarea: `AdministrarGrupos`, `CambiarContrasena`, `SeguimientoUso` |
| Controladores           | `<Modulo>Controller` con métodos `index`, `crear`, `actualizar`, `estado`, `guardar`       |
| Tablas                  | Prefijo por área en minúsculas y `snake_case` (`adm_usuarios`); fechas con sufijo `_utc`   |
| Código de funcionalidad | `area.modulo.funcionalidad` en camelCase (`administracion.accesos.usuarios`)               |
| Acciones                | `VER`, `CREAR`, `EDITAR`, `ELIMINAR`; permiso completo `codigo:ACCION`                     |
| Rutas                   | Nombre con puntos (`admin.grupos.actualizar`); parámetros con `whereNumber` o patrón       |
| Props de Inertia        | camelCase (`esAdministrador`, `soloLectura`)                                               |
| Páginas Vue             | `resources/js/pages/<area>/<Pantalla>.vue` en PascalCase                                   |
| Pruebas PHP             | `tests/Feature/<Area>/<Tema>Test.php`, métodos `test_descripcion_en_espanol`               |

## Estilo de código

- PHP: Pint (`composer lint`) y Larastan (`composer types:check`); comentarios breves en español que explican el porqué.
- TypeScript y Vue: oxfmt y oxlint mediante `npm run check` (`npm run check:fix` corrige el formato); `npm run types:check` con `vue-tsc`.
- Markdown: también lo formatea `npm run check`.

## Lenguaje visual

Fuente: [`GUIA_DISENO_UI.md`](../GUIA_DISENO_UI.md), en especial las secciones 3 (estructura de las páginas), 7 (patrones) y 11 (reglas para pantallas nuevas).

- Colores solo como tokens `--adm-*` en `resources/css/tema/tokens.css`. Sin colores literales, sin clases de color de Tailwind y sin modo oscuro; `PaletaTest` lo comprueba.
- Usar las clases `adm-*`, los layouts y `lib/alertas.ts`. No rediseñar estructura, medidas, tipografía ni jerarquías; una clase que falte se añade a `componentes.css` usando tokens.
- Textos en español con trato de usted; contadores con `plural()` de `lib/textos.ts`; páginas de error sobre `resources/views/errors/plantilla.blade.php`.
- Accesibilidad: foco visible, teclado, atributos `aria-*`, estados de carga, error y vacío, y comportamiento responsive.

## Control de versiones

- Antes de cada commit: `composer test`, `npm run check`, `npm run types:check`, `npm run test:js` y `npm run build`.
- Mensajes en español: `tipo(alcance): descripción` (por ejemplo, `fix(seguridad): …`, `docs(etapa-2): …`).
- Añadir solo rutas explícitas (`git add <archivo>`) y revisar el diff en busca de datos sensibles.
- Commits, push y etiquetas solo con autorización expresa. Una sola sesión de asistente trabaja en la carpeta a la vez.
- Todo cambio significativo se registra en [`HISTORIAL_CAMBIOS.md`](../HISTORIAL_CAMBIOS.md) y en el manual correspondiente, en el mismo trabajo.
