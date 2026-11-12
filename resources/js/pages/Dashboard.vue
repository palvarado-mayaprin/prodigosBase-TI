<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import { icono } from '@/lib/iconos';
import { contarFuncionalidades } from '@/lib/navegacion';
import { dashboard, miPerfil } from '@/routes';

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

// Dashboard administrativo: las funcionalidades que la cuenta puede consultar,
// agrupadas por área y módulo (misma prop compartida `navegacion` que el menú
// lateral). No muestra indicadores ni datos de negocio.
const page = usePage();
const nombre = computed(() => page.props.auth.user?.name ?? '');
const nombreApp = computed(() => page.props.name || 'Administración');
const navegacion = computed(
    () => page.props.navegacion ?? { areas: [], verificada: true },
);
const sinModulos = computed(
    () => contarFuncionalidades(navegacion.value.areas) === 0,
);
</script>

<template>
    <Head title="Inicio" />

    <div class="adm-main min-h-full">
        <header class="mb-6">
            <p class="adm-eyebrow">{{ nombreApp }} · Inicio</p>
            <h1 class="adm-welcome-title">Bienvenido, {{ nombre }}</h1>
            <p class="adm-muted mt-1">Seleccione un módulo para comenzar.</p>
        </header>

        <div
            v-if="!navegacion.verificada"
            class="adm-alert adm-alert-warning"
            role="status"
        >
            No se pudieron comprobar sus permisos. Intente de nuevo más tarde.
        </div>

        <div v-else-if="sinModulos" class="flex flex-col gap-4">
            <div class="adm-alert adm-alert-info" role="status">
                Su usuario no tiene módulos administrativos asignados. Solicite
                acceso al administrador del sistema.
            </div>
            <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <li>
                    <Link :href="miPerfil()" class="adm-module-card">
                        <span class="adm-module-card-icon" aria-hidden="true">
                            <UserRound />
                        </span>
                        <span class="adm-module-card-title">Mi perfil</span>
                        <span class="adm-module-card-desc">
                            Consulte sus datos y cambie su contraseña.
                        </span>
                        <span class="adm-module-card-open" aria-hidden="true">
                            Abrir
                            <ArrowRight />
                        </span>
                    </Link>
                </li>
            </ul>
        </div>

        <div v-else class="flex flex-col gap-8">
            <section
                v-for="area in navegacion.areas"
                :key="area.area"
                :aria-labelledby="`area-${area.area}`"
            >
                <h2 :id="`area-${area.area}`" class="adm-area-title">
                    {{ area.nombre }}
                </h2>
                <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <template
                        v-for="modulo in area.modulos"
                        :key="modulo.modulo"
                    >
                        <li
                            v-for="funcionalidad in modulo.funcionalidades"
                            :key="funcionalidad.codigo"
                        >
                            <Link
                                :href="funcionalidad.href"
                                class="adm-module-card"
                            >
                                <span
                                    class="adm-module-card-icon"
                                    aria-hidden="true"
                                >
                                    <component
                                        :is="icono(funcionalidad.icono)"
                                    />
                                </span>
                                <span class="adm-module-card-title">
                                    {{ funcionalidad.nombre }}
                                </span>
                                <span class="adm-module-card-desc">
                                    {{
                                        funcionalidad.descripcion ??
                                        modulo.nombre
                                    }}
                                </span>
                                <span
                                    class="adm-module-card-open"
                                    aria-hidden="true"
                                >
                                    Abrir
                                    <ArrowRight />
                                </span>
                            </Link>
                        </li>
                    </template>
                </ul>
            </section>
        </div>
    </div>
</template>
