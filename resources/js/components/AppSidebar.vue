<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    useSidebar,
} from '@/components/ui/sidebar';
import { icono } from '@/lib/iconos';
import { filtrarNavegacion } from '@/lib/navegacion';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

// Menú lateral: «Inicio» para toda cuenta y, debajo, las áreas del catálogo
// que la cuenta puede consultar (prop compartida `navegacion`, calculada en
// el servidor). Ocultar una opción no protege nada: cada ruta exige su
// permiso en el servidor.
const page = usePage();
const { isMobile, state, setOpenMobile } = useSidebar();
const expandido = computed(() => isMobile.value || state.value === 'expanded');

const nombreApp = computed(() => page.props.name || 'Administración');
const monograma = computed(() => nombreApp.value.trim().charAt(0) || 'A');

const inicio: NavItem[] = [
    { title: 'Inicio', href: dashboard(), icon: LayoutGrid },
];

const busqueda = ref('');
const areas = computed(() =>
    filtrarNavegacion(
        page.props.navegacion?.areas ?? [],
        expandido.value ? busqueda.value : '',
    ),
);
const grupos = computed(() =>
    areas.value.map((area) => ({
        area: area.area,
        nombre: area.nombre,
        secciones: area.modulos.map((modulo) => ({
            // Un módulo con una sola funcionalidad del mismo nombre no
            // necesita título propio.
            titulo:
                modulo.funcionalidades.length === 1 &&
                modulo.funcionalidades[0].nombre.startsWith(modulo.nombre)
                    ? undefined
                    : modulo.nombre,
            items: modulo.funcionalidades.map((funcionalidad) => ({
                title: funcionalidad.nombre,
                href: funcionalidad.href,
                icon: icono(funcionalidad.icono),
            })),
        })),
    })),
);

function cerrarEnMovil() {
    if (isMobile.value) setOpenMobile(false);
}
</script>

<template>
    <Sidebar collapsible="icon" variant="inset" class="adm-sidebar">
        <SidebarHeader class="adm-sidebar-header">
            <Link
                :href="dashboard()"
                class="adm-brand-link"
                :title="nombreApp"
                @click="cerrarEnMovil"
            >
                <span v-if="expandido" class="adm-brand-wordmark">{{
                    nombreApp
                }}</span>
                <span v-else class="adm-brand-monogram">{{ monograma }}</span>
            </Link>
            <div
                v-if="expandido && grupos.length + busqueda.length > 0"
                class="adm-menu-search"
            >
                <Search class="adm-menu-search-icon" aria-hidden="true" />
                <input
                    v-model="busqueda"
                    type="search"
                    aria-label="Buscar opciones del menú"
                    placeholder="Buscar en el menú"
                    class="adm-menu-search-input"
                />
            </div>
        </SidebarHeader>

        <SidebarContent>
            <NavMain label="General" :secciones="[{ items: inicio }]" />
            <NavMain
                v-for="grupo in grupos"
                :key="grupo.area"
                :label="grupo.nombre"
                :secciones="grupo.secciones"
            />
            <p
                v-if="expandido && busqueda.trim() && grupos.length === 0"
                class="adm-menu-empty"
                role="status"
            >
                Sin opciones coincidentes.
            </p>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
