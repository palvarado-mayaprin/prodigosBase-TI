<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Pencil,
    UserCheck,
    UserCog,
    UserPlus,
    UserX,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import {
    cerrarCarga,
    confirmarAccion,
    mostrarCarga,
    mostrarError,
    mostrarExito,
} from '@/lib/alertas';
import { DESCRIPCION_POLITICA } from '@/lib/politicaContrasena';
import { plural } from '@/lib/textos';
import { dashboard } from '@/routes';
import { usuarios as rutaUsuarios } from '@/routes/admin';
import { actualizar, crear, estado } from '@/routes/admin/usuarios';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Administración de Usuarios', href: rutaUsuarios() },
        ],
    },
});

type Usuario = {
    id: number;
    usuario: string;
    nombre: string;
    apellidos: string;
    nombreCompleto: string;
    email: string | null;
    telefono: string | null;
    activo: boolean;
    debeCambiarPassword: boolean;
};

const props = defineProps<{
    usuarios: Usuario[];
    actorId: number;
}>();

// Búsqueda local: cada palabra debe aparecer en el usuario, el nombre o el correo.
const busqueda = ref('');
const usuariosFiltrados = computed(() => {
    const palabras = busqueda.value.trim().toLowerCase().split(/\s+/);
    return props.usuarios.filter((usuario) => {
        const texto =
            `${usuario.usuario} ${usuario.nombreCompleto} ${usuario.email ?? ''}`.toLowerCase();
        return palabras.every((palabra) => texto.includes(palabra));
    });
});

// Ordenamiento local. Primer clic en una columna: descendente; los
// siguientes alternan. Sin orden: el del servidor (por usuario).
type Columna = 'usuario' | 'nombreCompleto' | 'email' | 'estado';
const columnas: { clave: Columna; titulo: string }[] = [
    { clave: 'usuario', titulo: 'Usuario' },
    { clave: 'nombreCompleto', titulo: 'Nombre completo' },
    { clave: 'email', titulo: 'Correo' },
    { clave: 'estado', titulo: 'Estado' },
];
const orden = ref<{ columna: Columna; ascendente: boolean } | null>(null);

function ordenarPor(columna: Columna) {
    orden.value =
        orden.value?.columna === columna
            ? { columna, ascendente: !orden.value.ascendente }
            : { columna, ascendente: false };
}

const comparador = new Intl.Collator('es', {
    sensitivity: 'base',
    numeric: true,
});

function valor(usuario: Usuario, columna: Columna): string {
    if (columna === 'estado') return usuario.activo ? '1' : '0';
    return (usuario[columna] ?? '').trim();
}

const usuariosOrdenados = computed(() => {
    const actual = orden.value;
    if (!actual) return usuariosFiltrados.value;
    const sentido = actual.ascendente ? 1 : -1;
    return [...usuariosFiltrados.value].sort((a, b) => {
        const va = valor(a, actual.columna);
        const vb = valor(b, actual.columna);
        // Los vacíos siempre al final; empate por usuario.
        if (!va !== !vb) return va ? -1 : 1;
        return (
            sentido * comparador.compare(va, vb) ||
            comparador.compare(a.usuario, b.usuario)
        );
    });
});

function ariaSort(columna: Columna) {
    if (orden.value?.columna !== columna) return 'none';
    return orden.value.ascendente ? 'ascending' : 'descending';
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
    apellidos: '',
    usuario: '',
    email: '',
    telefono: '',
    password: '',
    password_confirmation: '',
    activo: true,
};
const form = useForm({ ...vacio });

async function abrir(usuario?: Usuario) {
    form.defaults(
        usuario
            ? {
                  nombre: usuario.nombre,
                  apellidos: usuario.apellidos,
                  usuario: usuario.usuario,
                  email: usuario.email ?? '',
                  telefono: usuario.telefono ?? '',
                  password: '',
                  password_confirmation: '',
                  activo: usuario.activo,
              }
            : { ...vacio },
    );
    form.reset();
    form.clearErrors();
    editando.value = usuario?.id ?? 0;
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
                // El usuario no se edita y el estado tiene su propia acción.
                form.transform((datos) => {
                    const { usuario: _u, activo: _a, ...resto } = datos;
                    void _u;
                    void _a;
                    return resto;
                }).put(actualizar(id).url, opciones);
            }
        },
        {
            titulo: alta ? 'Usuario creado' : 'Usuario actualizado',
            descripcion: 'Los cambios se guardaron correctamente.',
        },
        () => ({
            titulo: 'No se pudo guardar',
            descripcion: 'Revise los campos marcados e intente de nuevo.',
        }),
        cerrar,
    );
}

async function alternarEstado(usuario: Usuario) {
    if (procesando.value) return;
    const habilitar = !usuario.activo;
    const confirmado = await confirmarAccion({
        titulo: habilitar ? 'Habilitar usuario' : 'Deshabilitar usuario',
        descripcion: habilitar
            ? `¿Habilitar a ${usuario.usuario}? Podrá iniciar sesión de nuevo.`
            : `¿Deshabilitar a ${usuario.usuario}? No podrá iniciar sesión.`,
        confirmar: habilitar ? 'Habilitar' : 'Deshabilitar',
    });
    if (!confirmado || procesando.value) return;

    void enviar(
        (opciones) =>
            router.put(estado(usuario.id).url, { activo: habilitar }, opciones),
        {
            titulo: habilitar ? 'Usuario habilitado' : 'Usuario deshabilitado',
            descripcion: `${usuario.usuario} se actualizó correctamente.`,
        },
        (errores) => ({
            titulo: 'No se pudo cambiar el estado',
            descripcion: errores.activo ?? 'Intente de nuevo más tarde.',
        }),
    );
}

const esPropio = (usuario: Usuario) => usuario.id === props.actorId;

type CampoTexto = 'nombre' | 'apellidos' | 'email' | 'telefono';
type Campo = {
    clave: CampoTexto;
    etiqueta: string;
    requerido: boolean;
    tipo?: 'email' | 'tel';
    max: number;
    autocomplete: string;
};
const secciones: { titulo: string; campos: Campo[] }[] = [
    {
        titulo: 'Datos personales',
        campos: [
            {
                clave: 'nombre',
                etiqueta: 'Nombre',
                requerido: true,
                max: 100,
                autocomplete: 'off',
            },
            {
                clave: 'apellidos',
                etiqueta: 'Apellidos',
                requerido: true,
                max: 100,
                autocomplete: 'off',
            },
        ],
    },
    {
        titulo: 'Contacto',
        campos: [
            {
                clave: 'email',
                etiqueta: 'Correo',
                requerido: false,
                tipo: 'email',
                max: 255,
                autocomplete: 'off',
            },
            {
                clave: 'telefono',
                etiqueta: 'Teléfono',
                requerido: false,
                tipo: 'tel',
                max: 30,
                autocomplete: 'off',
            },
        ],
    },
];
</script>

<template>
    <Head title="Administración de Usuarios" />

    <div class="adm-main min-h-full">
        <div class="w-full min-w-0">
            <div class="mb-6">
                <h1 class="adm-page-title">ADMINISTRACIÓN DE USUARIOS</h1>
                <p class="adm-page-subtitle">Cuentas de usuario</p>
                <p class="adm-page-note mt-3 text-sm">
                    Los usuarios no se eliminan: deshabilite la cuenta para
                    impedir el inicio de sesión. Una contraseña asignada aquí
                    obliga a cambiarla al iniciar sesión.
                </p>
            </div>

            <section v-if="editando !== null" class="adm-card mb-6">
                <h2 class="adm-card-title mb-1 flex items-center gap-2">
                    <UserCog class="size-4" aria-hidden="true" />
                    {{ esAlta ? 'Nuevo usuario' : `Editar ${form.usuario}` }}
                </h2>
                <p class="adm-muted mb-4 text-xs">
                    Los campos marcados con
                    <span class="adm-requerido">*</span>
                    son obligatorios.
                </p>
                <form novalidate @submit.prevent="guardar">
                    <fieldset
                        v-for="seccion in secciones"
                        :key="seccion.titulo"
                        class="mb-5"
                    >
                        <legend
                            class="adm-label mb-2 text-xs tracking-wide uppercase"
                        >
                            {{ seccion.titulo }}
                        </legend>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div
                                v-for="campo in seccion.campos"
                                :key="campo.clave"
                                class="min-w-0"
                            >
                                <label :for="campo.clave" class="adm-label"
                                    >{{ campo.etiqueta
                                    }}<span
                                        v-if="campo.requerido"
                                        class="adm-requerido"
                                        aria-hidden="true"
                                        >&nbsp;*</span
                                    ></label
                                >
                                <input
                                    :id="campo.clave"
                                    v-model="form[campo.clave]"
                                    :name="campo.clave"
                                    :type="campo.tipo ?? 'text'"
                                    class="adm-input"
                                    :maxlength="campo.max"
                                    :autocomplete="campo.autocomplete"
                                    :required="campo.requerido"
                                    :aria-required="
                                        campo.requerido ? 'true' : undefined
                                    "
                                    :disabled="procesando"
                                    :aria-invalid="
                                        form.errors[campo.clave]
                                            ? 'true'
                                            : undefined
                                    "
                                    :aria-describedby="
                                        form.errors[campo.clave]
                                            ? `${campo.clave}-error`
                                            : undefined
                                    "
                                />
                                <span
                                    v-if="form.errors[campo.clave]"
                                    :id="`${campo.clave}-error`"
                                    class="adm-field-error mt-1"
                                    >{{ form.errors[campo.clave] }}</span
                                >
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="mb-5">
                        <legend
                            class="adm-label mb-2 text-xs tracking-wide uppercase"
                        >
                            Acceso
                        </legend>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div class="min-w-0">
                                <label for="usuario" class="adm-label"
                                    >Usuario<span
                                        v-if="esAlta"
                                        class="adm-requerido"
                                        aria-hidden="true"
                                        >&nbsp;*</span
                                    ></label
                                >
                                <input
                                    id="usuario"
                                    v-model="form.usuario"
                                    name="usuario"
                                    class="adm-input"
                                    maxlength="50"
                                    autocomplete="off"
                                    :required="esAlta"
                                    :aria-required="esAlta ? 'true' : undefined"
                                    :readonly="!esAlta"
                                    :disabled="procesando"
                                    :aria-invalid="
                                        form.errors.usuario ? 'true' : undefined
                                    "
                                    :aria-describedby="
                                        form.errors.usuario
                                            ? 'usuario-error'
                                            : 'usuario-ayuda'
                                    "
                                />
                                <span
                                    v-if="form.errors.usuario"
                                    id="usuario-error"
                                    class="adm-field-error mt-1"
                                    >{{ form.errors.usuario }}</span
                                >
                                <span
                                    v-else
                                    id="usuario-ayuda"
                                    class="adm-muted mt-1 block text-xs"
                                    >{{
                                        esAlta
                                            ? 'Letras, números, punto, guion y guion bajo. No se puede cambiar después.'
                                            : 'Solo lectura: identifica el inicio de sesión.'
                                    }}</span
                                >
                            </div>

                            <div class="min-w-0">
                                <label for="password" class="adm-label"
                                    >Contraseña<span
                                        v-if="esAlta"
                                        class="adm-requerido"
                                        aria-hidden="true"
                                        >&nbsp;*</span
                                    ></label
                                >
                                <input
                                    id="password"
                                    v-model="form.password"
                                    name="password"
                                    type="password"
                                    class="adm-input"
                                    maxlength="72"
                                    autocomplete="new-password"
                                    :required="esAlta"
                                    :aria-required="esAlta ? 'true' : undefined"
                                    :disabled="procesando"
                                    :aria-invalid="
                                        form.errors.password
                                            ? 'true'
                                            : undefined
                                    "
                                    :aria-describedby="
                                        form.errors.password
                                            ? 'password-error'
                                            : esAlta
                                              ? undefined
                                              : 'password-ayuda'
                                    "
                                />
                                <span
                                    v-if="form.errors.password"
                                    id="password-error"
                                    class="adm-field-error mt-1"
                                    >{{ form.errors.password }}</span
                                >
                                <span
                                    v-else-if="!esAlta"
                                    id="password-ayuda"
                                    class="adm-muted mt-1 block text-xs"
                                    >Opcional: déjela vacía para conservar la
                                    actual.</span
                                >
                            </div>

                            <div class="min-w-0">
                                <label
                                    for="password_confirmation"
                                    class="adm-label"
                                    >Confirmar contraseña<span
                                        v-if="esAlta"
                                        class="adm-requerido"
                                        aria-hidden="true"
                                        >&nbsp;*</span
                                    ></label
                                >
                                <input
                                    id="password_confirmation"
                                    v-model="form.password_confirmation"
                                    name="password_confirmation"
                                    type="password"
                                    class="adm-input"
                                    maxlength="72"
                                    autocomplete="new-password"
                                    :required="esAlta || form.password !== ''"
                                    :aria-required="
                                        esAlta || form.password !== ''
                                            ? 'true'
                                            : undefined
                                    "
                                    :disabled="procesando"
                                    aria-describedby="password-politica"
                                />
                                <span
                                    id="password-politica"
                                    class="adm-muted mt-1 block text-xs"
                                    >{{ DESCRIPCION_POLITICA }}</span
                                >
                            </div>

                            <label
                                v-if="esAlta"
                                class="flex items-center gap-2 text-sm"
                            >
                                <input
                                    v-model="form.activo"
                                    type="checkbox"
                                    name="activo"
                                    class="adm-checkbox"
                                    :disabled="procesando"
                                />
                                Usuario activo
                            </label>
                        </div>
                    </fieldset>

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
                            {{ esAlta ? 'Crear usuario' : 'Guardar cambios' }}
                        </button>
                    </div>
                </form>
            </section>

            <section class="adm-card">
                <div
                    class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="w-full min-w-0 sm:max-w-sm">
                        <h2 class="adm-card-title">Usuarios</h2>
                        <input
                            v-model="busqueda"
                            type="search"
                            placeholder="Buscar usuario, nombre o correo..."
                            aria-label="Buscar usuarios"
                            class="adm-input mb-2"
                            autocomplete="off"
                        />
                        <p class="adm-muted text-xs" aria-live="polite">
                            {{ usuariosFiltrados.length }}
                            {{
                                plural(
                                    usuariosFiltrados.length,
                                    'usuario encontrado',
                                    'usuarios encontrados',
                                )
                            }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="adm-btn adm-btn-primary"
                        :disabled="procesando"
                        @click="abrir()"
                    >
                        <UserPlus class="size-4" aria-hidden="true" />
                        Nuevo usuario
                    </button>
                </div>

                <div
                    v-if="usuariosOrdenados.length === 0"
                    class="adm-alert adm-alert-info"
                    role="status"
                >
                    {{
                        busqueda.trim()
                            ? `Ningún usuario coincide con «${busqueda.trim()}». Revise el texto o busque por usuario, nombre o correo.`
                            : 'No hay usuarios registrados.'
                    }}
                </div>
                <div v-else class="adm-table-scroll">
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
                                        @click="ordenarPor(columna.clave)"
                                    >
                                        {{ columna.titulo }}
                                        <ArrowDown
                                            v-if="
                                                orden?.columna ===
                                                    columna.clave &&
                                                !orden.ascendente
                                            "
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        <ArrowUp
                                            v-else-if="
                                                orden?.columna === columna.clave
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
                                <th scope="col" class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="usuario in usuariosOrdenados"
                                :key="usuario.id"
                                :class="{ 'is-inactive': !usuario.activo }"
                            >
                                <td class="font-medium">
                                    {{ usuario.usuario }}
                                </td>
                                <td>{{ usuario.nombreCompleto || '—' }}</td>
                                <td>{{ usuario.email || '—' }}</td>
                                <td>
                                    <span class="inline-flex flex-wrap gap-1">
                                        <span
                                            :class="[
                                                'adm-badge',
                                                usuario.activo
                                                    ? 'adm-badge-success'
                                                    : 'adm-badge-neutral',
                                            ]"
                                            >{{
                                                usuario.activo
                                                    ? 'Activo'
                                                    : 'Inactivo'
                                            }}</span
                                        >
                                        <span
                                            v-if="usuario.debeCambiarPassword"
                                            class="adm-badge adm-badge-warning"
                                            >Cambio de contraseña
                                            pendiente</span
                                        >
                                    </span>
                                </td>
                                <td class="text-right">
                                    <div class="adm-table-actions">
                                        <button
                                            type="button"
                                            class="adm-btn adm-btn-neutral"
                                            :disabled="procesando"
                                            :aria-label="`Editar ${usuario.usuario}`"
                                            @click="abrir(usuario)"
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
                                                usuario.activo
                                                    ? 'adm-btn-danger'
                                                    : 'adm-btn-secondary',
                                            ]"
                                            :disabled="
                                                procesando ||
                                                (usuario.activo &&
                                                    esPropio(usuario))
                                            "
                                            :aria-label="`${usuario.activo ? 'Deshabilitar' : 'Habilitar'} ${usuario.usuario}`"
                                            :title="
                                                usuario.activo &&
                                                esPropio(usuario)
                                                    ? 'No puede deshabilitar su propia cuenta'
                                                    : undefined
                                            "
                                            @click="alternarEstado(usuario)"
                                        >
                                            <UserX
                                                v-if="usuario.activo"
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            <UserCheck
                                                v-else
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {{
                                                usuario.activo
                                                    ? 'Deshabilitar'
                                                    : 'Habilitar'
                                            }}
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
