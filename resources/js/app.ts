import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { initializeSeguimientoUso } from '@/lib/seguimientoUso';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    // Barra de progreso de Inertia con el color de acento de la paleta.
    progress: {
        color: 'var(--adm-color-acento)',
    },
});

// This will listen for flash toast data from the server...
initializeFlashToast();

// Registro de uso (Seguimiento de Uso): tiempo activo por pantalla.
initializeSeguimientoUso();
