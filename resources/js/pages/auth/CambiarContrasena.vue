<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { actualizar } from '@/routes/contrasena';

// Diseño provisional: la Etapa 6 aplica el diseño definitivo.
defineOptions({
    layout: {
        title: 'Cambiar contraseña',
        description: 'Defina una contraseña nueva para continuar',
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
        class="mb-4 text-center text-sm text-muted-foreground"
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
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="contrasena_actual">Contraseña actual</Label>
                <PasswordInput
                    id="contrasena_actual"
                    name="contrasena_actual"
                    required
                    v-focus
                    autocomplete="current-password"
                />
                <InputError :message="errors.contrasena_actual" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Contraseña nueva</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="new-password"
                    :passwordrules="reglas"
                    aria-describedby="reglas-contrasena"
                />
                <p id="reglas-contrasena" class="text-xs text-muted-foreground">
                    Mínimo 12 caracteres, con mayúsculas, minúsculas, números y
                    símbolos.
                </p>
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Repita la contraseña</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                />
            </div>

            <Button type="submit" class="mt-4 w-full" :disabled="processing">
                <Spinner v-if="processing" />
                Guardar contraseña
            </Button>
        </div>
    </Form>

    <Link
        :href="logout()"
        as="button"
        class="mt-4 w-full text-center text-sm text-muted-foreground underline"
    >
        Cerrar sesión
    </Link>
</template>
