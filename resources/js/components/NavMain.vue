<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

// Un grupo del menú lateral (un área): etiqueta y secciones (sus módulos),
// con título opcional. Los títulos de sección se ocultan con el menú
// contraído; los íconos y la ayuda emergente se conservan.
defineProps<{
    label: string;
    secciones: { titulo?: string; items: NavItem[] }[];
}>();

const { isCurrentOrParentUrl } = useCurrentUrl();
const { isMobile, setOpenMobile } = useSidebar();

function cerrarEnMovil() {
    if (isMobile.value) setOpenMobile(false);
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel class="adm-sidebar-section">{{
            label
        }}</SidebarGroupLabel>
        <template v-for="(seccion, indice) in secciones" :key="indice">
            <p
                v-if="seccion.titulo"
                class="adm-sidebar-module group-data-[collapsible=icon]:hidden"
            >
                {{ seccion.titulo }}
            </p>
            <SidebarMenu>
                <SidebarMenuItem
                    v-for="item in seccion.items"
                    :key="String(item.href)"
                >
                    <SidebarMenuButton
                        as-child
                        class="adm-nav-link"
                        :is-active="isCurrentOrParentUrl(item.href)"
                        :tooltip="item.title"
                    >
                        <Link :href="item.href" @click="cerrarEnMovil">
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </template>
    </SidebarGroup>
</template>
