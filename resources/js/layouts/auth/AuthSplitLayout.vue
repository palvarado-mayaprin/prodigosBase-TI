<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Inicio de sesión y cambio de contraseña: distribución dividida
// (contenido a la izquierda, panel de marca a la derecha en escritorio y
// franja superior en móvil). La marca es neutra y configurable: APP_NAME y
// ADM_APP_DESCRIPCION; los colores salen de tokens.css.
defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage();
const nombreApp = computed(() => page.props.name || 'Administración');
const descripcion = computed(() => page.props.descripcionApp || '');
const monograma = computed(() => nombreApp.value.trim().charAt(0) || 'A');
</script>

<template>
    <main class="adm-login">
        <aside class="adm-login-brand" aria-hidden="true">
            <span class="adm-login-monogram">{{ monograma }}</span>
        </aside>

        <div class="adm-login-content">
            <div class="adm-login-inner">
                <header class="mb-8">
                    <p v-if="descripcion" class="adm-eyebrow mb-2">
                        {{ descripcion }}
                    </p>
                    <h1 class="adm-login-title">{{ nombreApp }}</h1>
                </header>

                <section class="adm-login-card" aria-labelledby="login-title">
                    <div class="mb-6">
                        <h2 id="login-title" class="adm-login-card-title">
                            {{ title }}
                        </h2>
                        <p v-if="description" class="adm-muted mt-1 text-sm">
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </section>
            </div>
        </div>
    </main>
</template>
