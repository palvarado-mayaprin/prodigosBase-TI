import {
    ChartColumn,
    History,
    KeyRound,
    LayoutGrid,
    ShieldCheck,
    UserCog,
    Users,
    UsersRound,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';

// Iconos que el catálogo de permisos puede declarar (`icono`). Es un juego
// genérico de iconos, no una lista de módulos: una funcionalidad nueva usa
// uno de estos o se agrega aquí el que necesite; sin icono conocido se usa
// el genérico.
const ICONOS: Record<string, LucideIcon> = {
    ChartColumn,
    History,
    KeyRound,
    LayoutGrid,
    ShieldCheck,
    UserCog,
    Users,
    UsersRound,
};

export function icono(nombre: string | null): LucideIcon {
    return (nombre && ICONOS[nombre]) || LayoutGrid;
}
