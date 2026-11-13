<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Spinner } from '@/components/ui/spinner';
import { DESCRIPCION_POLITICA } from '@/lib/politicaContrasena';
import { logout } from '@/routes';
import { actualizar } from '@/routes/contrasena';

// Cambio de contraseña (obligatorio o voluntario) con el layout de acceso.
defineOptions({
    layout: {
        title: 'Cambiar contraseña',
        description: 'Defina una contraseña nueva para continuar.',
    },
});

defineProps<{
    obligatorio: boolean;
    reglas: string;
}>();
</script>

<template>
    <Head title="Cambiar contraseña" />

    <p
        v-if="obligatorio"
        class="adm-alert adm-alert-warning mb-4 text-sm"
        role="status"
    >
        Por seguridad, debe cambiar su contraseña antes de continuar.
    </p>

    <Form
        v-bind="actualizar.form()"
        :reset-on-error="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-5">
            <div class="min-w-0">
                <label for="contrasena_actual" class="adm-label"
                    >Contraseña actual</label
                >
                <PasswordInput
                    id="contrasena_actual"
                    name="contrasena_actual"
                    required
                    v-focus
                    autocomplete="current-password"
                    class="adm-input"
                    :aria-invalid="
                        errors.contrasena_actual ? 'true' : undefined
                    "
                />
                <InputError class="mt-1" :message="errors.contrasena_actual" />
            </div>

            <div class="min-w-0">
                <label for="password" class="adm-label">Contraseña nueva</label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    class="adm-input"
                    :passwordrules="reglas"
                    :aria-invalid="errors.password ? 'true' : undefined"
                    aria-describedby="reglas-contrasena"
                />
                <p id="reglas-contrasena" class="adm-muted mt-1 text-xs">
                    {{ DESCRIPCION_POLITICA }}
                </p>
                <InputError class="mt-1" :message="errors.password" />
            </div>

            <div class="min-w-0">
                <label for="password_confirmation" class="adm-label"
                    >Repita la contraseña</label
                >
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    class="adm-input"
                />
            </div>

            <button
                type="submit"
                class="adm-btn adm-btn-primary adm-login-submit mt-2"
                :disabled="processing"
                :aria-busy="processing ? 'true' : undefined"
            >
                <Spinner v-if="processing" />
                Guardar contraseña
            </button>
        </div>
    </Form>

    <Link
        :href="logout()"
        as="button"
        class="adm-btn adm-btn-neutral mt-4 w-full"
    >
        Cerrar sesión
    </Link>
</template>
