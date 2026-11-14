// Confirmaciones, carga y mensajes con SweetAlert2 (carga diferida). Los
// estilos están en resources/css/tema/componentes.css (adm-swal-*) y los
// colores de los iconos salen de los tokens de tokens.css.

let promesaSweetAlert: Promise<
    (typeof import('sweetalert2'))['default']
> | null = null;

function cargarSweetAlert() {
    return (promesaSweetAlert ??= Promise.all([
        import('sweetalert2'),
        import('sweetalert2/dist/sweetalert2.min.css'),
    ]).then(([modulo]) => modulo.default));
}

interface Mensaje {
    titulo: string;
    descripcion: string;
}

interface Confirmacion extends Mensaje {
    confirmar: string;
    cancelar?: string;
}

const estilo = {
    buttonsStyling: false,
    customClass: {
        container: 'adm-swal-container',
        popup: 'adm-swal-popup',
        loader: 'adm-swal-loader',
        htmlContainer: 'adm-swal-description',
        confirmButton: 'adm-swal-confirm',
        cancelButton: 'adm-swal-cancel',
    },
};

function token(nombre: string): string {
    return getComputedStyle(document.documentElement)
        .getPropertyValue(nombre)
        .trim();
}

const colores = {
    success: '--adm-exito',
    error: '--adm-peligro',
    warning: '--adm-advertencia',
} as const;

export async function confirmarAccion({
    titulo,
    descripcion,
    confirmar,
    cancelar = 'Cancelar',
}: Confirmacion): Promise<boolean> {
    const Swal = await cargarSweetAlert();
    const respuesta = await Swal.fire({
        ...estilo,
        icon: 'warning',
        iconColor: token(colores.warning),
        title: titulo,
        text: descripcion,
        showCancelButton: true,
        confirmButtonText: confirmar,
        cancelButtonText: cancelar,
        focusCancel: true,
        allowOutsideClick: false,
    });

    return respuesta.isConfirmed;
}

export async function mostrarCarga(
    titulo = 'Guardando cambios...',
): Promise<void> {
    const Swal = await cargarSweetAlert();
    await new Promise<void>((resolve) => {
        void Swal.fire({
            ...estilo,
            title: titulo,
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => {
                Swal.showLoading();
                resolve();
            },
        });
    });
}

export async function cerrarCarga(): Promise<void> {
    const Swal = await cargarSweetAlert();
    Swal.close();
}

async function mostrarMensaje(
    icon: keyof typeof colores,
    { titulo, descripcion }: Mensaje,
): Promise<void> {
    const Swal = await cargarSweetAlert();
    await Swal.fire({
        ...estilo,
        icon,
        iconColor: token(colores[icon]),
        title: titulo,
        text: descripcion,
        confirmButtonText: 'Entendido',
    });
}

export function mostrarExito(mensaje: Mensaje): Promise<void> {
    return mostrarMensaje('success', mensaje);
}

export function mostrarError(mensaje: Mensaje): Promise<void> {
    return mostrarMensaje('error', mensaje);
}

export function mostrarAdvertencia(mensaje: Mensaje): Promise<void> {
    return mostrarMensaje('warning', mensaje);
}
