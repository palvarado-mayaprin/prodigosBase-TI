<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// Lleva el foco al contenido sin añadir #contenido a la URL.
function saltarAlContenido() {
    document.getElementById('contenido')?.focus();
}
</script>

<template>
    <AppShell variant="sidebar">
        <a
            href="#contenido"
            class="adm-saltar"
            @click.prevent="saltarAlContenido"
            >Saltar al contenido</a
        >
        <AppSidebar />
        <AppContent
            id="contenido"
            tabindex="-1"
            variant="sidebar"
            class="adm-contenido min-w-0 overflow-x-clip"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <Toaster container-aria-label="Notificaciones" />
    </AppShell>
</template>
