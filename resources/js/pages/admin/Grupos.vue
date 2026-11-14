<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Eye,
    Pencil,
    ShieldCheck,
    ToggleLeft,
    ToggleRight,
    Users,
    UsersRound,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import {
    cerrarCarga,
    confirmarAccion,
    mostrarCarga,
    mostrarError,
    mostrarExito,
} from '@/lib/alertas';
import { iniciales } from '@/lib/seleccion';
import { plural } from '@/lib/textos';
import { dashboard } from '@/routes';
import { grupos as rutaGrupos } from '@/routes/admin';
import { actualizar, crear, estado } from '@/routes/admin/grupos';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Administración de Grupos', href: rutaGrupos() },
        ],
    },
});

type Integrante = {
    id: number;
    usuario: string;
    nombreCompleto: string;
    activo: boolean;
};

type Grupo = {
    id: number;
    nombre: string;
    descripcion: string | null;
    esAdministrador: boolean;
    activo: boolean;
    integrantes: Integrante[];
    permisos: string[];
};

const props = defineProps<{
    grupos: Grupo[];
    soloLectura: boolean;
}>();

// Búsqueda local por nombre o descripción.
const busqueda = ref('');
const gruposFiltrados = computed(() => {
    const palabras = busqueda.value.trim().toLowerCase().split(/\s+/);
    return props.grupos.filter((grupo) => {
        const texto =
            `${grupo.nombre} ${grupo.descripcion ?? ''}`.toLowerCase();
        return palabras.every((palabra) => texto.includes(palabra));
    });
});

// Detalle de consulta: integrantes y permisos del grupo elegido.
const detalleId = ref<number | null>(null);
const detalle = computed(
    () => props.grupos.find((grupo) => grupo.id === detalleId.value) ?? null,
);

async function verDetalle(grupo: Grupo) {
    detalleId.value = detalleId.value === grupo.id ? null : grupo.id;
    await nextTick();
    document.getElementById('detalle-grupo')?.focus();
}

// Guardado: un solo envío a la vez, con el indicador bloqueante.
const procesando = ref(false);
type Resultado = { exito: boolean; titulo: string; descripcion: string };

async function enviar(
    ejecutar: (opciones: {
        preserveScroll: true;
        onSuccess: () => void;
        onError: (errores: Record<string, string>) => void;
        onFinish: () => void;
    }) => void,
    exito: Omit<Resultado, 'exito'>,
    error: (errores: Record<string, string>) => Omit<Resultado, 'exito'>,
    alGuardar?: () => void,
) {
    procesando.value = true;
    let resultado: Resultado | null = null;
    await mostrarCarga();
    ejecutar({
        preserveScroll: true,
        onSuccess: () => {
            alGuardar?.();
            resultado = { exito: true, ...exito };
        },
        onError: (errores) => {
            resultado = { exito: false, ...error(errores) };
        },
        onFinish: () => {
            void cerrarCarga().then(() => {
                procesando.value = false;
                if (!resultado) return;
                const { exito: ok, ...mensaje } = resultado;
                void (ok ? mostrarExito(mensaje) : mostrarError(mensaje));
            });
        },
    });
}

// null: formulario cerrado; 0: alta; otro: id en edición.
const editando = ref<number | null>(null);
const esAlta = computed(() => editando.value === 0);
const vacio = {
    nombre: '',
    descripcion: '',
    es_administrador: false,
    activo: true,
};
const form = useForm({ ...vacio });

async function abrir(grupo?: Grupo) {
    form.defaults(
        grupo
            ? {
                  nombre: grupo.nombre,
                  descripcion: grupo.descripcion ?? '',
                  es_administrador: grupo.esAdministrador,
                  activo: grupo.activo,
              }
            : { ...vacio },
    );
    form.reset();
    form.clearErrors();
    editando.value = grupo?.id ?? 0;
    await nextTick();
    document.getElementById('nombre')?.focus();
}

function cerrar() {
    editando.value = null;
    form.defaults({ ...vacio });
    form.reset();
    form.clearErrors();
}

function guardar() {
    if (procesando.value || editando.value === null) return;
    const id = editando.value;
    const alta = id === 0;
    void enviar(
        (opciones) => {
            if (alta) {
                form.transform((datos) => datos).post(crear().url, opciones);
            } else {
                // El carácter administrador se fija al crear y el estado
                // tiene su propia acción.
                form.transform(({ nombre, descripcion }) => ({
                    nombre,
                    descripcion,
                })).put(actualizar(id).url, opciones);
            }
        },
        {
            titulo: alta ? 'Grupo creado' : 'Grupo actualizado',
            descripcion: 'Los cambios se guardaron correctamente.',
        },
        () => ({
            titulo: 'No se pudo guardar',
            descripcion: 'Revise los campos marcados e intente de nuevo.',
        }),
        cerrar,
    );
}

async function alternarEstado(grupo: Grupo) {
    if (procesando.value) return;
    const activar = !grupo.activo;
    const confirmado = await confirmarAccion({
        titulo: activar ? 'Activar grupo' : 'Desactivar grupo',
        descripcion: activar
            ? `¿Activar ${grupo.nombre}? Sus integrantes recuperarán sus permisos.`
            : `¿Desactivar ${grupo.nombre}? Sus integrantes dejarán de recibir sus permisos.`,
        confirmar: activar ? 'Activar' : 'Desactivar',
    });
    if (!confirmado || procesando.value) return;

    void enviar(
        (opciones) =>
            router.put(estado(grupo.id).url, { activo: activar }, opciones),
        {
            titulo: activar ? 'Grupo activado' : 'Grupo desactivado',
            descripcion: `${grupo.nombre} se actualizó correctamente.`,
        },
        (errores) => ({
            titulo: 'No se pudo cambiar el estado',
            descripcion: errores.activo ?? 'Intente de nuevo más tarde.',
        }),
    );
}
</script>

<template>
    <Head title="Administración de Grupos" />

    <div class="adm-main min-h-full">
        <div class="w-full min-w-0">
            <div class="mb-6">
                <h1 class="adm-page-title">ADMINISTRACIÓN DE GRUPOS</h1>
                <p class="adm-page-subtitle">Grupos de permisos</p>
                <p class="adm-page-note mt-3 text-sm">
                    Los grupos no se eliminan: desactívelos para retirar sus
                    permisos. Un grupo administrador tiene acceso universal y
                    ese carácter se fija al crearlo.
                </p>
            </div>

            <p
                v-if="soloLectura"
                class="adm-alert adm-alert-warning mb-6 font-semibold"
                role="status"
            >
                Consulta de producción: solo lectura.
            </p>

            <section v-if="editando !== null" class="adm-card mb-6">
                <h2 class="adm-card-title mb-1 flex items-center gap-2">
                    <UsersRound class="size-4" aria-hidden="true" />
                    {{ esAlta ? 'Nuevo grupo' : `Editar ${form.nombre}` }}
                </h2>
                <p class="adm-muted mb-4 text-xs">
                    Los campos marcados con
                    <span class="adm-requerido">*</span>
                    son obligatorios.
                </p>
                <form novalidate @submit.prevent="guardar">
                    <div class="mb-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="min-w-0">
                            <label for="nombre" class="adm-label"
                                >Nombre<span
                                    class="adm-requerido"
                                    aria-hidden="true"
                                    >&nbsp;*</span
                                ></label
                            >
                            <input
                                id="nombre"
                                v-model="form.nombre"
                                name="nombre"
                                class="adm-input"
                                maxlength="100"
                                autocomplete="off"
                                required
                                aria-required="true"
                                :disabled="procesando"
                                :aria-invalid="
                                    form.errors.nombre ? 'true' : undefined
                                "
                                :aria-describedby="
                                    form.errors.nombre
                                        ? 'nombre-error'
                                        : undefined
                                "
                            />
                            <span
                                v-if="form.errors.nombre"
                                id="nombre-error"
                                class="adm-field-error mt-1"
                                >{{ form.errors.nombre }}</span
                            >
                        </div>
                        <div class="min-w-0">
                            <label for="descripcion" class="adm-label"
                                >Descripción</label
                            >
                            <input
                                id="descripcion"
                                v-model="form.descripcion"
                                name="descripcion"
                                class="adm-input"
                                maxlength="300"
                                autocomplete="off"
                                :disabled="procesando"
                                :aria-invalid="
                                    form.errors.descripcion ? 'true' : undefined
                                "
                                :aria-describedby="
                                    form.errors.descripcion
                                        ? 'descripcion-error'
                                        : undefined
                                "
                            />
                            <span
                                v-if="form.errors.descripcion"
                                id="descripcion-error"
                                class="adm-field-error mt-1"
                                >{{ form.errors.descripcion }}</span
                            >
                        </div>
                        <template v-if="esAlta">
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="form.es_administrador"
                                    type="checkbox"
                                    name="es_administrador"
                                    class="adm-checkbox"
                                    :disabled="procesando"
                                />
                                Grupo administrador (acceso universal; no se
                                puede cambiar después)
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="form.activo"
                                    type="checkbox"
                                    name="activo"
                                    class="adm-checkbox"
                                    :disabled="procesando"
                                />
                                Grupo activo
                            </label>
                        </template>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2">
                        <button
                            type="button"
                            class="adm-btn adm-btn-neutral"
                            :disabled="procesando"
                            @click="cerrar"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="adm-btn adm-btn-primary"
                            :disabled="procesando"
                            :aria-busy="procesando ? 'true' : undefined"
                        >
                            {{ esAlta ? 'Crear grupo' : 'Guardar cambios' }}
                        </button>
                    </div>
                </form>
            </section>

            <section class="adm-card">
                <div
                    class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="w-full min-w-0 sm:max-w-sm">
                        <h2 class="adm-card-title">Grupos</h2>
                        <input
                            v-model="busqueda"
                            type="search"
                            placeholder="Buscar grupo por nombre o descripción..."
                            aria-label="Buscar grupos"
                            class="adm-input mb-2"
                            autocomplete="off"
                        />
                        <p class="adm-muted text-xs" aria-live="polite">
                            {{ gruposFiltrados.length }}
                            {{
                                plural(
                                    gruposFiltrados.length,
                                    'grupo encontrado',
                                    'grupos encontrados',
                                )
                            }}
                        </p>
                    </div>
                    <button
                        v-if="!soloLectura"
                        type="button"
                        class="adm-btn adm-btn-primary"
                        :disabled="procesando"
                        @click="abrir()"
                    >
                        <UsersRound class="size-4" aria-hidden="true" />
                        Nuevo grupo
                    </button>
                </div>

                <div
                    v-if="gruposFiltrados.length === 0"
                    class="adm-alert adm-alert-info"
                    role="status"
                >
                    {{
                        busqueda.trim()
                            ? `Ningún grupo coincide con «${busqueda.trim()}».`
                            : 'No hay grupos registrados.'
                    }}
                </div>
                <div v-else class="adm-table-scroll">
                    <table class="adm-table">
                        <thead>
                            <tr>
                                <th scope="col">Nombre</th>
                                <th scope="col">Descripción</th>
                                <th scope="col">Integrantes</th>
                                <th scope="col">Estado</th>
                                <th scope="col" class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="grupo in gruposFiltrados"
                                :key="grupo.id"
                                :class="{ 'is-inactive': !grupo.activo }"
                            >
                                <td class="font-medium">
                                    <span
                                        class="inline-flex items-center gap-2"
                                    >
                                        {{ grupo.nombre }}
                                        <span
                                            v-if="grupo.esAdministrador"
                                            class="adm-badge"
                                            >Administrador</span
                                        >
                                    </span>
                                </td>
                                <td>{{ grupo.descripcion || '—' }}</td>
                                <td>{{ grupo.integrantes.length }}</td>
                                <td>
                                    <span
                                        :class="[
                                            'adm-badge',
                                            grupo.activo
                                                ? 'adm-badge-success'
                                                : 'adm-badge-neutral',
                                        ]"
                                        >{{
                                            grupo.activo ? 'Activo' : 'Inactivo'
                                        }}</span
                                    >
                                </td>
                                <td class="text-right">
                                    <div class="adm-table-actions">
                                        <button
                                            type="button"
                                            class="adm-btn adm-btn-neutral"
                                            :aria-pressed="
                                                detalleId === grupo.id
                                            "
                                            :aria-label="`Ver integrantes y permisos de ${grupo.nombre}`"
                                            @click="verDetalle(grupo)"
                                        >
                                            <Eye
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            Detalle
                                        </button>
                                        <template v-if="!soloLectura">
                                            <button
                                                type="button"
                                                class="adm-btn adm-btn-neutral"
                                                :disabled="procesando"
                                                :aria-label="`Editar ${grupo.nombre}`"
                                                @click="abrir(grupo)"
                                            >
                                                <Pencil
                                                    class="size-3.5"
                                                    aria-hidden="true"
                                                />
                                                Editar
                                            </button>
                                            <button
                                                type="button"
                                                :class="[
                                                    'adm-btn',
                                                    grupo.activo
                                                        ? 'adm-btn-danger'
                                                        : 'adm-btn-secondary',
                                                ]"
                                                :disabled="procesando"
                                                :aria-label="`${grupo.activo ? 'Desactivar' : 'Activar'} ${grupo.nombre}`"
                                                @click="alternarEstado(grupo)"
                                            >
                                                <ToggleLeft
                                                    v-if="grupo.activo"
                                                    class="size-3.5"
                                                    aria-hidden="true"
                                                />
                                                <ToggleRight
                                                    v-else
                                                    class="size-3.5"
                                                    aria-hidden="true"
                                                />
                                                {{
                                                    grupo.activo
                                                        ? 'Desactivar'
                                                        : 'Activar'
                                                }}
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section
                v-if="detalle"
                id="detalle-grupo"
                class="adm-card adm-access-card mt-6"
                tabindex="-1"
                :aria-label="`Detalle de ${detalle.nombre}`"
            >
                <div class="adm-selected-header mb-6 pb-4">
                    <div class="adm-identity">
                        <span class="adm-avatar is-large" aria-hidden="true">{{
                            iniciales(detalle.nombre)
                        }}</span>
                        <div class="adm-identity-text">
                            <h2 class="adm-card-title adm-selected-title">
                                {{ detalle.nombre }}
                            </h2>
                            <p class="adm-muted text-sm">
                                {{ detalle.descripcion || 'Sin descripción' }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="grid min-w-0 grid-cols-1 gap-6 lg:grid-cols-2">
                    <div class="min-w-0">
                        <h3 class="adm-card-title flex items-center gap-2">
                            <Users class="size-4" aria-hidden="true" />
                            Integrantes ({{ detalle.integrantes.length }})
                        </h3>
                        <div
                            v-if="detalle.integrantes.length"
                            class="adm-access-list grid gap-3 sm:grid-cols-2"
                        >
                            <div
                                v-for="integrante in detalle.integrantes"
                                :key="integrante.id"
                                class="adm-member-item adm-identity"
                            >
                                <span class="adm-avatar" aria-hidden="true">{{
                                    iniciales(
                                        integrante.nombreCompleto ||
                                            integrante.usuario,
                                    )
                                }}</span>
                                <span class="adm-identity-text">
                                    <span class="adm-user-name">{{
                                        integrante.nombreCompleto ||
                                        integrante.usuario
                                    }}</span>
                                    <span class="adm-user-code"
                                        >{{ integrante.usuario
                                        }}{{
                                            integrante.activo
                                                ? ''
                                                : ' · Inactiva'
                                        }}</span
                                    >
                                </span>
                            </div>
                        </div>
                        <p v-else class="adm-muted py-6 text-center text-sm">
                            Este grupo no tiene integrantes.
                        </p>
                    </div>

                    <div class="min-w-0">
                        <h3 class="adm-card-title flex items-center gap-2">
                            <ShieldCheck class="size-4" aria-hidden="true" />
                            Permisos
                        </h3>
                        <p
                            v-if="detalle.esAdministrador"
                            class="adm-alert adm-alert-info"
                            role="status"
                        >
                            Grupo administrador: acceso universal a todas las
                            funcionalidades.
                        </p>
                        <ul
                            v-else-if="detalle.permisos.length"
                            class="adm-access-list space-y-2"
                        >
                            <li
                                v-for="permiso in detalle.permisos"
                                :key="permiso"
                                class="adm-member-item adm-group-code font-mono break-all"
                            >
                                {{ permiso }}
                            </li>
                        </ul>
                        <p v-else class="adm-muted py-6 text-center text-sm">
                            Este grupo no tiene permisos asignados.
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
