import { router } from '@inertiajs/vue3';
import { actividad, visita as rutaVisita } from '@/routes/seguimiento';

// Registro de uso silencioso: tiempo activo por pantalla visitada.
// - Cuenta solo con la pestaña visible y hasta 5 minutos después de la
//   última interacción (clic, teclado, desplazamiento o navegación).
// - Con varias pestañas cuenta solo la de la interacción más reciente
//   (compartida por localStorage): ningún segundo se cuenta dos veces.
// - Envía los segundos acumulados cada minuto, al navegar y al ocultar la
//   pestaña. No registra teclas ni datos introducidos: solo la ruta.
// - El servidor decide qué se registra (funcionalidades del catálogo que la
//   cuenta puede ver) y limita los segundos al tiempo real de la sesión.
//   Con la escritura bloqueada responde con error y no se registra nada.

const INACTIVIDAD_MS = 5 * 60 * 1000;
const ENVIO_MS = 60 * 1000;
const CLAVE = 'adm-seguimiento-interaccion';
const pestana = Math.random().toString(36).slice(2);

type Visita = { id: number | null; ruta: string; ms: number };
let visita: Visita | null = null;
let ultimaInteraccion = 0;
let ultimoTic = Date.now();

function marcarInteraccion() {
    const ahora = Date.now();
    if (ahora - ultimaInteraccion < 1000) return;
    ultimaInteraccion = ahora;
    try {
        localStorage.setItem(CLAVE, JSON.stringify({ p: pestana, t: ahora }));
    } catch {
        // Sin almacenamiento: cada pestaña decide con su propia interacción.
    }
}

// Pestaña que puede contar: la de la interacción más reciente, aún vigente.
function puedeContar(ahora: number) {
    let duena = { p: pestana, t: ultimaInteraccion };
    try {
        duena = JSON.parse(localStorage.getItem(CLAVE) ?? '') ?? duena;
    } catch {
        // Se usa la interacción local.
    }
    return (
        document.visibilityState === 'visible' &&
        duena.p === pestana &&
        ahora - duena.t < INACTIVIDAD_MS
    );
}

function enviar(url: string, datos: object) {
    const csrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);
    return fetch(url, {
        method: 'POST',
        keepalive: true,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf) } : {}),
        },
        body: JSON.stringify(datos),
    });
}

// Envía los segundos completos pendientes. Ante un error se descartan:
// perder segundos es preferible a contarlos dos veces.
function vaciar() {
    if (!visita?.id) return;
    const segundos = Math.floor(visita.ms / 1000);
    if (segundos < 1) return;
    visita.ms -= segundos * 1000;
    enviar(actividad().url, { visita: visita.id, segundos }).catch(
        () => undefined,
    );
}

function iniciarVisita(ruta: string) {
    const nueva: Visita = { id: null, ruta, ms: 0 };
    visita = nueva;
    enviar(rutaVisita().url, { ruta })
        .then(async (respuesta) => {
            // 204 o error: esta pantalla no se registra.
            nueva.id =
                respuesta.status === 200
                    ? ((await respuesta.json()) as { visita: number }).visita
                    : 0;
            if (nueva === visita) vaciar();
        })
        .catch(() => (nueva.id = 0));
}

export function initializeSeguimientoUso(): void {
    router.on('navigate', (evento) => {
        const pagina = evento.detail.page;
        const ruta = new URL(pagina.url, location.origin).pathname;
        marcarInteraccion();
        if (!pagina.props.auth?.user) {
            visita = null;
            return;
        }
        // Recargas parciales o redirecciones a la misma ruta: misma visita.
        if (visita?.ruta === ruta) return;
        vaciar();
        iniciarVisita(ruta);
    });
    // Antes de cada navegación (incluido el cierre de sesión) se envía lo pendiente.
    router.on('start', () => vaciar());

    for (const evento of ['pointerdown', 'keydown', 'wheel', 'touchstart']) {
        window.addEventListener(evento, marcarInteraccion, { passive: true });
    }
    window.addEventListener('scroll', marcarInteraccion, {
        passive: true,
        capture: true,
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') marcarInteraccion();
        else vaciar();
    });
    window.addEventListener('pagehide', vaciar);

    window.setInterval(() => {
        const ahora = Date.now();
        // Máximo 2 s por tic: una pausa del equipo no se cuenta como actividad.
        const transcurrido = Math.min(ahora - ultimoTic, 2000);
        ultimoTic = ahora;
        if (visita && puedeContar(ahora)) visita.ms += transcurrido;
    }, 1000);
    window.setInterval(vaciar, ENVIO_MS);
}
