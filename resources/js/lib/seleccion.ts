// Edición de asignaciones (membresías y permisos): lo que la pantalla leyó
// (base) frente a lo seleccionado. El servidor vuelve a comparar la base con
// la base de datos antes de guardar (concurrencia optimista).

export type Diferencia = {
    agregados: number[];
    retirados: number[];
    hayCambios: boolean;
};

export function diferencia(base: number[], seleccion: number[]): Diferencia {
    const enBase = new Set(base);
    const enSeleccion = new Set(seleccion);
    const agregados = [...enSeleccion].filter((id) => !enBase.has(id));
    const retirados = [...enBase].filter((id) => !enSeleccion.has(id));
    return {
        agregados,
        retirados,
        hayCambios: agregados.length > 0 || retirados.length > 0,
    };
}

export function alternar(
    seleccion: number[],
    id: number,
    marcado: boolean,
): number[] {
    const sin = seleccion.filter((actual) => actual !== id);
    return marcado ? [...sin, id] : sin;
}

// Detalle legible para la confirmación de guardado.
export function detalleCambios(
    cambios: Diferencia,
    rotulo: (id: number) => string,
): string {
    const lista = (ids: number[]) =>
        ids.map((id) => `• ${rotulo(id)}`).join('\n');
    return [
        cambios.agregados.length ? `Agregar:\n${lista(cambios.agregados)}` : '',
        cambios.retirados.length ? `Retirar:\n${lista(cambios.retirados)}` : '',
    ]
        .filter(Boolean)
        .join('\n\n');
}

export function iniciales(nombre: string): string {
    return (
        nombre
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map((parte) => parte.charAt(0).toUpperCase())
            .join('') || '?'
    );
}
