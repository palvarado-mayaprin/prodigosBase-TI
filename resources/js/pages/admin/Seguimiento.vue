<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ChartColumn,
    Download,
    Filter,
    LoaderCircle,
    RotateCcw,
    ShieldCheck,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { duracion, fechaDia, fechaHora } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { seguimiento } from '@/routes/admin';
import { exportar } from '@/routes/admin/seguimiento';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Seguimiento de Uso', href: seguimiento() },
        ],
    },
});

// Contrato de SeguimientoController@index (App\Services\Admin\SeguimientoUso).
type Filtros = {
    desde: string | null;
    hasta: string | null;
    usuario: string | null;
    modulo: string | null;
};
type ClaveFiltro = keyof Filtros;
type FilaModulo = {
    modulo: string;
    nombre: string;
    visitas: number;
    usuarios: number;
    segundosActivos: number;
    promedioPorVisita: number;
};
type FilaUsuario = {
    usuario: string;
    sesiones: number;
    visitas: number;
    segundosActivos: number;
};
type FilaDia = {
    fecha: string;
    usuarios: number;
    sesiones: number;
    visitas: number;
    segundosActivos: number;
};
type FilaHistorial = {
    id: number;
    usuario: string;
    ip: string | null;
    inicioSesion: string | null;
    modulo: string;
    nombre: string;
    ruta: string;
    visita: string | null;
    segundosActivos: number;
};

const props = defineProps<{
    filtros: Filtros;
    indicadores: {
        sesiones: number;
        usuarios: number;
        visitas: number;
        segundosActivos: number;
        promedioPorSesion: number;
    };
    porUsuario: FilaUsuario[];
    porModulo: FilaModulo[];
    diario: FilaDia[];
    historial: {
        data: FilaHistorial[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    modulos: Record<string, string>;
}>();

const page = usePage();
const zona = computed(() => page.props.zonaHoraria);
const errores = computed(
    () => Object.values(page.props.errors ?? {}) as string[],
);

const numero = new Intl.NumberFormat('es');
const n = (valor: number) => numero.format(valor);
const fecha = (valor: string | null) => fechaHora(valor, zona.value);

// ---------- Filtros ----------
const claves: ClaveFiltro[] = ['desde', 'hasta', 'usuario', 'modulo'];
const vacios = (): Record<ClaveFiltro, string> => ({
    desde: '',
    hasta: '',
    usuario: '',
    modulo: '',
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

function consulta(
    valores: Record<ClaveFiltro, string | null>,
    pagina = 1,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    for (const clave of claves) {
        const valor = valores[clave]?.trim();
        if (valor) query[clave] = valor;
    }
    if (pagina > 1) query.page = pagina;
    return query;
}

const aplicados = computed(() => consulta(props.filtros));
const hayFiltros = computed(() => Object.keys(aplicados.value).length > 0);
const sinAplicar = computed(
    () => JSON.stringify(consulta(form)) !== JSON.stringify(aplicados.value),
);
const errorFechas = computed(() =>
    form.desde && form.hasta && form.hasta < form.desde
        ? 'La fecha final no puede ser anterior a la inicial.'
        : '',
);

// Una sola consulta a la vez: los botones se deshabilitan mientras carga.
const cargando = ref(false);

function cargar(query: Record<string, string | number>) {
    if (cargando.value) return;
    cargando.value = true;
    router.get(seguimiento().url, query, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            cargando.value = false;
        },
    });
}

function aplicar() {
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

// ---------- Indicadores y gráficos ----------
const indicadores = computed(() => [
    {
        titulo: 'Usuarios únicos',
        valor: n(props.indicadores.usuarios),
        detalle: 'Con al menos una visita',
    },
    {
        titulo: 'Sesiones con actividad',
        valor: n(props.indicadores.sesiones),
        detalle: 'Inicios de sesión con visitas registradas',
    },
    {
        titulo: 'Visitas a módulos',
        valor: n(props.indicadores.visitas),
        detalle: 'Pantallas abiertas',
    },
    {
        titulo: 'Tiempo activo total',
        valor: duracion(props.indicadores.segundosActivos),
        detalle: `${n(props.indicadores.segundosActivos)} s`,
    },
    {
        titulo: 'Promedio por sesión',
        valor: duracion(props.indicadores.promedioPorSesion),
        detalle: `${n(props.indicadores.promedioPorSesion)} s por sesión con actividad`,
    },
]);

const sinDatos = computed(() => props.indicadores.visitas === 0);

type Metrica = 'segundosActivos' | 'visitas' | 'usuarios';
const metricas: { clave: Metrica; titulo: string }[] = [
    { clave: 'segundosActivos', titulo: 'Tiempo activo' },
    { clave: 'visitas', titulo: 'Visitas' },
    { clave: 'usuarios', titulo: 'Usuarios' },
];
const metrica = ref<Metrica>('segundosActivos');
const valorMetrica = (dia: FilaDia) =>
    metrica.value === 'segundosActivos'
        ? duracion(dia.segundosActivos)
        : n(dia[metrica.value]);

/** Porcentaje respecto del máximo; un valor positivo siempre se ve. */
function proporcion(valor: number, maximo: number): string {
    if (maximo <= 0 || valor <= 0) return '0%';
    return `${Math.max(2, (valor / maximo) * 100)}%`;
}

const maxDia = computed(() =>
    Math.max(0, ...props.diario.map((d) => d[metrica.value])),
);
const maxModulo = computed(() =>
    Math.max(0, ...props.porModulo.map((m) => m.segundosActivos)),
);
const maxUsuario = computed(() =>
    Math.max(0, ...props.porUsuario.map((u) => u.segundosActivos)),
);

// ---------- Exportaciones (usan los filtros aplicados) ----------
const reportes = [
    {
        clave: 'historial',
        titulo: 'Historial completo',
        descripcion: 'Cada visita con su sesión, sin límite de página.',
    },
    {
        clave: 'usuarios',
        titulo: 'Resumen por usuario',
        descripcion: 'Sesiones, visitas y tiempo activo de cada usuario.',
    },
    {
        clave: 'modulos',
        titulo: 'Resumen por módulo',
        descripcion: 'Visitas, usuarios y tiempo activo de cada módulo.',
    },
    {
        clave: 'diario',
        titulo: 'Resumen diario',
        descripcion: 'Usuarios, sesiones, visitas y tiempo por día.',
    },
] as const;

const urlExportar = (reporte: string, formato: 'csv' | 'xlsx') =>
    exportar({ reporte, formato }, { query: aplicados.value }).url;
</script>

<template>
    <Head title="Seguimiento de Uso" />

    <div class="adm-main min-h-full">
        <div class="w-full min-w-0">
            <div class="mb-6">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="adm-page-title">SEGUIMIENTO DE USO</h1>
                    <span class="adm-badge adm-badge-neutral">
                        <ShieldCheck class="mr-1 size-3.5" aria-hidden="true" />
                        Módulo administrativo
                    </span>
                </div>
                <p class="adm-page-subtitle">
                    Visitas, usuarios y tiempo activo
                </p>
                <p class="adm-page-note mt-3 text-sm">
                    Fechas y horas en la zona horaria {{ zona }}. El tiempo
                    activo cuenta solo con la pestaña visible y se detiene tras
                    5 minutos sin actividad. Con la base en solo lectura no se
                    registra actividad.
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
                <form novalidate @submit.prevent="aplicar">
                    <div
                        class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
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
                                >Usuario</label
                            >
                            <input
                                id="filtro-usuario"
                                v-model="form.usuario"
                                name="usuario"
                                class="adm-input"
                                maxlength="50"
                                autocomplete="off"
                                list="lista-usuarios"
                                placeholder="Usuario exacto"
                                :disabled="cargando"
                            />
                            <datalist id="lista-usuarios">
                                <option
                                    v-for="fila in porUsuario"
                                    :key="fila.usuario"
                                    :value="fila.usuario"
                                />
                            </datalist>
                        </div>
                        <div class="min-w-0">
                            <label for="filtro-modulo" class="adm-label"
                                >Módulo</label
                            >
                            <select
                                id="filtro-modulo"
                                v-model="form.modulo"
                                name="modulo"
                                class="adm-input"
                                :disabled="cargando"
                            >
                                <option value="">Todos</option>
                                <option
                                    v-for="(nombre, codigo) in modulos"
                                    :key="codigo"
                                    :value="codigo"
                                >
                                    {{ nombre }}
                                </option>
                            </select>
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
                                Sin filtros: se muestra toda la actividad
                                registrada.
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
                                Limpiar
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
                                <Filter
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ cargando ? 'Aplicando…' : 'Aplicar' }}
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

            <div
                :aria-busy="cargando ? 'true' : undefined"
                :class="[
                    'transition-opacity',
                    cargando ? 'pointer-events-none opacity-60' : undefined,
                ]"
            >
                <!-- Indicadores -->
                <section
                    class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5"
                    aria-label="Indicadores"
                >
                    <div
                        v-for="indicador in indicadores"
                        :key="indicador.titulo"
                        class="adm-card adm-stat-card min-w-0"
                    >
                        <p class="adm-stat-label">{{ indicador.titulo }}</p>
                        <p class="adm-stat-value break-words">
                            {{ indicador.valor }}
                        </p>
                        <p class="adm-muted mt-1 text-xs">
                            {{ indicador.detalle }}
                        </p>
                    </div>
                </section>

                <div
                    v-if="sinDatos"
                    class="adm-alert adm-alert-info mb-6"
                    role="status"
                >
                    {{
                        hayFiltros
                            ? 'No hay actividad para los filtros aplicados. Amplíe el rango de fechas o limpie los filtros.'
                            : 'Todavía no hay actividad registrada.'
                    }}
                </div>

                <template v-else>
                    <!-- Actividad diaria -->
                    <section
                        class="adm-card mb-6"
                        aria-labelledby="titulo-diario"
                    >
                        <div
                            class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <h2
                                id="titulo-diario"
                                class="adm-card-title flex items-center gap-2"
                            >
                                <ChartColumn
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Actividad diaria
                            </h2>
                            <div
                                class="adm-view-switch"
                                role="group"
                                aria-label="Medida del gráfico"
                            >
                                <button
                                    v-for="opcion in metricas"
                                    :key="opcion.clave"
                                    type="button"
                                    :class="[
                                        'adm-view-switch-button',
                                        metrica === opcion.clave
                                            ? 'is-active'
                                            : undefined,
                                    ]"
                                    :aria-pressed="metrica === opcion.clave"
                                    @click="metrica = opcion.clave"
                                >
                                    {{ opcion.titulo }}
                                </button>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <ul
                                class="adm-chart flex h-44 items-end gap-0.5"
                                :style="{
                                    minWidth: `${diario.length * 14}px`,
                                }"
                                :aria-label="`Actividad diaria: ${metricas.find((m) => m.clave === metrica)?.titulo}`"
                            >
                                <li
                                    v-for="dia in diario"
                                    :key="dia.fecha"
                                    class="relative flex h-full min-w-3 flex-1 items-end"
                                    :title="`${fechaDia(dia.fecha)}: ${valorMetrica(dia)}`"
                                >
                                    <span
                                        class="adm-chart-bar"
                                        :style="{
                                            height: proporcion(
                                                dia[metrica],
                                                maxDia,
                                            ),
                                        }"
                                    />
                                    <span class="sr-only"
                                        >{{ fechaDia(dia.fecha) }}:
                                        {{ valorMetrica(dia) }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                        <div
                            class="adm-muted mt-1 flex justify-between text-xs"
                            aria-hidden="true"
                        >
                            <span>{{ fechaDia(diario[0].fecha) }}</span>
                            <span v-if="diario.length > 1">{{
                                fechaDia(diario[diario.length - 1].fecha)
                            }}</span>
                        </div>
                        <details class="mt-4">
                            <summary class="adm-details-summary">
                                Ver tabla diaria ({{ diario.length }} días)
                            </summary>
                            <div class="adm-table-scroll mt-3">
                                <table class="adm-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Fecha</th>
                                            <th scope="col" class="is-num">
                                                Usuarios únicos
                                            </th>
                                            <th scope="col" class="is-num">
                                                Sesiones con actividad
                                            </th>
                                            <th scope="col" class="is-num">
                                                Visitas
                                            </th>
                                            <th scope="col" class="is-num">
                                                Tiempo activo
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="dia in diario"
                                            :key="dia.fecha"
                                        >
                                            <td>{{ fechaDia(dia.fecha) }}</td>
                                            <td class="is-num">
                                                {{ n(dia.usuarios) }}
                                            </td>
                                            <td class="is-num">
                                                {{ n(dia.sesiones) }}
                                            </td>
                                            <td class="is-num">
                                                {{ n(dia.visitas) }}
                                            </td>
                                            <td
                                                class="is-num"
                                                :title="`${n(dia.segundosActivos)} s`"
                                            >
                                                {{
                                                    duracion(
                                                        dia.segundosActivos,
                                                    )
                                                }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="adm-muted mt-2 text-xs">
                                Una sesión que cruza la medianoche cuenta en
                                cada día con visitas.
                            </p>
                        </details>
                    </section>

                    <div class="mb-6 grid grid-cols-1 gap-6 2xl:grid-cols-2">
                        <!-- Módulos -->
                        <section
                            class="adm-card min-w-0"
                            aria-labelledby="titulo-modulos"
                        >
                            <h2 id="titulo-modulos" class="adm-card-title mb-4">
                                Módulos más utilizados
                            </h2>
                            <div class="adm-table-scroll">
                                <table class="adm-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Módulo</th>
                                            <th scope="col">Tiempo activo</th>
                                            <th scope="col" class="is-num">
                                                Visitas
                                            </th>
                                            <th scope="col" class="is-num">
                                                Usuarios
                                            </th>
                                            <th scope="col" class="is-num">
                                                Promedio por visita
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="fila in porModulo"
                                            :key="fila.modulo"
                                        >
                                            <td>
                                                <span class="font-medium">{{
                                                    fila.nombre
                                                }}</span>
                                                <span
                                                    class="adm-muted block text-xs"
                                                    >{{ fila.modulo }}</span
                                                >
                                            </td>
                                            <td
                                                class="min-w-40"
                                                :title="`${n(fila.segundosActivos)} s`"
                                            >
                                                <span
                                                    class="adm-meter"
                                                    :style="{
                                                        width: proporcion(
                                                            fila.segundosActivos,
                                                            maxModulo,
                                                        ),
                                                    }"
                                                    aria-hidden="true"
                                                />
                                                {{
                                                    duracion(
                                                        fila.segundosActivos,
                                                    )
                                                }}
                                            </td>
                                            <td class="is-num">
                                                {{ n(fila.visitas) }}
                                            </td>
                                            <td class="is-num">
                                                {{ n(fila.usuarios) }}
                                            </td>
                                            <td
                                                class="is-num"
                                                :title="`${n(fila.promedioPorVisita)} s`"
                                            >
                                                {{
                                                    duracion(
                                                        fila.promedioPorVisita,
                                                    )
                                                }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <!-- Usuarios -->
                        <section
                            class="adm-card min-w-0"
                            aria-labelledby="titulo-usuarios"
                        >
                            <h2
                                id="titulo-usuarios"
                                class="adm-card-title mb-4"
                            >
                                Resumen de uso por usuario
                            </h2>
                            <div class="adm-table-scroll">
                                <table class="adm-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">Usuario</th>
                                            <th scope="col">Tiempo activo</th>
                                            <th scope="col" class="is-num">
                                                Sesiones con actividad
                                            </th>
                                            <th scope="col" class="is-num">
                                                Visitas
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="fila in porUsuario"
                                            :key="fila.usuario"
                                        >
                                            <td class="font-medium">
                                                {{ fila.usuario }}
                                            </td>
                                            <td
                                                class="min-w-40"
                                                :title="`${n(fila.segundosActivos)} s`"
                                            >
                                                <span
                                                    class="adm-meter"
                                                    :style="{
                                                        width: proporcion(
                                                            fila.segundosActivos,
                                                            maxUsuario,
                                                        ),
                                                    }"
                                                    aria-hidden="true"
                                                />
                                                {{
                                                    duracion(
                                                        fila.segundosActivos,
                                                    )
                                                }}
                                            </td>
                                            <td class="is-num">
                                                {{ n(fila.sesiones) }}
                                            </td>
                                            <td class="is-num">
                                                {{ n(fila.visitas) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>

                    <!-- Historial -->
                    <section
                        class="adm-card mb-6"
                        aria-labelledby="titulo-historial"
                    >
                        <div
                            class="mb-4 flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <h2 id="titulo-historial" class="adm-card-title">
                                Historial de visitas
                            </h2>
                            <p class="adm-muted text-xs">
                                {{ n(historial.from ?? 0) }}–{{
                                    n(historial.to ?? 0)
                                }}
                                de {{ n(historial.total) }} visitas
                            </p>
                        </div>
                        <div class="adm-table-scroll">
                            <table class="adm-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Usuario</th>
                                        <th scope="col">IP</th>
                                        <th scope="col">Inicio de sesión</th>
                                        <th scope="col">Módulo</th>
                                        <th scope="col">Ruta</th>
                                        <th scope="col">
                                            Fecha y hora de visita
                                        </th>
                                        <th scope="col" class="is-num">
                                            Tiempo activo
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="fila in historial.data"
                                        :key="fila.id"
                                    >
                                        <td class="font-medium">
                                            {{ fila.usuario }}
                                        </td>
                                        <td>{{ fila.ip ?? '—' }}</td>
                                        <td>{{ fecha(fila.inicioSesion) }}</td>
                                        <td>{{ fila.nombre }}</td>
                                        <td class="is-wrap">{{ fila.ruta }}</td>
                                        <td>{{ fecha(fila.visita) }}</td>
                                        <td
                                            class="is-num"
                                            :title="`${n(fila.segundosActivos)} s`"
                                        >
                                            {{ duracion(fila.segundosActivos) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <nav
                            v-if="historial.last_page > 1"
                            class="adm-table-pagination"
                            aria-label="Páginas del historial"
                        >
                            <button
                                type="button"
                                :disabled="
                                    historial.current_page <= 1 || cargando
                                "
                                @click="irAPagina(historial.current_page - 1)"
                            >
                                Anterior
                            </button>
                            <span
                                >Página {{ n(historial.current_page) }} de
                                {{ n(historial.last_page) }}</span
                            >
                            <button
                                type="button"
                                :disabled="
                                    historial.current_page >=
                                        historial.last_page || cargando
                                "
                                @click="irAPagina(historial.current_page + 1)"
                            >
                                Siguiente
                            </button>
                        </nav>
                    </section>
                </template>

                <!-- Exportaciones -->
                <section class="adm-card" aria-labelledby="titulo-exportar">
                    <h2
                        id="titulo-exportar"
                        class="adm-card-title mb-1 flex items-center gap-2"
                    >
                        <Download class="size-4" aria-hidden="true" />
                        Exportar reportes
                    </h2>
                    <p class="adm-muted mb-4 text-xs">
                        Incluyen todos los registros de los filtros aplicados,
                        no solo la página visible. Fechas en la zona horaria
                        {{ zona }}.
                    </p>
                    <ul class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <li
                            v-for="reporte in reportes"
                            :key="reporte.clave"
                            class="adm-export-item flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-semibold">
                                    {{ reporte.titulo }}
                                </p>
                                <p class="adm-muted text-xs">
                                    {{ reporte.descripcion }}
                                </p>
                            </div>
                            <div class="flex shrink-0 gap-2">
                                <a
                                    class="adm-btn adm-btn-secondary"
                                    :href="urlExportar(reporte.clave, 'csv')"
                                    :aria-label="`${reporte.titulo} en CSV`"
                                    download
                                    >CSV</a
                                >
                                <a
                                    class="adm-btn adm-btn-secondary"
                                    :href="urlExportar(reporte.clave, 'xlsx')"
                                    :aria-label="`${reporte.titulo} en Excel`"
                                    download
                                    >Excel</a
                                >
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</template>
