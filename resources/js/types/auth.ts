// Datos del usuario autenticado que comparte HandleInertiaRequests
// (nunca el modelo completo de adm_usuarios).
export type User = {
    id: number;
    usuario: string;
    name: string;
    email: string | null;
    avatar?: string;
};

export type Auth = {
    user: User;
};
