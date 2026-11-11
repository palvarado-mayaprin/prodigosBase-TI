// Forma provisional del usuario autenticado; la Etapa 4 la ajusta al modelo
// propio de adm_usuarios.
export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};
