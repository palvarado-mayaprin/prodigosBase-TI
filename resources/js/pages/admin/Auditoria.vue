<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Download,
    Eye,
    Filter,
    History,
    LoaderCircle,
    RotateCcw,
    Search,
    ShieldCheck,
    X,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { fechaHora } from '@/lib/fechas';
import { plural } from '@/lib/textos';
import { dashboard } from '@/routes';
import { auditoria } from '@/routes/admin';
import { exportar } from '@/routes/admin/auditoria';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Auditoría de Modificaciones', href: auditoria() },
        ],
    },
});

// Contrato de AuditoriaController@index y ConsultaAuditoria::fila(). Los
// valores sensibles ya llegan ocultos («********») desde el servidor.
type Filtros = {
    desde: string | null;
    hasta: string | null;
    usuario: string | null;
    entidad: string | null;
    accion: string | null;
    texto: string | null;
    orden: string | null;
    dir: string | null;
};
type ClaveFiltro =
    | 'desde'
    | 'hasta'
    | 'usuario'
    | 'entidad'
    | 'accion'
    | 'texto';
type Campo = {
    campo: string;
    anterior: string | null;
    nuevo: string | null;
    cambio: boolean;
};
type Registro = {
    id: number;
    fecha: string | null;
    usuario: string;
    ip: string | null;
    entidad: string;
    registro: string | null;
    accion: string;
    accionTexto: string;
    campos: Campo[];
    resumen: string;
};

const props = defineProps<{
    filtros: Filtros;
    registros: {
        data: Registro[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    usuarios: string[];
    entidades: string[];
    acciones: Record<string, string>;
}>();

const page = usePage();
const zona = computed(() => page.props.zonaHoraria);
const errores = computed(
    () => Object.values(page.props.errors ?? {}) as string[],
);

const numero = new Intl.NumberFormat('es');
const n = (valor: number) => numero.format(valor);
const fecha = (valor: string | null) => fechaHora(valor, zona.value);

const insignias: Record<string, string> = {
    CREAR: 'adm-badge-success',
    HABILITAR: 'adm-badge-success',
    ASIGNAR: 'adm-badge-success',
    EDITAR: '',
    DESHABILITAR: 'adm-badge-warning',
    RETIRAR: 'adm-badge-warning',
    REVOCAR: 'adm-badge-danger',
};
const insignia = (accion: string) => insignias[accion] ?? 'adm-badge-neutral';

// ---------- Filtros y orden ----------
const claves: ClaveFiltro[] = [
    'desde',
    'hasta',
    'usuario',
    'entidad',
    'accion',
    'texto',
];
const vacios = (): Record<ClaveFiltro, string> => ({
    desde: '',
    hasta: '',
    usuario: '',
    entidad: '',
    accion: '',
    texto: '',
});
const form = reactive(vacios());

// El formulario refleja los filtros aplicados que devolvió el servidor.
watch(
    () => props.filtros,
    (filtros) => {
        for (const clave of claves) form[clave] = filtros[clave] ?? '';
    },
    { immediate: true },
);

type Orden = { orden: string; dir: string };
const ordenActual = computed<Orden>(() => ({
    orden: props.filtros.orden ?? 'fecha',
    dir: props.filtros.dir ?? 'desc',
}));

function consulta(
    valores: Record<ClaveFiltro, string | null>,
    pagina = 1,
    orden: Orden = ordenActual.value,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    for (const clave of claves) {
        const valor = valores[clave]?.trim();
        if (valor) query[clave] = valor;
    }
    if (orden.orden !== 'fecha' || orden.dir !== 'desc') {
        query.orden = orden.orden;
        query.dir = orden.dir;
    }
    if (pagina > 1) query.page = pagina;
    return query;
}

const aplicados = computed(() => consulta(props.filtros));
const hayFiltros = computed(() =>
    claves.some((clave) => (props.filtros[clave] ?? '') !== ''),
);
const sinAplicar = computed(
    () =>
        JSON.stringify(consulta(form)) !==
        JSON.stringify(consulta(props.filtros)),
);
const errorFechas = computed(() =>
    form.desde && form.hasta && form.hasta < form.desde
        ? 'La fecha final no puede ser anterior a la inicial.'
        : '',
);

// Una sola consulta a la vez; solo se consulta con «Buscar», no al escribir.
const cargando = ref(false);

function cargar(query: Record<string, string | number>) {
    if (cargando.value) return;
    cargando.value = true;
    router.get(auditoria().url, query, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            cargando.value = false;
        },
    });
}

function buscar() {
    if (errorFechas.value) return;
    cargar(consulta(form));
}

function limpiar() {
    Object.assign(form, vacios());
    cargar({});
}

function irAPagina(pagina: number) {
    cargar(consulta(props.filtros, pagina));
}

// Primer clic en una columna: descendente; los siguientes alternan.
const columnas = [
    { clave: 'fecha', titulo: 'Fecha y hora' },
    { clave: 'usuario', titulo: 'Usuario' },
    { clave: 'accion', titulo: 'Acción' },
    { clave: 'entidad', titulo: 'Entidad' },
];

function ordenarPor(clave: string) {
    const actual = ordenActual.value;
    cargar(
        consulta(props.filtros, 1, {
            orden: clave,
            dir:
                actual.orden === clave && actual.dir === 'desc'
                    ? 'asc'
                    : 'desc',
        }),
    );
}

function ariaSort(clave: string) {
    if (ordenActual.value.orden !== clave) return 'none';
    return ordenActual.value.dir === 'asc' ? 'ascending' : 'descending';
}

// Las descargas usan los filtros aplicados, no los cambios sin buscar.
const urlExportar = (formato: 'csv' | 'xlsx') =>
    exportar({ formato }, { query: aplicados.value }).url;

// ---------- Detalle ----------
const seleccionado = ref<Registro | null>(null);
let origenDetalle: HTMLElement | null = null;
const datosDe = (registro: Registro) =>
    registro.campos.filter((c) => !c.cambio);
const cambiosDe = (registro: Registro) =>
    registro.campos.filter((c) => c.cambio);

function verDetalle(registro: Registro, evento: MouseEvent) {
    origenDetalle = evento.currentTarget as HTMLElement;
    seleccionado.value = registro;
}

function alCambiarDetalle(abierto: boolean) {
    if (!abierto) seleccionado.value = null;
}

// Devuelve el foco al botón «Ver detalle» que abrió el diálogo.
function alCerrarDetalle(evento: Event) {
    evento.preventDefault();
    origenDetalle?.focus();
}
</script>

<template>
    <Head title="Auditoría de Modificaciones" />

    <div class="adm-main min-h-full">
        <div class="w-full min-w-0">
            <div class="mb-6">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="adm-page-title">AUDITORÍA DE MODIFICACIONES</h1>
                    <span class="adm-badge adm-badge-neutral">
                        <ShieldCheck class="mr-1 size-3.5" aria-hidden="true" />
                        Módulo administrativo
                    </span>
                </div>
                <p class="adm-page-subtitle">
                    Cambios administrativos registrados
                </p>
                <p class="adm-page-note mt-3 text-sm">
                    Solo consulta. Fechas y horas en la zona horaria {{ zona }}.
                    Los valores sensibles, como contraseñas y hashes, se
                    ocultan.
                </p>
            </div>

            <!-- Filtros -->
            <section class="adm-card mb-6" aria-labelledby="titulo-filtros">
                <h2
                    id="titulo-filtros"
                    class="adm-card-title mb-4 flex items-center gap-2"
                >
                    <Filter class="size-4" aria-hidden="true" />
                    Filtros
                </h2>
                <form novalidate @submit.prevent="buscar">
                    <div
                        class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6"
                    >
                        <div class="min-w-0">
                            <label for="filtro-desde" class="adm-label"
                                >Fecha inicial</label
                            >
                            <input
                                id="filtro-desde"
                                v-model="form.desde"
                                type="date"
                                name="desde"
                                class="adm-input"
                                :max="form.hasta || undefined"
                                :disabled="cargando"
                            />
                        </div>
                        <div class="min-w-0">
                            <label for="filtro-hasta" class="adm-label"
                                >Fecha final</label
                            >
                            <input
                                id="filtro-hasta"
                                v-model="form.hasta"
                                type="date"
                                name="hasta"
                                class="adm-input"
                                :min="form.desde || undefined"
                                :disabled="cargando"
                                :aria-invalid="errorFechas ? 'true' : undefined"
                                :aria-describedby="
                                    errorFechas
                                        ? 'filtro-hasta-error'
                                        : undefined
                                "
                            />
                            <span
                                v-if="errorFechas"
                                id="filtro-hasta-error"
                                class="adm-field-error mt-1"
                                >{{ errorFechas }}</span
                            >
                        </div>
                        <div class="min-w-0">
                            <label for="filtro-usuario" class="adm-label"
                                >Usuario responsable</label
                            >
                            <select
                                id="filtro-usuario"
                                v-model="form.usuario"
                                name="usuario"
                                class="adm-input"
                                :disabled="cargando"
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
                            <label for="filtro-accion" class="adm-label"
                                >Acción</label
                            >
                            <select
                                id="filtro-accion"
                                v-model="form.accion"
                                name="accion"
                                class="adm-input"
                                :disabled="cargando"
                            >
                                <option value="">Todas</option>
                                <option
                                    v-for="(texto, codigo) in acciones"
                                    :key="codigo"
                                    :value="codigo"
                                >
                                    {{ texto }}
                                </option>
                            </select>
                        </div>
                        <div class="min-w-0">
                            <label for="filtro-entidad" class="adm-label"
                                >Entidad</label
                            >
                            <select
                                id="filtro-entidad"
                                v-model="form.entidad"
                                name="entidad"
                                class="adm-input"
                                :disabled="cargando"
                            >
                                <option value="">Todas</option>
                                <option
                                    v-for="entidad in entidades"
                                    :key="entidad"
                                    :value="entidad"
                                >
                                    {{ entidad }}
                                </option>
                            </select>
                        </div>
                        <div class="min-w-0">
                            <label for="filtro-texto" class="adm-label"
                                >Buscar</label
                            >
                            <input
                                id="filtro-texto"
                                v-model="form.texto"
                                type="search"
                                name="texto"
                                class="adm-input"
                                maxlength="100"
                                autocomplete="off"
                                placeholder="Registro, usuario o cambio"
                                :disabled="cargando"
                            />
                        </div>
                    </div>

                    <div
                        class="mt-4 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <p class="adm-muted text-xs" aria-live="polite">
                            <template v-if="cargando">
                                Actualizando resultados…
                            </template>
                            <template v-else-if="sinAplicar">
                                Hay cambios sin aplicar: los resultados y las
                                descargas usan los filtros aplicados.
                            </template>
                            <template v-else-if="hayFiltros">
                                Filtros aplicados.
                            </template>
                            <template v-else>
                                Sin filtros: se muestra todo el historial.
                            </template>
                        </p>
                        <div class="flex flex-wrap justify-end gap-2">
                            <button
                                type="button"
                                class="adm-btn adm-btn-neutral"
                                :disabled="cargando"
                                @click="limpiar"
                            >
                                <RotateCcw class="size-4" aria-hidden="true" />
                                Limpiar filtros
                            </button>
                            <button
                                type="submit"
                                class="adm-btn adm-btn-primary"
                                :disabled="cargando || !!errorFechas"
                                :aria-busy="cargando ? 'true' : undefined"
                            >
                                <LoaderCircle
                                    v-if="cargando"
                                    class="size-4 motion-safe:animate-spin"
                                    aria-hidden="true"
                                />
                                <Search
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ cargando ? 'Buscando…' : 'Buscar' }}
                            </button>
                        </div>
                    </div>
                </form>
            </section>

            <div
                v-if="errores.length"
                class="adm-alert adm-alert-danger mb-6"
                role="alert"
            >
                <p v-for="error in errores" :key="error">{{ error }}</p>
            </div>

            <!-- Historial -->
            <section
                class="adm-card mb-6"
                aria-labelledby="titulo-historial"
                :aria-busy="cargando ? 'true' : undefined"
                :class="[
                    'transition-opacity',
                    cargando ? 'pointer-events-none opacity-60' : undefined,
                ]"
            >
                <div
                    class="mb-4 flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h2
                        id="titulo-historial"
                        class="adm-card-title flex items-center gap-2"
                    >
                        <History class="size-4" aria-hidden="true" />
                        Historial de modificaciones
                    </h2>
                    <p v-if="registros.total > 0" class="adm-muted text-xs">
                        {{ n(registros.from ?? 0) }}–{{
                            n(registros.to ?? 0)
                        }}
                        de {{ n(registros.total) }}
                        {{ plural(registros.total, 'registro', 'registros') }}
                    </p>
                </div>

                <div
                    v-if="registros.total === 0"
                    class="adm-alert adm-alert-info"
                    role="status"
                >
                    {{
                        hayFiltros
                            ? 'No hay registros para los filtros aplicados. Amplíe el rango de fechas o limpie los filtros.'
                            : 'Todavía no hay modificaciones registradas.'
                    }}
                </div>

                <template v-else>
                    <div class="adm-table-scroll">
                        <table class="adm-table">
                            <thead>
                                <tr>
                                    <th
                                        v-for="columna in columnas"
                                        :key="columna.clave"
                                        scope="col"
                                        :aria-sort="ariaSort(columna.clave)"
                                    >
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 font-semibold hover:underline focus-visible:underline"
                                            :title="`Ordenar por ${columna.titulo.toLowerCase()}`"
                                            :disabled="cargando"
                                            @click="ordenarPor(columna.clave)"
                                        >
                                            {{ columna.titulo }}
                                            <ArrowDown
                                                v-if="
                                                    ordenActual.orden ===
                                                        columna.clave &&
                                                    ordenActual.dir === 'desc'
                                                "
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            <ArrowUp
                                                v-else-if="
                                                    ordenActual.orden ===
                                                    columna.clave
                                                "
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            <ArrowUpDown
                                                v-else
                                                class="size-3.5 opacity-40"
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </th>
                                    <th scope="col">Registro afectado</th>
                                    <th scope="col">Resumen de cambios</th>
                                    <th scope="col">
                                        <span class="sr-only">Acciones</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="registro in registros.data"
                                    :key="registro.id"
                                >
                                    <td>
                                        <span
                                            class="block max-w-[6.5rem] whitespace-normal"
                                            >{{ fecha(registro.fecha) }}</span
                                        >
                                    </td>
                                    <td class="font-medium">
                                        {{ registro.usuario }}
                                    </td>
                                    <td>
                                        <span
                                            class="adm-badge"
                                            :class="insignia(registro.accion)"
                                            >{{ registro.accionTexto }}</span
                                        >
                                    </td>
                                    <td>{{ registro.entidad }}</td>
                                    <td>
                                        <span
                                            class="block max-w-[12rem] [overflow-wrap:anywhere] whitespace-normal"
                                            >{{
                                                registro.registro || '—'
                                            }}</span
                                        >
                                    </td>
                                    <td
                                        :class="{
                                            'adm-muted':
                                                registro.campos.length === 0,
                                        }"
                                    >
                                        <span
                                            class="block max-w-[14rem] [overflow-wrap:anywhere] whitespace-normal"
                                            >{{ registro.resumen }}</span
                                        >
                                    </td>
                                    <td>
                                        <div class="adm-table-actions">
                                            <button
                                                type="button"
                                                class="adm-btn adm-btn-secondary whitespace-nowrap"
                                                :aria-label="`Ver detalle del registro de auditoría ${registro.id}`"
                                                @click="
                                                    verDetalle(registro, $event)
                                                "
                                            >
                                                <Eye
                                                    class="size-3.5"
                                                    aria-hidden="true"
                                                />
                                                Ver detalle
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <nav
                        v-if="registros.last_page > 1"
                        class="adm-table-pagination"
                        aria-label="Páginas del historial"
                    >
                        <button
                            type="button"
                            :disabled="registros.current_page <= 1 || cargando"
                            @click="irAPagina(registros.current_page - 1)"
                        >
                            Anterior
                        </button>
                        <span
                            >Página {{ n(registros.current_page) }} de
                            {{ n(registros.last_page) }}</span
                        >
                        <button
                            type="button"
                            :disabled="
                                registros.current_page >= registros.last_page ||
                                cargando
                            "
                            @click="irAPagina(registros.current_page + 1)"
                        >
                            Siguiente
                        </button>
                    </nav>
                </template>
            </section>

            <!-- Exportaciones -->
            <section class="adm-card" aria-labelledby="titulo-exportar">
                <h2
                    id="titulo-exportar"
                    class="adm-card-title mb-1 flex items-center gap-2"
                >
                    <Download class="size-4" aria-hidden="true" />
                    Exportar historial
                </h2>
                <p class="adm-muted mb-4 text-xs">
                    Incluye todos los registros de los filtros aplicados, no
                    solo la página visible, con los cambios y los valores
                    sensibles ocultos. Fechas en la zona horaria {{ zona }}.
                </p>
                <div class="flex flex-wrap gap-2">
                    <a
                        class="adm-btn adm-btn-secondary"
                        :href="urlExportar('csv')"
                        download
                        >Exportar CSV</a
                    >
                    <a
                        class="adm-btn adm-btn-secondary"
                        :href="urlExportar('xlsx')"
                        download
                        >Exportar Excel</a
                    >
                </div>
            </section>
        </div>
    </div>

    <!-- Detalle: solo datos ya interpretados y saneados por el servidor. -->
    <Dialog :open="seleccionado !== null" @update:open="alCambiarDetalle">
        <DialogContent
            v-if="seleccionado"
            :show-close-button="false"
            class="max-h-[90vh] grid-cols-1 overflow-y-auto font-[family-name:var(--adm-fuente)] sm:max-w-3xl"
            @close-auto-focus="alCerrarDetalle"
        >
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <DialogTitle class="adm-card-title mb-1">
                        Detalle de auditoría #{{ seleccionado.id }}
                    </DialogTitle>
                    <DialogDescription class="adm-muted text-xs">
                        {{ seleccionado.accionTexto }} en
                        {{ seleccionado.entidad }}. Los valores sensibles se
                        muestran ocultos.
                    </DialogDescription>
                </div>
                <DialogClose
                    class="adm-btn adm-btn-neutral shrink-0"
                    aria-label="Cerrar detalle"
                >
                    <X class="size-4" aria-hidden="true" />
                </DialogClose>
            </div>

            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                <div class="min-w-0">
                    <dt class="adm-label">Fecha y hora</dt>
                    <dd>{{ fecha(seleccionado.fecha) }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="adm-label">Usuario responsable</dt>
                    <dd class="font-medium break-words">
                        {{ seleccionado.usuario }}
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="adm-label">Dirección IP</dt>
                    <dd class="break-words">{{ seleccionado.ip || '—' }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="adm-label">Acción</dt>
                    <dd>
                        <span
                            class="adm-badge"
                            :class="insignia(seleccionado.accion)"
                            >{{ seleccionado.accionTexto }}</span
                        >
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="adm-label">Entidad</dt>
                    <dd class="break-words">{{ seleccionado.entidad }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="adm-label">Registro afectado</dt>
                    <dd class="break-words">
                        {{ seleccionado.registro || '—' }}
                    </dd>
                </div>
            </dl>

            <section
                v-if="datosDe(seleccionado).length"
                aria-labelledby="titulo-datos"
            >
                <h3 id="titulo-datos" class="adm-label mb-2">Datos</h3>
                <ul class="flex flex-wrap gap-2">
                    <li
                        v-for="dato in datosDe(seleccionado)"
                        :key="dato.campo"
                        class="adm-badge adm-badge-neutral max-w-full gap-1 break-all whitespace-pre-wrap"
                    >
                        <span class="font-semibold">{{ dato.campo }}:</span>
                        {{ dato.nuevo ?? '—' }}
                    </li>
                </ul>
            </section>

            <section aria-labelledby="titulo-campos">
                <h3 id="titulo-campos" class="adm-label mb-2">
                    Campos modificados
                </h3>
                <div
                    v-if="cambiosDe(seleccionado).length"
                    class="adm-table-scroll"
                >
                    <table class="adm-table">
                        <thead>
                            <tr>
                                <th scope="col">Campo</th>
                                <th scope="col">Valor anterior</th>
                                <th scope="col">Valor nuevo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="campo in cambiosDe(seleccionado)"
                                :key="campo.campo"
                            >
                                <th scope="row" class="font-medium">
                                    {{ campo.campo }}
                                </th>
                                <td
                                    v-for="(valor, indice) in [
                                        campo.anterior,
                                        campo.nuevo,
                                    ]"
                                    :key="indice"
                                    :class="{
                                        'adm-muted':
                                            valor === null || valor === '',
                                    }"
                                >
                                    <template v-if="valor === null">
                                        <span aria-hidden="true">—</span>
                                        <span class="sr-only">Sin valor</span>
                                    </template>
                                    <template v-else-if="valor === ''">
                                        (vacío)
                                    </template>
                                    <span
                                        v-else
                                        class="block max-w-[18rem] [overflow-wrap:anywhere] whitespace-pre-wrap"
                                        >{{ valor }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="adm-muted text-sm">Sin campos modificados.</p>
            </section>

            <div class="flex justify-end">
                <DialogClose class="adm-btn adm-btn-secondary">
                    Cerrar
                </DialogClose>
            </div>
        </DialogContent>
    </Dialog>
</template>
