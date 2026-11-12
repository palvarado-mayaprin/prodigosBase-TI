// Pruebas del filtro del menú lateral.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    contarFuncionalidades,
    filtrarNavegacion,
} from '../../resources/js/lib/navegacion.ts';
import type { AreaNav } from '../../resources/js/lib/navegacion.ts';

const f = (nombre: string) => ({
    codigo: `a.m.${nombre}`,
    nombre,
    descripcion: null,
    icono: null,
    ruta: nombre,
    href: `/${nombre}`,
});

const areas: AreaNav[] = [
    {
        area: 'administracion',
        nombre: 'Administración',
        modulos: [
            {
                modulo: 'accesos',
                nombre: 'Accesos',
                funcionalidades: [f('Usuarios'), f('Grupos')],
            },
            {
                modulo: 'auditoria',
                nombre: 'Auditoría',
                funcionalidades: [f('Auditoría de modificaciones')],
            },
        ],
    },
];

void test('sin texto devuelve todo', () => {
    assert.equal(filtrarNavegacion(areas, '  '), areas);
    assert.equal(contarFuncionalidades(areas), 3);
});

void test('filtra funcionalidades sin distinguir mayúsculas ni tildes', () => {
    const resultado = filtrarNavegacion(areas, 'GRUP');
    assert.equal(contarFuncionalidades(resultado), 1);
    assert.equal(resultado[0].modulos[0].funcionalidades[0].nombre, 'Grupos');
    assert.equal(
        contarFuncionalidades(filtrarNavegacion(areas, 'auditoria')),
        1,
    );
});

void test('un área o módulo coincidente se conserva completo', () => {
    assert.equal(contarFuncionalidades(filtrarNavegacion(areas, 'accesos')), 2);
    assert.equal(
        contarFuncionalidades(filtrarNavegacion(areas, 'administración')),
        3,
    );
    assert.deepEqual(filtrarNavegacion(areas, 'inexistente'), []);
});
