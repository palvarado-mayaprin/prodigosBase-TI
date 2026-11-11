<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { dashboard } from '@/routes';

// Página provisional: la Etapa 5 incorpora el Dashboard con los módulos
// permitidos a cada usuario.
defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Inicio',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Inicio" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Inicio</h1>
        <p class="text-sm text-muted-foreground">
            Los módulos administrativos se incorporarán en las próximas etapas.
        </p>
    </div>
</template>
