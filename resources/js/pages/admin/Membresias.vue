<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { UserRound } from '@lucide/vue';
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
import { membresias as rutaMembresias } from '@/routes/admin';
import { guardar as rutaGuardar } from '@/routes/admin/membresias';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Usuarios y grupos', href: rutaMembresias() },
        ],
    },
});

type Usuario = {
    id: number;
    usuario: string;
    nombreCompleto: string;
    activo: boolean;
};

type Grupo = {
    id: number;
    nombre: string;
    esAdministrador: boolean;
    activo: boolean;
};

type Asignacion = { usuarioId: number; grupoId: number };

const props = defineProps<{
    usuarios: Usuario[];
    grupos: Grupo[];
    asignaciones: Asignacion[];
    actorId: number;
    soloLectura: boolean;
}>();

const vista = ref<'usuarios-grupos' | 'grupos-usuarios'>('usuarios-grupos');
const busqueda = ref('');
const busquedaGrupos = ref('');
const busquedaUsuariosDelGrupo = ref('');
const busquedaGruposDisponibles = ref('');
const filtroGruposDisponibles = ref<'todos' | 'asignados' | 'no-asignados'>(
    'todos',
);
const usuarioSeleccionado = ref<number | null>(null);
const grupoSeleccionado = ref<number | null>(null);
const base = ref<number[]>([]);
const seleccionados = ref<number[]>([]);
const idsSeleccionados = computed(() => new Set(seleccionados.value));
const procesando = ref(false);

const coincide = (texto: string, valores: string[]) =>
    valores.join(' ').toLowerCase().includes(texto.trim().toLowerCase());
const nombre = (usuario: Usuario) => usuario.nombreCompleto || usuario.usuario;

const usuariosFiltrados = computed(() =>
    props.usuarios.filter((usuario) =>
        coincide(busqueda.value, [usuario.nombreCompleto, usuario.usuario]),
    ),
);

const gruposFiltrados = computed(() =>
    props.grupos.filter((grupo) =>
        coincide(busquedaGrupos.value, [grupo.nombre]),
    ),
);

const gruposDisponiblesFiltrados = computed(() =>
    props.grupos.filter((grupo) => {
        const asignado = idsSeleccionados.value.has(grupo.id);
        return (
            coincide(busquedaGruposDisponibles.value, [grupo.nombre]) &&
            (filtroGruposDisponibles.value === 'todos' ||
                (filtroGruposDisponibles.value === 'asignados'
                    ? asignado
                    : !asignado))
        );
    }),
);

const usuarioActual = computed(
    () =>
        props.usuarios.find(
            (usuario) => usuario.id === usuarioSeleccionado.value,
        ) ?? null,
);

const grupoActual = computed(
    () =>
        props.grupos.find((grupo) => grupo.id === grupoSeleccionado.value) ??
        null,
);

const usuariosDelGrupo = computed(() => {
    const grupo = grupoActual.value;
    if (!grupo) return [];
    const ids = new Set(
        props.asignaciones
            .filter((asignacion) => asignacion.grupoId === grupo.id)
            .map((asignacion) => asignacion.usuarioId),
    );
    return props.usuarios.filter((usuario) => ids.has(usuario.id));
});

const usuariosDelGrupoFiltrados = computed(() =>
    usuariosDelGrupo.value.filter((usuario) =>
        coincide(busquedaUsuariosDelGrupo.value, [
            usuario.nombreCompleto,
            usuario.usuario,
        ]),
    ),
);

function gruposDe(usuarioId: number): number[] {
    return props.asignaciones
        .filter((asignacion) => asignacion.usuarioId === usuarioId)
        .map((asignacion) => asignacion.grupoId)
        .sort((a, b) => a - b);
}

const cambios = computed(() => diferencia(base.value, seleccionados.value));

function etiqueta(id: number): string {
    return (
        props.grupos.find((grupo) => grupo.id === id)?.nombre ?? `Grupo ${id}`
    );
}

// Un grupo inactivo solo admite retirar a quien ya pertenece.
function bloqueado(grupo: Grupo): boolean {
    return (
        procesando.value ||
        props.soloLectura ||
        (!grupo.activo && !idsSeleccionados.value.has(grupo.id))
    );
}

async function descartarPendientes(titulo: string): Promise<boolean> {
    return (
        !cambios.value.hayCambios ||
        confirmarAccion({
            titulo,
            descripcion: 'Hay cambios sin guardar. ¿Desea descartarlos?',
            confirmar: 'Descartar cambios',
        })
    );
}

async function seleccionarUsuario(usuario: Usuario) {
    if (procesando.value || usuarioSeleccionado.value === usuario.id) return;
    if (!(await descartarPendientes('Descartar cambios pendientes'))) return;
    usuarioSeleccionado.value = usuario.id;
    busquedaGruposDisponibles.value = '';
    filtroGruposDisponibles.value = 'todos';
    base.value = gruposDe(usuario.id);
    seleccionados.value = [...base.value];
}

async function cambiarVista(destino: typeof vista.value) {
    if (vista.value === destino || procesando.value) return;
    if (!(await descartarPendientes('Cambiar de modalidad'))) return;
    seleccionados.value = [...base.value];
    vista.value = destino;
}

function seleccionarGrupo(grupo: Grupo) {
    if (grupoSeleccionado.value === grupo.id) return;
    grupoSeleccionado.value = grupo.id;
    busquedaUsuariosDelGrupo.value = '';
}

function cancelar() {
    seleccionados.value = [...base.value];
}

function sincronizarConServidor() {
    base.value = usuarioActual.value ? gruposDe(usuarioActual.value.id) : [];
    seleccionados.value = [...base.value];
}

async function recargar() {
    if (procesando.value) return;
    if (
        cambios.value.hayCambios &&
        !(await confirmarAccion({
            titulo: 'Recargar asignaciones',
            descripcion:
                'Se descartarán los cambios locales. ¿Desea continuar?',
            confirmar: 'Recargar',
        }))
    )
        return;
    procesando.value = true;
    router.reload({
        only: ['usuarios', 'grupos', 'asignaciones'],
        onSuccess: async () => {
            await nextTick();
            sincronizarConServidor();
            void mostrarExito({
                titulo: 'Asignaciones actualizadas',
                descripcion: 'Se cargaron las asignaciones vigentes.',
            });
        },
        onError: () => {
            void mostrarError({
                titulo: 'No se pudieron recargar',
                descripcion: 'No se pudieron recargar las asignaciones.',
            });
        },
        onFinish: () => {
            procesando.value = false;
        },
    });
}

async function guardar() {
    const usuario = usuarioActual.value;
    if (
        props.soloLectura ||
        !usuario ||
        !cambios.value.hayCambios ||
        procesando.value
    )
        return;

    if (
        !(await confirmarAccion({
            titulo: 'Guardar cambios',
            descripcion: `Confirme los cambios de ${nombre(usuario)}:\n\n${detalleCambios(cambios.value, etiqueta)}`,
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
        rutaGuardar(usuario.id).url,
        { base: base.value, grupos: seleccionados.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: async () => {
                await nextTick();
                sincronizarConServidor();
                resultado = {
                    tipo: 'exito',
                    titulo: 'Cambios guardados',
                    descripcion: 'Las asignaciones se guardaron correctamente.',
                };
            },
            onError: (errores) => {
                // Se conserva la selección para corregir o recargar.
                resultado = errores.conflicto
                    ? {
                          tipo: 'advertencia',
                          titulo: 'Asignaciones modificadas',
                          descripcion: errores.conflicto,
                      }
                    : {
                          tipo: 'error',
                          titulo: 'No se pudieron guardar los cambios',
                          descripcion:
                              errores.grupos ??
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

function advertirSalida(event: BeforeUnloadEvent) {
    if (!cambios.value.hayCambios) return;
    event.preventDefault();
}

onMounted(() => window.addEventListener('beforeunload', advertirSalida));
onUnmounted(() => window.removeEventListener('beforeunload', advertirSalida));
</script>

<template>
    <Head title="Usuarios y grupos" />

    <div class="adm-main min-h-full">
        <div class="w-full min-w-0">
            <div class="mb-6">
                <h1 class="adm-page-title">ADMINISTRACIÓN DE ACCESOS</h1>
                <p class="adm-page-subtitle">Usuarios y grupos de permisos</p>
                <p
                    v-if="!soloLectura && vista === 'usuarios-grupos'"
                    class="adm-page-note mt-3 text-sm"
                >
                    Los cambios se aplican al guardar y requieren confirmación.
                </p>
            </div>

            <p
                v-if="soloLectura"
                class="adm-alert adm-alert-warning mb-6 font-semibold"
                role="status"
            >
                Modo consulta: las membresías solo las modifica un administrador
                con escritura habilitada.
            </p>

            <div
                class="adm-view-switch mb-6"
                role="group"
                aria-label="Modalidad de administración de accesos"
            >
                <button
                    type="button"
                    :disabled="procesando"
                    :aria-pressed="vista === 'usuarios-grupos'"
                    :class="[
                        'adm-view-switch-button',
                        vista === 'usuarios-grupos' ? 'is-active' : '',
                    ]"
                    @click="cambiarVista('usuarios-grupos')"
                >
                    Usuarios → Grupos
                </button>
                <button
                    type="button"
                    :disabled="procesando"
                    :aria-pressed="vista === 'grupos-usuarios'"
                    :class="[
                        'adm-view-switch-button',
                        vista === 'grupos-usuarios' ? 'is-active' : '',
                    ]"
                    @click="cambiarVista('grupos-usuarios')"
                >
                    Grupos → Usuarios
                </button>
            </div>

            <div
                v-if="vista === 'usuarios-grupos'"
                class="grid min-w-0 grid-cols-1 gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,7fr)]"
            >
                <section class="adm-card adm-access-card">
                    <h2 class="adm-card-title">Usuarios</h2>
                    <input
                        v-model="busqueda"
                        type="search"
                        placeholder="Buscar usuario..."
                        aria-label="Buscar usuarios"
                        class="adm-input mb-4"
                    />
                    <p class="adm-muted mb-3 text-xs" aria-live="polite">
                        {{ usuariosFiltrados.length }}
                        {{
                            plural(
                                usuariosFiltrados.length,
                                'usuario encontrado',
                                'usuarios encontrados',
                            )
                        }}
                    </p>
                    <div class="adm-access-list space-y-2">
                        <button
                            v-for="usuario in usuariosFiltrados"
                            :key="usuario.id"
                            type="button"
                            :disabled="procesando"
                            :aria-pressed="usuarioSeleccionado === usuario.id"
                            :class="[
                                'adm-user-item',
                                usuarioSeleccionado === usuario.id
                                    ? 'is-selected'
                                    : '',
                            ]"
                            @click="seleccionarUsuario(usuario)"
                        >
                            <span class="adm-identity">
                                <span class="adm-avatar" aria-hidden="true">{{
                                    iniciales(nombre(usuario))
                                }}</span>
                                <span class="adm-identity-text">
                                    <span class="adm-user-name">{{
                                        nombre(usuario)
                                    }}</span>
                                    <span class="adm-user-code"
                                        >{{ usuario.usuario
                                        }}{{
                                            usuario.activo ? '' : ' · Inactiva'
                                        }}</span
                                    >
                                </span>
                            </span>
                        </button>
                        <p
                            v-if="usuariosFiltrados.length === 0"
                            class="adm-muted py-6 text-center text-sm"
                        >
                            No se encontraron usuarios.
                        </p>
                    </div>
                </section>

                <section class="adm-card adm-access-card">
                    <template v-if="usuarioActual">
                        <div class="adm-selected-header mb-6 pb-4">
                            <div class="adm-identity">
                                <span
                                    class="adm-avatar is-large"
                                    aria-hidden="true"
                                    >{{
                                        iniciales(nombre(usuarioActual))
                                    }}</span
                                >
                                <div class="adm-identity-text">
                                    <h2
                                        class="adm-card-title adm-selected-title"
                                    >
                                        {{ nombre(usuarioActual) }}
                                    </h2>
                                    <p class="adm-muted text-sm">
                                        {{ usuarioActual.usuario }}
                                    </p>
                                </div>
                            </div>
                            <p class="adm-count-tag mt-3">
                                Grupos asignados:
                                <strong>{{ seleccionados.length }}</strong>
                            </p>
                        </div>
                        <h3 class="adm-card-title">
                            Grupos de permisos disponibles
                        </h3>
                        <div
                            class="mb-4 grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto]"
                        >
                            <input
                                v-model="busquedaGruposDisponibles"
                                type="search"
                                placeholder="Buscar grupo por nombre..."
                                aria-label="Buscar grupos disponibles por nombre"
                                class="adm-input min-w-0"
                            />
                            <select
                                v-model="filtroGruposDisponibles"
                                aria-label="Filtrar grupos por asignación"
                                class="adm-input adm-group-filter"
                            >
                                <option value="todos">Todos</option>
                                <option value="asignados">Asignados</option>
                                <option value="no-asignados">
                                    No asignados
                                </option>
                            </select>
                        </div>
                        <div
                            class="adm-access-groups grid gap-3 sm:grid-cols-2"
                        >
                            <label
                                v-for="grupo in gruposDisponiblesFiltrados"
                                :key="grupo.id"
                                :class="[
                                    'adm-group-option',
                                    grupo.esAdministrador ? 'is-admin' : '',
                                ]"
                            >
                                <input
                                    type="checkbox"
                                    class="adm-checkbox h-4 w-4"
                                    :checked="idsSeleccionados.has(grupo.id)"
                                    :disabled="bloqueado(grupo)"
                                    @change="
                                        seleccionados = alternar(
                                            seleccionados,
                                            grupo.id,
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />
                                <span>
                                    <span class="adm-group-name">{{
                                        grupo.nombre
                                    }}</span>
                                    <span class="adm-group-code"
                                        >{{
                                            grupo.esAdministrador
                                                ? 'Grupo administrador · acceso universal'
                                                : 'Grupo de permisos'
                                        }}{{
                                            grupo.activo ? '' : ' · Inactivo'
                                        }}</span
                                    >
                                </span>
                            </label>
                            <p
                                v-if="gruposDisponiblesFiltrados.length === 0"
                                class="adm-muted py-6 text-center text-sm sm:col-span-2"
                            >
                                No se encontraron grupos.
                            </p>
                        </div>
                        <div
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
                        <div class="mt-6 flex flex-wrap gap-3">
                            <template v-if="!soloLectura">
                                <button
                                    type="button"
                                    class="adm-btn adm-btn-primary"
                                    :disabled="
                                        !cambios.hayCambios || procesando
                                    "
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
                                    :disabled="
                                        !cambios.hayCambios || procesando
                                    "
                                    @click="cancelar"
                                >
                                    Cancelar cambios
                                </button>
                            </template>
                            <button
                                type="button"
                                class="adm-btn adm-btn-neutral"
                                :disabled="procesando"
                                @click="recargar"
                            >
                                Recargar asignaciones
                            </button>
                        </div>
                    </template>
                    <div
                        v-else
                        class="adm-muted flex min-h-[350px] flex-col items-center justify-center text-center"
                    >
                        <UserRound class="mb-4 size-12" aria-hidden="true" />
                        <h2 class="text-lg font-semibold">
                            Seleccione un usuario
                        </h2>
                        <p class="mt-2 text-sm">
                            {{
                                soloLectura
                                    ? 'Podrá consultar sus grupos de permisos.'
                                    : 'Podrá consultar y editar sus grupos de permisos.'
                            }}
                        </p>
                    </div>
                </section>
            </div>

            <div
                v-else
                class="grid min-w-0 grid-cols-1 gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,7fr)]"
            >
                <section class="adm-card adm-access-card">
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
                            :aria-pressed="grupoSeleccionado === grupo.id"
                            :class="[
                                'adm-user-item',
                                grupoSeleccionado === grupo.id
                                    ? 'is-selected'
                                    : '',
                            ]"
                            @click="seleccionarGrupo(grupo)"
                        >
                            <span class="adm-user-name">{{
                                grupo.nombre
                            }}</span>
                            <span class="adm-user-code"
                                >{{
                                    grupo.esAdministrador
                                        ? 'Grupo administrador'
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
                        <div class="adm-selected-header mb-6 pb-4">
                            <div class="adm-identity">
                                <span
                                    class="adm-avatar is-large"
                                    aria-hidden="true"
                                    >{{ iniciales(grupoActual.nombre) }}</span
                                >
                                <div class="adm-identity-text">
                                    <h2
                                        class="adm-card-title adm-selected-title"
                                    >
                                        {{ grupoActual.nombre }}
                                    </h2>
                                    <p class="adm-muted text-sm">
                                        {{
                                            grupoActual.esAdministrador
                                                ? 'Grupo administrador · acceso universal'
                                                : 'Grupo de permisos'
                                        }}
                                    </p>
                                </div>
                            </div>
                            <p class="adm-count-tag mt-3">
                                {{ usuariosDelGrupo.length }}
                                {{
                                    plural(
                                        usuariosDelGrupo.length,
                                        'integrante',
                                        'integrantes',
                                    )
                                }}
                            </p>
                        </div>
                        <input
                            v-model="busquedaUsuariosDelGrupo"
                            type="search"
                            placeholder="Buscar integrante por nombre o usuario..."
                            aria-label="Buscar integrantes del grupo por nombre o usuario"
                            class="adm-input mb-3"
                        />
                        <p class="adm-muted mb-3 text-xs" aria-live="polite">
                            {{ usuariosDelGrupoFiltrados.length }}
                            {{
                                plural(
                                    usuariosDelGrupoFiltrados.length,
                                    'resultado',
                                    'resultados',
                                )
                            }}
                        </p>
                        <div
                            v-if="usuariosDelGrupo.length"
                            class="adm-access-groups grid gap-3 sm:grid-cols-2"
                        >
                            <div
                                v-for="usuario in usuariosDelGrupoFiltrados"
                                :key="usuario.id"
                                class="adm-member-item adm-identity"
                            >
                                <span class="adm-avatar" aria-hidden="true">{{
                                    iniciales(nombre(usuario))
                                }}</span>
                                <span class="adm-identity-text">
                                    <span class="adm-user-name">{{
                                        nombre(usuario)
                                    }}</span>
                                    <span class="adm-user-code"
                                        >{{ usuario.usuario
                                        }}{{
                                            usuario.activo ? '' : ' · Inactiva'
                                        }}</span
                                    >
                                </span>
                            </div>
                            <p
                                v-if="usuariosDelGrupoFiltrados.length === 0"
                                class="adm-muted py-12 text-center text-sm sm:col-span-2"
                            >
                                No se encontraron usuarios.
                            </p>
                        </div>
                        <p v-else class="adm-muted py-12 text-center text-sm">
                            Este grupo no tiene integrantes.
                        </p>
                        <p
                            v-if="!soloLectura"
                            class="adm-page-note mt-4 text-xs"
                        >
                            Para agregar o retirar integrantes use la modalidad
                            Usuarios → Grupos.
                        </p>
                    </template>
                    <div
                        v-else
                        class="adm-muted flex min-h-[350px] flex-col items-center justify-center text-center"
                    >
                        <h2 class="text-lg font-semibold">
                            Seleccione un grupo
                        </h2>
                        <p class="mt-2 text-sm">
                            Podrá consultar sus integrantes.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
