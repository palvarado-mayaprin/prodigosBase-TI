<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowRight, Lock, User } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';

// Inicio de sesión (Fortify): distribución y campos de PRODIGOS V2 con la
// marca neutra de AuthSplitLayout.
defineOptions({
    layout: {
        title: 'Iniciar sesión',
        description: 'Ingrese con su cuenta.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Iniciar sesión" />

    <div
        v-if="status"
        class="adm-alert adm-alert-success mb-4 text-sm font-medium"
        role="status"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-5">
            <div class="grid gap-1.5">
                <div
                    class="adm-login-field"
                    :class="{ 'is-error': errors.usuario }"
                >
                    <User class="adm-login-field-icon" aria-hidden="true" />
                    <div class="adm-login-field-control">
                        <Label for="usuario" class="adm-login-field-label">
                            Usuario
                        </Label>
                        <Input
                            id="usuario"
                            type="text"
                            name="usuario"
                            required
                            v-focus
                            autocomplete="username"
                            :aria-invalid="errors.usuario ? 'true' : undefined"
                            :aria-describedby="
                                errors.usuario ? 'usuario-error' : undefined
                            "
                            class="adm-login-field-input"
                        />
                    </div>
                </div>
                <InputError
                    id="usuario-error"
                    class="adm-login-error"
                    :message="errors.usuario"
                />
            </div>

            <div class="grid gap-1.5">
                <div
                    class="adm-login-field"
                    :class="{ 'is-error': errors.password }"
                >
                    <Lock class="adm-login-field-icon" aria-hidden="true" />
                    <div class="adm-login-field-control">
                        <Label for="password" class="adm-login-field-label">
                            Contraseña
                        </Label>
                        <PasswordInput
                            id="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            :aria-invalid="errors.password ? 'true' : undefined"
                            class="adm-login-field-input"
                        />
                    </div>
                </div>
                <InputError
                    class="adm-login-error"
                    :message="errors.password"
                />
            </div>

            <button
                type="submit"
                class="adm-btn adm-btn-primary adm-login-submit mt-2"
                :disabled="processing"
                :aria-busy="processing ? 'true' : undefined"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Aceptar
                <ArrowRight
                    v-if="!processing"
                    class="size-4"
                    aria-hidden="true"
                />
            </button>
        </div>
    </Form>
</template>
