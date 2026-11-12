// Pruebas de la edición de asignaciones (membresías y permisos).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    alternar,
    detalleCambios,
    diferencia,
    iniciales,
} from '../../resources/js/lib/seleccion.ts';

void test('calcula agregados, retirados y cambios pendientes', () => {
    assert.deepEqual(diferencia([1, 2], [2, 3]), {
        agregados: [3],
        retirados: [1],
        hayCambios: true,
    });
    // El orden no cuenta como cambio.
    assert.equal(diferencia([1, 2], [2, 1]).hayCambios, false);
});

void test('alternar no duplica ni pierde selecciones', () => {
    assert.deepEqual(alternar([1], 1, true), [1]);
    assert.deepEqual(alternar([1, 2], 1, false), [2]);
    assert.deepEqual(alternar([], 4, true), [4]);
});

void test('describe los cambios para la confirmación', () => {
    const texto = detalleCambios(diferencia([1], [2]), (id) => `Grupo ${id}`);
    assert.equal(texto, 'Agregar:\n• Grupo 2\n\nRetirar:\n• Grupo 1');
    assert.equal(detalleCambios(diferencia([], []), String), '');
});

void test('iniciales de nombres', () => {
    assert.equal(iniciales('ana maría lópez'), 'AM');
    assert.equal(iniciales('  '), '?');
});
