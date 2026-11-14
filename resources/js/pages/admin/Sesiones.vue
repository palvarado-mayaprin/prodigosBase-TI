<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Filter, KeyRound, LogOut, RotateCcw, UserX } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import {
    cerrarCarga,
    confirmarAccion,
    mostrarCarga,
    mostrarError,
    mostrarExito,
} from '@/lib/alertas';
import { fechaHora as formatoFecha } from '@/lib/fechas';
import { plural } from '@/lib/textos';
import { dashboard } from '@/routes';
import { sesiones as rutaSesiones } from '@/routes/admin';
import { revocar, revocarUsuario } from '@/routes/admin/sesiones';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            {
                title: 'Administración de Sesiones Activas',
                href: rutaSesiones(),
            },
        ],
    },
});

// Contrato de SesionesController@index. Ni el identificador de sesión ni el
// id del registro llegan al navegador: solo una referencia HMAC.
type Estado = 'activa' | 'vencida' | 'cerrada' | 'revocada';
type Sesion = {
    referencia: string;
    usuarioId: number;
    usuario: string;
    nombre: string;
    estado: Estado;
    abierta: boolean;
    inicio: string;
    ultimaActividad: string;
    fin: string | null;
    revocadaPor: string | null;
    ip: string | null;
    agente: string | null;
    actual: boolean;
};

const props = defineProps<{
    sesiones: Sesion[];
    actorId: number;
    minutosVigencia: number;
    diasHistorial: number;
    soloLectura: boolean;
}>();

const ESTADOS: Record<Estado, { texto: string; insignia: string }> = {
    activa: { texto: 'Activa', insignia: 'adm-badge-success' },
    vencida: {
        texto: 'Vencida por inactividad',
        insignia: 'adm-badge-warning',
    },
    cerrada: { texto: 'Cerrada', insignia: 'adm-badge-neutral' },
    revocada: { texto: 'Revocada', insignia: 'adm-badge-danger' },
};

const corta = (sesion: Sesion) => sesion.referencia.slice(0, 8);

// Fechas UTC en la zona horaria configurada (prop compartida).
const page = usePage();
const zona = computed(() => page.props.zonaHoraria);
const fechaHora = (iso: string | null) => formatoFecha(iso, zona.value);

// ---------- Filtros (locales) ----------
const vacios = () => ({ usuario: '', estado: 'abiertas', buscar: '' });
const filtros = reactive(vacios());

const usuarios = computed(() =>
    [...new Set(props.sesiones.map((sesion) => sesion.usuario))].sort(),
);

const filtradas = computed(() => {
    const texto = filtros.buscar.trim().toLowerCase();
    return props.sesiones.filter(
        (sesion) =>
            (!filtros.usuario || sesion.usuario === filtros.usuario) &&
            (filtros.estado === 'todas' ||
                (filtros.estado === 'abiertas'
                    ? sesion.abierta
                    : sesion.estado === filtros.estado)) &&
            `${sesion.usuario} ${sesion.nombre} ${sesion.ip ?? ''}`
                .toLowerCase()
                .includes(texto),
    );
});

const hayFiltros = computed(
    () => JSON.stringify(filtros) !== JSON.stringify(vacios()),
);

function limpiar() {
    Object.assign(filtros, vacios());
}

// ---------- Revocación ----------
const procesando = ref(false);
type Mensaje = { titulo: string; descripcion: string };

async function enviar(url: string, exito: Mensaje) {
    procesando.value = true;
    let resultado: { exito: boolean; mensaje: Mensaje } | null = null;
    await mostrarCarga('Revocando...');
    router.put(
        url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                resultado = { exito: true, mensaje: exito };
            },
            onError: (errores: Record<string, string>) => {
                resultado = {
                    exito: false,
                    mensaje: {
                        titulo: 'No se pudo revocar',
                        descripcion:
                            errores.sesion ?? 'Intente de nuevo más tarde.',
                    },
                };
            },
            onHttpException: (respuesta) => {
                resultado = {
                    exito: false,
                    mensaje: {
                        titulo: 'No se pudo revocar',
                        descripcion:
                            respuesta.status === 404
                                ? 'La sesión ya no está abierta. Se actualizó el listado.'
                                : respuesta.status === 403
                                  ? 'No tiene autorización o el entorno es de solo lectura.'
                                  : 'Ocurrió un error inesperado. Intente de nuevo más tarde.',
                    },
                };
                if (respuesta.status === 404)
                    router.reload({ only: ['sesiones'] });
                return false;
            },
            onFinish: () => {
                void cerrarCarga().then(() => {
                    procesando.value = false;
                    const final = resultado as {
                        exito: boolean;
                        mensaje: Mensaje;
                    } | null;
                    if (!final) return;
                    void (final.exito
                        ? mostrarExito(final.mensaje)
                        : mostrarError(final.mensaje));
                });
            },
        },
    );
}

async function revocarSesion(sesion: Sesion) {
    if (procesando.value || sesion.actual) return;
    const confirmado = await confirmarAccion({
        titulo: 'Revocar sesión',
        descripcion: `¿Revocar la sesión ${corta(sesion)} de ${sesion.usuario} (última actividad: ${fechaHora(sesion.ultimaActividad)})? Deberá volver a iniciar sesión en ese equipo.`,
        confirmar: 'Revocar sesión',
    });
    if (!confirmado || procesando.value) return;
    void enviar(revocar(sesion.referencia).url, {
        titulo: 'Sesión revocada',
        descripcion: `${sesion.usuario} deberá iniciar sesión de nuevo en ese equipo.`,
    });
}

async function revocarTodas(sesion: Sesion) {
    if (procesando.value) return;
    const propia = sesion.usuarioId === props.actorId;
    const confirmado = await confirmarAccion({
        titulo: 'Revocar todas las sesiones',
        descripcion: propia
            ? '¿Revocar todas sus otras sesiones abiertas? Esta sesión se conserva.'
            : `¿Revocar todas las sesiones abiertas de ${sesion.usuario}? Deberá volver a iniciar sesión en todos sus equipos.`,
        confirmar: 'Revocar todas',
    });
    if (!confirmado || procesando.value) return;
    void enviar(revocarUsuario(sesion.usuarioId).url, {
        titulo: 'Sesiones revocadas',
        descripcion: propia
            ? 'Sus otras sesiones se revocaron. Esta sesión sigue abierta.'
            : `Las sesiones abiertas de ${sesion.usuario} se revocaron.`,
    });
}
</script>

<template>
    <Head title="Administración de Sesiones Activas" />

    <div class="adm-main min-h-full">
        <div class="w-full min-w-0">
            <div class="mb-6">
                <h1 class="adm-page-title">
                    ADMINISTRACIÓN DE SESIONES ACTIVAS
                </h1>
                <p class="adm-page-subtitle">
                    Sesiones autenticadas y revocación remota
                </p>
                <p class="adm-page-note mt-3 text-sm">
                    Al revocar una sesión, el usuario deberá volver a iniciar
                    sesión en ese equipo en su siguiente acción. Su propia
                    sesión no se revoca desde aquí: use «Cerrar sesión». Una
                    sesión vence tras {{ minutosVigencia }} minutos sin
                    actividad. Se muestran las sesiones abiertas y las de los
                    últimos {{ diasHistorial }} días, en la zona horaria
                    {{ zona }}.
                </p>
            </div>

            <div
                v-if="soloLectura"
                class="adm-alert adm-alert-warning mb-6 font-semibold"
                role="status"
            >
                Consulta de producción: solo lectura. Las sesiones iniciadas en
                este modo no se registran.
            </div>

            <section class="adm-card mb-6" aria-labelledby="titulo-filtros">
                <h2
                    id="titulo-filtros"
                    class="adm-card-title mb-4 flex items-center gap-2"
                >
                    <Filter class="size-4" aria-hidden="true" />
                    Filtros
                </h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="min-w-0">
                        <label for="filtro-usuario" class="adm-label"
                            >Usuario</label
                        >
                        <select
                            id="filtro-usuario"
                            v-model="filtros.usuario"
                            class="adm-input"
                        >
                            <option value="">Todos</option>
                            <option
                                v-for="usuario in usuarios"
                                :key="usuario"
                                :value="usuario"
                            >
                                {{ usuario }}
                            </option>
                        </select>
                    </div>
                    <div class="min-w-0">
                        <label for="filtro-estado" class="adm-label"
                            >Estado</label
                        >
                        <select
                            id="filtro-estado"
                            v-model="filtros.estado"
                            class="adm-input"
                        >
                            <option value="abiertas">Abiertas</option>
                            <option value="todas">Todas</option>
                            <option
                                v-for="(estado, clave) in ESTADOS"
                                :key="clave"
                                :value="clave"
                            >
                                {{ estado.texto }}
                            </option>
                        </select>
                    </div>
                    <div class="min-w-0">
                        <label for="filtro-buscar" class="adm-label"
                            >Buscar</label
                        >
                        <input
                            id="filtro-buscar"
                            v-model="filtros.buscar"
                            type="search"
                            class="adm-input"
                            maxlength="100"
                            autocomplete="off"
                            placeholder="Usuario, nombre o IP"
                        />
                    </div>
                </div>
                <div
                    class="mt-4 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <p class="adm-muted text-xs" aria-live="polite">
                        {{
                            hayFiltros
                                ? 'Filtros aplicados.'
                                : 'Se muestran las sesiones abiertas.'
                        }}
                    </p>
                    <button
                        type="button"
                        class="adm-btn adm-btn-neutral"
                        :disabled="!hayFiltros"
                        @click="limpiar"
                    >
                        <RotateCcw class="size-4" aria-hidden="true" />
                        Limpiar filtros
                    </button>
                </div>
            </section>

            <section class="adm-card" aria-labelledby="titulo-sesiones">
                <div
                    class="mb-4 flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h2
                        id="titulo-sesiones"
                        class="adm-card-title flex items-center gap-2"
                    >
                        <KeyRound class="size-4" aria-hidden="true" />
                        Sesiones
                    </h2>
                    <p class="adm-muted text-xs" aria-live="polite">
                        {{ filtradas.length }} de {{ sesiones.length }}
                        {{ plural(sesiones.length, 'sesión', 'sesiones') }}
                    </p>
                </div>

                <div
                    v-if="filtradas.length === 0"
                    class="adm-alert adm-alert-info"
                    role="status"
                >
                    {{
                        hayFiltros
                            ? 'No hay sesiones para los filtros aplicados. Limpie los filtros para ver las abiertas.'
                            : 'No hay sesiones abiertas.'
                    }}
                </div>

                <div v-else class="adm-table-scroll">
                    <table class="adm-table">
                        <thead>
                            <tr>
                                <th scope="col">Usuario</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Última actividad</th>
                                <th scope="col">Inicio</th>
                                <th scope="col">Dirección IP</th>
                                <th scope="col">Equipo</th>
                                <th scope="col">Referencia</th>
                                <th scope="col">
                                    <span class="sr-only">Acciones</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="sesion in filtradas"
                                :key="sesion.referencia"
                                :class="{ 'is-inactive': !sesion.abierta }"
                            >
                                <td>
                                    <span
                                        class="block max-w-[14rem] [overflow-wrap:anywhere] whitespace-normal"
                                    >
                                        <span class="font-medium">{{
                                            sesion.usuario
                                        }}</span>
                                        <span
                                            v-if="sesion.nombre"
                                            class="adm-muted block text-xs"
                                            >{{ sesion.nombre }}</span
                                        >
                                    </span>
                                </td>
                                <td>
                                    <span
                                        :class="[
                                            'adm-badge',
                                            ESTADOS[sesion.estado].insignia,
                                        ]"
                                        >{{
                                            ESTADOS[sesion.estado].texto
                                        }}</span
                                    >
                                    <span
                                        v-if="sesion.fin"
                                        class="adm-muted block text-xs"
                                        >{{ fechaHora(sesion.fin)
                                        }}{{
                                            sesion.revocadaPor
                                                ? ` · por ${sesion.revocadaPor}`
                                                : ''
                                        }}</span
                                    >
                                </td>
                                <td>{{ fechaHora(sesion.ultimaActividad) }}</td>
                                <td>{{ fechaHora(sesion.inicio) }}</td>
                                <td :class="{ 'adm-muted': !sesion.ip }">
                                    {{ sesion.ip ?? 'No disponible' }}
                                </td>
                                <td>
                                    <span
                                        class="adm-muted block max-w-[14rem] truncate text-xs"
                                        :title="sesion.agente ?? undefined"
                                        >{{ sesion.agente ?? '—' }}</span
                                    >
                                </td>
                                <td>
                                    <code class="text-xs">{{
                                        corta(sesion)
                                    }}</code>
                                    <span
                                        v-if="sesion.actual"
                                        class="adm-badge ml-2"
                                        >Esta sesión</span
                                    >
                                </td>
                                <td>
                                    <div
                                        v-if="!soloLectura && sesion.abierta"
                                        class="adm-table-actions"
                                    >
                                        <button
                                            v-if="!sesion.actual"
                                            type="button"
                                            class="adm-btn adm-btn-danger whitespace-nowrap"
                                            :disabled="procesando"
                                            :aria-label="`Revocar la sesión ${corta(sesion)} de ${sesion.usuario}`"
                                            @click="revocarSesion(sesion)"
                                        >
                                            <LogOut
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            Revocar
                                        </button>
                                        <button
                                            type="button"
                                            class="adm-btn adm-btn-secondary whitespace-nowrap"
                                            :disabled="procesando"
                                            :aria-label="`Revocar todas las sesiones de ${sesion.usuario}`"
                                            @click="revocarTodas(sesion)"
                                        >
                                            <UserX
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            Revocar todas
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</template>
