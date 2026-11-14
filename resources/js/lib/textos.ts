// Concordancia de los contadores: «1 usuario encontrado», «2 usuarios encontrados».
export function plural(
    cantidad: number,
    singular: string,
    plurales: string,
): string {
    return cantidad === 1 ? singular : plurales;
}
