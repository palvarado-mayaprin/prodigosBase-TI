// Navegación dinámica: el servidor entrega en la prop compartida
// `navegacion` el catálogo de funcionalidades filtrado por los permisos de la
// cuenta (App\Navegacion\Navegacion). Aquí no hay listas de módulos: solo se
// presenta lo recibido. Ocultar una opción no protege nada; cada ruta exige
// su permiso en el servidor.

export type FuncionalidadNav = {
    codigo: string;
    nombre: string;
    descripcion: string | null;
    icono: string | null;
    ruta: string;
    href: string;
};

export type ModuloNav = {
    modulo: string;
    nombre: string;
    funcionalidades: FuncionalidadNav[];
};

export type AreaNav = {
    area: string;
    nombre: string;
    modulos: ModuloNav[];
};

export type Navegacion = {
    areas: AreaNav[];
    verificada: boolean;
};

const normalizar = (texto: string) =>
    texto
        .toLocaleLowerCase('es')
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .trim();

// Filtra el menú por texto (sin distinguir mayúsculas ni tildes). Si coincide
// el área o el módulo se conserva completo; se omiten los grupos vacíos.
export function filtrarNavegacion(areas: AreaNav[], texto: string): AreaNav[] {
    const buscado = normalizar(texto);
    if (!buscado) return areas;
    const coincide = (valor: string) => normalizar(valor).includes(buscado);
    return areas
        .map((area) => {
            if (coincide(area.nombre)) return area;
            const modulos = area.modulos
                .map((modulo) =>
                    coincide(modulo.nombre)
                        ? modulo
                        : {
                              ...modulo,
                              funcionalidades: modulo.funcionalidades.filter(
                                  (f) => coincide(f.nombre),
                              ),
                          },
                )
                .filter((modulo) => modulo.funcionalidades.length > 0);
            return { ...area, modulos };
        })
        .filter((area) => area.modulos.length > 0);
}

export function contarFuncionalidades(areas: AreaNav[]): number {
    return areas.reduce(
        (total, area) =>
            total +
            area.modulos.reduce((n, m) => n + m.funcionalidades.length, 0),
        0,
    );
}
