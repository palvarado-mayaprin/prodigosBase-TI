// Pruebas de la orientación visual de la política de contraseñas.
// Ejecución: npm run test:js (node --test, TypeScript nativo de Node 24).
// Los casos coinciden con las reglas de Password::defaults() del servidor.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { requisitosContrasena } from '../../resources/js/lib/politicaContrasena.ts';

const cumple = (clave: string) =>
    requisitosContrasena(clave).map((requisito) => requisito.cumple);

void test('una contraseña válida cumple todos los requisitos', () => {
    assert.deepEqual(cumple('Nueva-Clave-Segura-26'), [true, true, true, true]);
});

void test('detecta cada requisito pendiente', () => {
    assert.deepEqual(cumple('Corta-1'), [false, true, true, true]);
    assert.deepEqual(cumple('sinmayusculas-2026'), [true, false, true, true]);
    assert.deepEqual(cumple('SinNumeros-Aqui'), [true, true, false, true]);
    assert.deepEqual(cumple('SinSimbolos2026'), [true, true, true, false]);
});

void test('cuenta caracteres, no bytes, y respeta el máximo de 72', () => {
    assert.equal(cumple('Ñandú-Clave-2026')[0], true);
    assert.equal(cumple('A1-' + 'a'.repeat(70))[0], false);
});
