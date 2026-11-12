<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Eye, EyeOff, KeyRound, UserRound, X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import {
    cerrarCarga,
    mostrarCarga,
    mostrarError,
    mostrarExito,
} from '@/lib/alertas';
import { requisitosContrasena } from '@/lib/politicaContrasena';
import { dashboard, miPerfil } from '@/routes';
import { actualizar, contrasena } from '@/routes/mi-perfil';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi perfil', href: miPerfil() },
        ],
    },
});

const props = defineProps<{
    perfil: {
        usuario: string;
        nombreCompleto: string;
        email: string | null;
        telefono: string | null;
    };
}>();

// Un solo envío a la vez (datos o contraseña), con el indicador bloqueante.
const procesando = ref(false);

async function enviarFormulario(
    ejecutar: (opciones: {
        preserveScroll: true;
        onSuccess: () => void;
        onFinish: () => void;
    }) => void,
    carga: string,
    exito: { titulo: string; descripcion: string },
    error: { titulo: string; descripcion: () => string },
    alGuardar?: () => void,
) {
    procesando.value = true;
    let correcto = false;
    await mostrarCarga(carga);
    ejecutar({
        preserveScroll: true,
        onSuccess: () => {
            correcto = true;
            alGuardar?.();
        },
        onFinish: () => {
            void cerrarCarga().then(() => {
                procesando.value = false;
                void (correcto
                    ? mostrarExito(exito)
                    : mostrarError({
                          titulo: error.titulo,
                          descripcion: error.descripcion(),
                      }));
            });
        },
    });
}

// Datos de contacto: lo único que la propia cuenta puede editar.
const contacto = useForm({
    email: props.perfil.email ?? '',
    telefono: props.perfil.telefono ?? '',
});

function guardarContacto() {
    if (procesando.value || !contacto.isDirty) return;
    void enviarFormulario(
        (opciones) => contacto.put(actualizar().url, opciones),
        'Guardando datos...',
        {
            titulo: 'Datos actualizados',
            descripcion: 'Sus datos de contacto se guardaron correctamente.',
        },
        {
            titulo: 'No se pudieron guardar los datos',
            descripcion: () =>
                contacto.hasErrors
                    ? 'Revise los campos marcados e intente de nuevo.'
                    : 'Intente de nuevo más tarde.',
        },
        () => contacto.defaults(),
    );
}

const form = useForm({
    contrasena_actual: '',
    password: '',
    password_confirmation: '',
});
type Clave = 'contrasena_actual' | 'password' | 'password_confirmation';

const campos: { clave: Clave; etiqueta: string; autocomplete: string }[] = [
    {
        clave: 'contrasena_actual',
        etiqueta: 'Contraseña actual',
        autocomplete: 'current-password',
    },
    {
        clave: 'password',
        etiqueta: 'Nueva contraseña',
        autocomplete: 'new-password',
    },
    {
        clave: 'password_confirmation',
        etiqueta: 'Confirmar nueva contraseña',
        autocomplete: 'new-password',
    },
];
const visibles = reactive<Record<Clave, boolean>>({
    contrasena_actual: false,
    password: false,
    password_confirmation: false,
});

const requisitos = computed(() => requisitosContrasena(form.password));
const completo = computed(() =>
    campos.every(({ clave }) => form[clave] !== ''),
);

function describir(clave: Clave): string | undefined {
    const ids = [
        form.errors[clave] ? `${clave}-error` : '',
        clave === 'password' ? 'politica-contrasena' : '',
    ].filter(Boolean);
    return ids.length ? ids.join(' ') : undefined;
}

function cambiarContrasena() {
    if (procesando.value || !completo.value) return;
    void enviarFormulario(
        (opciones) => form.put(contrasena().url, opciones),
        'Actualizando contraseña...',
        {
            titulo: 'Contraseña actualizada',
            descripcion:
                'Use la nueva contraseña en su próximo inicio de sesión.',
        },
        {
            titulo: 'No se pudo actualizar la contraseña',
            descripcion: () =>
                form.hasErrors
                    ? 'Revise los campos marcados e intente de nuevo.'
                    : 'Intente de nuevo más tarde.',
        },
        () => {
            form.reset();
            form.clearErrors();
            visibles.contrasena_actual = false;
            visibles.password = false;
            visibles.password_confirmation = false;
        },
    );
}
</script>

<template>
    <Head title="Mi perfil" />

    <div class="adm-main min-h-full">
        <div class="w-full min-w-0">
            <div class="mb-6">
                <h1 class="adm-page-title">MI PERFIL</h1>
                <p class="adm-page-subtitle">Información de su cuenta</p>
                <p class="adm-page-note mt-3 text-sm">
                    Actualice sus datos de contacto y su contraseña. Para
                    corregir su nombre o su usuario, contacte al administrador
                    del sistema.
                </p>
            </div>

            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
                <section class="adm-card" aria-labelledby="titulo-datos">
                    <h2
                        id="titulo-datos"
                        class="adm-card-title flex items-center gap-2"
                    >
                        <UserRound class="size-4" aria-hidden="true" />
                        Datos personales
                    </h2>
                    <dl class="mb-5 grid gap-4">
                        <div>
                            <dt class="adm-label">Usuario</dt>
                            <dd class="text-sm font-medium break-words">
                                {{ perfil.usuario }}
                            </dd>
                        </div>
                        <div>
                            <dt class="adm-label">Nombre completo</dt>
                            <dd class="text-sm font-medium break-words">
                                {{ perfil.nombreCompleto }}
                            </dd>
                        </div>
                    </dl>

                    <form
                        novalidate
                        class="grid gap-4"
                        @submit.prevent="guardarContacto"
                    >
                        <div class="min-w-0">
                            <label for="email" class="adm-label"
                                >Correo electrónico</label
                            >
                            <input
                                id="email"
                                v-model="contacto.email"
                                name="email"
                                type="email"
                                class="adm-input"
                                maxlength="255"
                                autocomplete="email"
                                :disabled="procesando"
                                :aria-invalid="
                                    contacto.errors.email ? 'true' : undefined
                                "
                                :aria-describedby="
                                    contacto.errors.email
                                        ? 'email-error'
                                        : undefined
                                "
                            />
                            <span
                                v-if="contacto.errors.email"
                                id="email-error"
                                class="adm-field-error mt-1"
                                >{{ contacto.errors.email }}</span
                            >
                        </div>
                        <div class="min-w-0">
                            <label for="telefono" class="adm-label"
                                >Teléfono</label
                            >
                            <input
                                id="telefono"
                                v-model="contacto.telefono"
                                name="telefono"
                                type="tel"
                                class="adm-input"
                                maxlength="30"
                                autocomplete="tel"
                                :disabled="procesando"
                                :aria-invalid="
                                    contacto.errors.telefono
                                        ? 'true'
                                        : undefined
                                "
                                :aria-describedby="
                                    contacto.errors.telefono
                                        ? 'telefono-error'
                                        : undefined
                                "
                            />
                            <span
                                v-if="contacto.errors.telefono"
                                id="telefono-error"
                                class="adm-field-error mt-1"
                                >{{ contacto.errors.telefono }}</span
                            >
                        </div>
                        <div class="flex justify-end">
                            <button
                                type="submit"
                                class="adm-btn adm-btn-secondary"
                                :disabled="procesando || !contacto.isDirty"
                                :aria-busy="procesando ? 'true' : undefined"
                            >
                                Guardar datos
                            </button>
                        </div>
                    </form>
                </section>

                <section
                    class="adm-card lg:col-span-2"
                    aria-labelledby="titulo-contrasena"
                >
                    <h2
                        id="titulo-contrasena"
                        class="adm-card-title mb-1 flex items-center gap-2"
                    >
                        <KeyRound class="size-4" aria-hidden="true" />
                        Cambiar contraseña
                    </h2>
                    <p class="adm-muted mb-4 text-xs">
                        Los campos marcados con
                        <span class="adm-requerido">*</span>
                        son obligatorios.
                    </p>
                    <form
                        novalidate
                        class="grid grid-cols-1 items-start gap-4 md:grid-cols-2"
                        @submit.prevent="cambiarContrasena"
                    >
                        <div
                            v-for="campo in campos"
                            :key="campo.clave"
                            class="min-w-0"
                            :class="{
                                'md:col-span-2 md:w-1/2 md:pr-2':
                                    campo.clave === 'contrasena_actual',
                            }"
                        >
                            <label :for="campo.clave" class="adm-label"
                                >{{ campo.etiqueta
                                }}<span class="adm-requerido" aria-hidden="true"
                                    >&nbsp;*</span
                                ></label
                            >
                            <div class="relative">
                                <input
                                    :id="campo.clave"
                                    v-model="form[campo.clave]"
                                    :name="campo.clave"
                                    :type="
                                        visibles[campo.clave]
                                            ? 'text'
                                            : 'password'
                                    "
                                    class="adm-input pr-10"
                                    maxlength="72"
                                    :autocomplete="campo.autocomplete"
                                    required
                                    aria-required="true"
                                    :disabled="procesando"
                                    :aria-invalid="
                                        form.errors[campo.clave]
                                            ? 'true'
                                            : undefined
                                    "
                                    :aria-describedby="describir(campo.clave)"
                                    @input="form.clearErrors(campo.clave)"
                                />
                                <button
                                    type="button"
                                    class="adm-icon-btn absolute inset-y-0 right-0 flex items-center rounded-r-md px-3"
                                    :aria-label="`Mostrar ${campo.etiqueta.toLowerCase()}`"
                                    :aria-pressed="visibles[campo.clave]"
                                    :aria-controls="campo.clave"
                                    :disabled="procesando"
                                    @click="
                                        visibles[campo.clave] =
                                            !visibles[campo.clave]
                                    "
                                >
                                    <EyeOff
                                        v-if="visibles[campo.clave]"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    <Eye
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </button>
                            </div>
                            <span
                                v-if="form.errors[campo.clave]"
                                :id="`${campo.clave}-error`"
                                class="adm-field-error mt-1"
                                >{{ form.errors[campo.clave] }}</span
                            >
                            <ul
                                v-if="campo.clave === 'password'"
                                id="politica-contrasena"
                                class="mt-2 grid gap-1 text-xs"
                            >
                                <li
                                    v-for="requisito in requisitos"
                                    :key="requisito.texto"
                                    class="flex items-start gap-1.5"
                                    :class="
                                        requisito.cumple
                                            ? 'adm-requisito-cumplido'
                                            : 'adm-muted'
                                    "
                                >
                                    <Check
                                        v-if="requisito.cumple"
                                        class="mt-px size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <X
                                        v-else
                                        class="mt-px size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span
                                        >{{ requisito.texto
                                        }}<span class="sr-only">{{
                                            requisito.cumple
                                                ? ': cumplido'
                                                : ': pendiente'
                                        }}</span></span
                                    >
                                </li>
                            </ul>
                        </div>

                        <div class="flex justify-end md:col-span-2">
                            <button
                                type="submit"
                                class="adm-btn adm-btn-primary"
                                :disabled="procesando || !completo"
                                :aria-busy="procesando ? 'true' : undefined"
                            >
                                Actualizar contraseña
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</template>
