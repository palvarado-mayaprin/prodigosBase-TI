<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronRight,
    Maximize2,
    Minimize2,
    ShieldCheck,
} from '@lucide/vue';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import {
    cerrarCarga,
    confirmarAccion,
    mostrarAdvertencia,
    mostrarCarga,
    mostrarError,
    mostrarExito,
} from '@/lib/alertas';
import {
    alternar,
    detalleCambios,
    diferencia,
    iniciales,
} from '@/lib/seleccion';
import { plural } from '@/lib/textos';
import { dashboard } from '@/routes';
import { permisos as rutaPermisos } from '@/routes/admin';
import { guardar as rutaGuardar } from '@/routes/admin/permisos';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Administración de Permisos', href: rutaPermisos() },
        ],
    },
});

type Grupo = {
    id: number;
    nombre: string;
    esAdministrador: boolean;
    activo: boolean;
    permisos: number[];
};

type Accion = { permisoId: number; accion: string; nombre: string };

type Funcionalidad = {
    codigo: string;
    nombre: string;
    descripcion: string | null;
    activo: boolean;
    soloAdministrador: boolean;
    acciones: Accion[];
};

type Modulo = {
    modulo: string;
    nombre: string;
    funcionalidades: Funcionalidad[];
};

type Area = { area: string; nombre: string; modulos: Modulo[] };

const props = defineProps<{
    grupos: Grupo[];
    areas: Area[];
    soloLectura: boolean;
}>();

const busquedaGrupos = ref('');
const busqueda = ref('');
const contraidos = ref(new Set<string>());
const grupoId = ref<number | null>(null);
const base = ref<number[]>([]);
const seleccion = ref<number[]>([]);
const idsSeleccion = computed(() => new Set(seleccion.value));
const idsBase = computed(() => new Set(base.value));
const procesando = ref(false);
// Solo presentación: amplía el panel de permisos y reduce el de grupos.
const panelExpandido = ref(false);

const grupoActual = computed(
    () => props.grupos.find((grupo) => grupo.id === grupoId.value) ?? null,
);
const esAdministrador = computed(
    () => grupoActual.value?.esAdministrador ?? false,
);
const edicionBloqueada = computed(
    () => props.soloLectura || esAdministrador.value || procesando.value,
);

const gruposFiltrados = computed(() => {
    const texto = busquedaGrupos.value.trim().toLowerCase();
    return props.grupos.filter((grupo) =>
        grupo.nombre.toLowerCase().includes(texto),
    );
});

// Rótulo legible de cada permiso para la confirmación.
const rotulos = computed(() => {
    const mapa = new Map<number, string>();
    for (const area of props.areas)
        for (const modulo of area.modulos)
            for (const funcionalidad of modulo.funcionalidades)
                for (const accion of funcionalidad.acciones)
                    mapa.set(
                        accion.permisoId,
                        `${funcionalidad.nombre} · ${accion.nombre}`,
                    );
    return mapa;
});

// Coincidencia por área, módulo, nombre o código; si coincide un nivel
// superior se muestra completo.
const areasFiltradas = computed(() => {
    const texto = busqueda.value.trim().toLowerCase();
    if (!texto) return props.areas;
    const coincide = (...valores: string[]) =>
        valores.some((valor) => valor.toLowerCase().includes(texto));
    return props.areas
        .map((area) => {
            if (coincide(area.nombre, area.area)) return area;
            const modulos = area.modulos
                .map((modulo) => {
                    if (coincide(modulo.nombre, modulo.modulo)) return modulo;
                    const funcionalidades = modulo.funcionalidades.filter(
                        (funcionalidad) =>
                            coincide(
                                funcionalidad.nombre,
                                funcionalidad.codigo,
                            ),
                    );
                    return funcionalidades.length
                        ? { ...modulo, funcionalidades }
                        : null;
                })
                .filter((modulo): modulo is Modulo => modulo !== null);
            return modulos.length ? { ...area, modulos } : null;
        })
        .filter((area): area is Area => area !== null);
});

const cambios = computed(() => diferencia(base.value, seleccion.value));

function idsDe(funcionalidades: Funcionalidad[]): number[] {
    return funcionalidades.flatMap((funcionalidad) =>
        funcionalidad.acciones.map((accion) => accion.permisoId),
    );
}

function idsDeArea(area: Area): number[] {
    return area.modulos.flatMap((modulo) => idsDe(modulo.funcionalidades));
}

function asignados(ids: number[]): number {
    return esAdministrador.value
        ? ids.length
        : ids.filter((id) => idsSeleccion.value.has(id)).length;
}

function pendiente(funcionalidad: Funcionalidad): boolean {
    return funcionalidad.acciones.some(
        (accion) =>
            idsSeleccion.value.has(accion.permisoId) !==
            idsBase.value.has(accion.permisoId),
    );
}

function marcada(accion: Accion): boolean {
    return esAdministrador.value || idsSeleccion.value.has(accion.permisoId);
}

// Una funcionalidad inactiva o reservada solo permite retirar asignaciones.
function deshabilitada(funcionalidad: Funcionalidad, accion: Accion): boolean {
    return (
        edicionBloqueada.value ||
        ((funcionalidad.soloAdministrador || !funcionalidad.activo) &&
            !idsSeleccion.value.has(accion.permisoId))
    );
}

function expandida(clave: string): boolean {
    return busqueda.value.trim() !== '' || !contraidos.value.has(clave);
}

function plegar(clave: string) {
    const siguiente = new Set(contraidos.value);
    if (siguiente.has(clave)) siguiente.delete(clave);
    else siguiente.add(clave);
    contraidos.value = siguiente;
}

function cargarGrupo(grupo: Grupo) {
    base.value = [...grupo.permisos];
    seleccion.value = [...grupo.permisos];
}

async function seleccionarGrupo(grupo: Grupo) {
    if (procesando.value || grupoId.value === grupo.id) return;
    if (
        cambios.value.hayCambios &&
        !(await confirmarAccion({
            titulo: 'Descartar cambios pendientes',
            descripcion: 'Hay cambios sin guardar. ¿Desea descartarlos?',
            confirmar: 'Descartar cambios',
        }))
    )
        return;
    grupoId.value = grupo.id;
    cargarGrupo(grupo);
}

function descartar() {
    seleccion.value = [...base.value];
}

async function guardar() {
    const grupo = grupoActual.value;
    if (!grupo || edicionBloqueada.value || !cambios.value.hayCambios) return;

    if (
        !(await confirmarAccion({
            titulo: 'Guardar permisos',
            descripcion: `Confirme los cambios del grupo ${grupo.nombre}:\n\n${detalleCambios(
                cambios.value,
                (id) => rotulos.value.get(id) ?? `Permiso ${id}`,
            )}`,
            confirmar: 'Guardar cambios',
        }))
    )
        return;

    procesando.value = true;
    type Resultado = {
        tipo: 'exito' | 'error' | 'advertencia';
        titulo: string;
        descripcion: string;
    };
    let resultado: Resultado | null = null;
    await mostrarCarga();
    router.put(
        rutaGuardar(grupo.id).url,
        { base: base.value, permisos: seleccion.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: async () => {
                await nextTick();
                const actualizado = grupoActual.value;
                if (actualizado) cargarGrupo(actualizado);
                resultado = {
                    tipo: 'exito',
                    titulo: 'Permisos guardados',
                    descripcion: `Los permisos del grupo ${grupo.nombre} se guardaron correctamente.`,
                };
            },
            onError: (errores) => {
                // Se conserva la selección para corregir o reintentar.
                resultado = errores.conflicto
                    ? {
                          tipo: 'advertencia',
                          titulo: 'Permisos modificados',
                          descripcion: errores.conflicto,
                      }
                    : {
                          tipo: 'error',
                          titulo: 'No se pudieron guardar los permisos',
                          descripcion:
                              errores.permisos ??
                              Object.values(errores)[0] ??
                              'Intente de nuevo más tarde.',
                      };
            },
            onFinish: () => {
                void cerrarCarga().then(() => {
                    procesando.value = false;
                    const final = resultado as Resultado | null;
                    if (!final) return;
                    const { tipo, ...mensaje } = final;
                    void (tipo === 'exito'
                        ? mostrarExito(mensaje)
                        : tipo === 'advertencia'
                          ? mostrarAdvertencia(mensaje)
                          : mostrarError(mensaje));
                });
            },
        },
    );
}

// Tras un conflicto, recarga los permisos vigentes y descarta la selección.
function recargar() {
    if (procesando.value) return;
    procesando.value = true;
    router.reload({
        only: ['grupos'],
        onSuccess: async () => {
            await nextTick();
            if (grupoActual.value) cargarGrupo(grupoActual.value);
        },
        onFinish: () => {
            procesando.value = false;
        },
    });
}

function advertirSalida(event: BeforeUnloadEvent) {
    if (!cambios.value.hayCambios) return;
    event.preventDefault();
}

onMounted(() => window.addEventListener('beforeunload', advertirSalida));
onUnmounted(() => window.removeEventListener('beforeunload', advertirSalida));
</script>

<template>
    <Head title="Administración de Permisos" />

    <div class="adm-main min-h-full">
        <div class="w-full min-w-0">
            <div class="mb-6">
                <h1 class="adm-page-title">ADMINISTRACIÓN DE PERMISOS</h1>
                <p class="adm-page-subtitle">
                    Permisos de los grupos por funcionalidad
                </p>
                <p v-if="!soloLectura" class="adm-page-note mt-3 text-sm">
                    Los cambios se aplican al guardar y requieren confirmación.
                </p>
            </div>

            <p
                v-if="soloLectura"
                class="adm-alert adm-alert-warning mb-6 font-semibold"
                role="status"
            >
                Consulta de producción: solo lectura.
            </p>

            <p
                v-if="areas.length === 0"
                class="adm-alert adm-alert-warning mb-6"
                role="status"
            >
                El catálogo no está sincronizado. Ejecute
                <code>php artisan adm:sincronizar-permisos</code>.
            </p>

            <div
                :class="[
                    'grid min-w-0 grid-cols-1',
                    panelExpandido
                        ? 'gap-3 lg:grid-cols-[4.5rem_minmax(0,1fr)]'
                        : 'gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,7fr)]',
                ]"
            >
                <section
                    v-if="panelExpandido"
                    class="adm-card adm-access-card p-3!"
                    :title="
                        grupoActual
                            ? `Grupo seleccionado: ${grupoActual.nombre}`
                            : undefined
                    "
                >
                    <h2 class="sr-only">Grupos</h2>
                    <div
                        class="flex min-w-0 items-center gap-3 lg:flex-col lg:gap-2"
                    >
                        <span class="adm-avatar" aria-hidden="true">{{
                            grupoActual ? iniciales(grupoActual.nombre) : '?'
                        }}</span>
                        <span
                            class="adm-muted min-w-0 truncate text-xs lg:sr-only"
                            >Lista de grupos oculta ·
                            {{ grupoActual?.nombre ?? '' }}</span
                        >
                    </div>
                </section>
                <section v-else class="adm-card adm-access-card">
                    <h2 class="adm-card-title">Grupos</h2>
                    <input
                        v-model="busquedaGrupos"
                        type="search"
                        placeholder="Buscar grupo..."
                        aria-label="Buscar grupos"
                        class="adm-input mb-4"
                    />
                    <p class="adm-muted mb-3 text-xs" aria-live="polite">
                        {{ gruposFiltrados.length }}
                        {{
                            plural(
                                gruposFiltrados.length,
                                'grupo encontrado',
                                'grupos encontrados',
                            )
                        }}
                    </p>
                    <div class="adm-access-list space-y-2">
                        <button
                            v-for="grupo in gruposFiltrados"
                            :key="grupo.id"
                            type="button"
                            :disabled="procesando"
                            :aria-pressed="grupoId === grupo.id"
                            :class="[
                                'adm-user-item',
                                grupoId === grupo.id ? 'is-selected' : '',
                            ]"
                            @click="seleccionarGrupo(grupo)"
                        >
                            <span class="adm-user-name">{{
                                grupo.nombre
                            }}</span>
                            <span class="adm-user-code"
                                >{{
                                    grupo.esAdministrador
                                        ? 'Grupo administrador · acceso universal'
                                        : 'Grupo de permisos'
                                }}{{ grupo.activo ? '' : ' · Inactivo' }}</span
                            >
                        </button>
                        <p
                            v-if="gruposFiltrados.length === 0"
                            class="adm-muted py-6 text-center text-sm"
                        >
                            No se encontraron grupos.
                        </p>
                    </div>
                </section>

                <section class="adm-card adm-access-card">
                    <template v-if="grupoActual">
                        <div class="adm-selected-header mb-4 pb-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="adm-identity min-w-0">
                                    <span
                                        class="adm-avatar is-large"
                                        aria-hidden="true"
                                        >{{
                                            iniciales(grupoActual.nombre)
                                        }}</span
                                    >
                                    <div class="adm-identity-text">
                                        <h2
                                            class="adm-card-title adm-selected-title"
                                        >
                                            {{ grupoActual.nombre }}
                                        </h2>
                                        <p class="adm-muted text-sm">
                                            {{
                                                grupoActual.activo
                                                    ? 'Grupo activo'
                                                    : 'Grupo inactivo: sus permisos no se aplican'
                                            }}
                                        </p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="adm-btn adm-btn-neutral shrink-0"
                                    :aria-pressed="panelExpandido"
                                    :aria-label="
                                        panelExpandido
                                            ? 'Restaurar paneles'
                                            : 'Expandir panel de permisos'
                                    "
                                    :title="
                                        panelExpandido
                                            ? 'Restaurar paneles'
                                            : 'Expandir panel de permisos'
                                    "
                                    @click="panelExpandido = !panelExpandido"
                                >
                                    <component
                                        :is="
                                            panelExpandido
                                                ? Minimize2
                                                : Maximize2
                                        "
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </button>
                            </div>
                            <p class="adm-count-tag mt-3">
                                Permisos asignados:
                                <strong>{{
                                    esAdministrador ? 'todos' : seleccion.length
                                }}</strong>
                            </p>
                        </div>

                        <p
                            v-if="esAdministrador"
                            class="adm-alert adm-alert-info mb-4 flex items-center gap-2"
                            role="status"
                        >
                            <ShieldCheck
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            El grupo administrador tiene acceso universal a
                            todas las funcionalidades. Sus permisos no se
                            editan.
                        </p>

                        <input
                            v-model="busqueda"
                            type="search"
                            placeholder="Buscar por área, módulo, funcionalidad o código..."
                            aria-label="Buscar funcionalidades por área, módulo, nombre o código"
                            class="adm-input mb-4"
                        />

                        <div class="adm-access-groups space-y-4">
                            <p
                                v-if="areasFiltradas.length === 0"
                                class="adm-muted py-6 text-center text-sm"
                            >
                                No se encontraron funcionalidades.
                            </p>
                            <div
                                v-for="area in areasFiltradas"
                                :key="area.area"
                                class="adm-tree-area"
                            >
                                <button
                                    type="button"
                                    class="adm-tree-area-toggle"
                                    :aria-expanded="expandida(`a:${area.area}`)"
                                    @click="plegar(`a:${area.area}`)"
                                >
                                    <component
                                        :is="
                                            expandida(`a:${area.area}`)
                                                ? ChevronDown
                                                : ChevronRight
                                        "
                                        class="size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span class="flex-1">{{
                                        area.nombre
                                    }}</span>
                                    <span
                                        class="adm-badge adm-badge-neutral normal-case"
                                        >{{ asignados(idsDeArea(area)) }} de
                                        {{ idsDeArea(area).length }}</span
                                    >
                                </button>

                                <div
                                    v-if="expandida(`a:${area.area}`)"
                                    class="space-y-3 p-3"
                                >
                                    <div
                                        v-for="modulo in area.modulos"
                                        :key="modulo.modulo"
                                    >
                                        <button
                                            type="button"
                                            class="adm-tree-module-toggle"
                                            :aria-expanded="
                                                expandida(
                                                    `m:${area.area}.${modulo.modulo}`,
                                                )
                                            "
                                            @click="
                                                plegar(
                                                    `m:${area.area}.${modulo.modulo}`,
                                                )
                                            "
                                        >
                                            <component
                                                :is="
                                                    expandida(
                                                        `m:${area.area}.${modulo.modulo}`,
                                                    )
                                                        ? ChevronDown
                                                        : ChevronRight
                                                "
                                                class="size-4 shrink-0"
                                                aria-hidden="true"
                                            />
                                            <span class="flex-1">{{
                                                modulo.nombre
                                            }}</span>
                                            <span class="adm-tree-count"
                                                >{{
                                                    asignados(
                                                        idsDe(
                                                            modulo.funcionalidades,
                                                        ),
                                                    )
                                                }}
                                                de
                                                {{
                                                    idsDe(
                                                        modulo.funcionalidades,
                                                    ).length
                                                }}</span
                                            >
                                        </button>

                                        <ul
                                            v-if="
                                                expandida(
                                                    `m:${area.area}.${modulo.modulo}`,
                                                )
                                            "
                                            class="mt-2 space-y-2 pl-2 sm:pl-6"
                                        >
                                            <li
                                                v-for="funcionalidad in modulo.funcionalidades"
                                                :key="funcionalidad.codigo"
                                                class="adm-tree-feature"
                                            >
                                                <div
                                                    class="flex flex-wrap items-center gap-2"
                                                >
                                                    <span
                                                        class="adm-group-name"
                                                        >{{
                                                            funcionalidad.nombre
                                                        }}</span
                                                    >
                                                    <span
                                                        v-if="
                                                            pendiente(
                                                                funcionalidad,
                                                            )
                                                        "
                                                        class="adm-change-chip is-warning"
                                                        >Cambios sin
                                                        guardar</span
                                                    >
                                                    <span
                                                        v-if="
                                                            funcionalidad.soloAdministrador
                                                        "
                                                        class="adm-badge adm-badge-neutral"
                                                        >Solo
                                                        administrador</span
                                                    >
                                                    <span
                                                        v-if="
                                                            !funcionalidad.activo
                                                        "
                                                        class="adm-badge adm-badge-danger"
                                                        >Inactiva</span
                                                    >
                                                </div>
                                                <p
                                                    class="adm-group-code mt-1 font-mono break-all"
                                                >
                                                    {{ funcionalidad.codigo }}
                                                </p>
                                                <p
                                                    v-if="
                                                        funcionalidad.descripcion
                                                    "
                                                    class="adm-tree-feature-description mt-1"
                                                >
                                                    {{
                                                        funcionalidad.descripcion
                                                    }}
                                                </p>
                                                <div
                                                    class="mt-3 grid gap-2 sm:grid-cols-2"
                                                    role="group"
                                                    :aria-label="`Acciones de ${funcionalidad.nombre}`"
                                                >
                                                    <label
                                                        v-for="accion in funcionalidad.acciones"
                                                        :key="accion.permisoId"
                                                        :class="[
                                                            'adm-group-option',
                                                            esAdministrador
                                                                ? 'is-admin'
                                                                : '',
                                                        ]"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            class="adm-checkbox h-4 w-4"
                                                            :checked="
                                                                marcada(accion)
                                                            "
                                                            :disabled="
                                                                deshabilitada(
                                                                    funcionalidad,
                                                                    accion,
                                                                )
                                                            "
                                                            @change="
                                                                seleccion =
                                                                    alternar(
                                                                        seleccion,
                                                                        accion.permisoId,
                                                                        (
                                                                            $event.target as HTMLInputElement
                                                                        )
                                                                            .checked,
                                                                    )
                                                            "
                                                        />
                                                        <span>
                                                            <span
                                                                class="adm-group-name"
                                                                >{{
                                                                    accion.nombre
                                                                }}</span
                                                            >
                                                            <span
                                                                class="adm-group-code"
                                                                >{{
                                                                    accion.accion
                                                                }}</span
                                                            >
                                                        </span>
                                                    </label>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="!esAdministrador"
                            class="mt-5 flex flex-wrap items-center gap-2"
                            role="status"
                            aria-live="polite"
                        >
                            <template v-if="cambios.hayCambios">
                                <span class="adm-change-chip is-add"
                                    ><span aria-hidden="true">+</span
                                    >{{ cambios.agregados.length }} por
                                    agregar</span
                                >
                                <span class="adm-change-chip is-remove"
                                    ><span aria-hidden="true">−</span
                                    >{{ cambios.retirados.length }} por
                                    retirar</span
                                >
                                <span class="adm-change-chip is-warning"
                                    ><span aria-hidden="true">⚠</span>Cambios
                                    sin guardar</span
                                >
                            </template>
                            <span v-else class="adm-change-chip is-clear"
                                ><span aria-hidden="true">✓</span>Sin cambios
                                pendientes</span
                            >
                        </div>
                        <div
                            v-if="!soloLectura && !esAdministrador"
                            class="mt-4 flex flex-wrap gap-3"
                        >
                            <button
                                type="button"
                                class="adm-btn adm-btn-primary"
                                :disabled="!cambios.hayCambios || procesando"
                                :aria-busy="procesando ? 'true' : undefined"
                                @click="guardar"
                            >
                                {{
                                    procesando
                                        ? 'Guardando...'
                                        : 'Guardar cambios'
                                }}
                            </button>
                            <button
                                type="button"
                                class="adm-btn adm-btn-secondary"
                                :disabled="!cambios.hayCambios || procesando"
                                @click="descartar"
                            >
                                Descartar cambios
                            </button>
                            <button
                                type="button"
                                class="adm-btn adm-btn-neutral"
                                :disabled="procesando"
                                @click="recargar"
                            >
                                Recargar permisos
                            </button>
                        </div>
                    </template>
                    <div
                        v-else
                        class="adm-muted flex min-h-[350px] flex-col items-center justify-center text-center"
                    >
                        <ShieldCheck class="mb-4 size-12" aria-hidden="true" />
                        <h2 class="text-lg font-semibold">
                            Seleccione un grupo
                        </h2>
                        <p class="mt-2 text-sm">
                            {{
                                soloLectura
                                    ? 'Podrá consultar sus permisos por funcionalidad.'
                                    : 'Podrá consultar y editar sus permisos por funcionalidad.'
                            }}
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
