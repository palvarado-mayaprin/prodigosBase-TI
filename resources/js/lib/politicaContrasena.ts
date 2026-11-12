// Requisitos de la política de contraseñas, solo como orientación visual.
// La validación definitiva es la del servidor (Password::defaults() en
// AppServiceProvider), con las mismas expresiones que usa Laravel.

export type Requisito = { texto: string; cumple: boolean };

export const DESCRIPCION_POLITICA =
    'De 12 a 72 caracteres, con mayúsculas, minúsculas, números y símbolos.';

export function requisitosContrasena(clave: string): Requisito[] {
    const longitud = Array.from(clave).length;

    return [
        {
            texto: 'Entre 12 y 72 caracteres',
            cumple: longitud >= 12 && longitud <= 72,
        },
        {
            texto: 'Mayúsculas y minúsculas',
            cumple: /(\p{Ll}+.*\p{Lu})|(\p{Lu}+.*\p{Ll})/u.test(clave),
        },
        { texto: 'Al menos un número', cumple: /\p{N}/u.test(clave) },
        {
            texto: 'Al menos un símbolo o signo de puntuación',
            cumple: /\p{Z}|\p{S}|\p{P}/u.test(clave),
        },
    ];
}
