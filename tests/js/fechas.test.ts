// Pruebas del formato de fechas UTC en la zona horaria local.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fechaHora } from '../../resources/js/lib/fechas.ts';

void test('convierte UTC a la zona horaria indicada', () => {
    assert.equal(fechaHora('2026-11-12T18:05:00Z', 'UTC'), '12/11/2026, 18:05');
    assert.equal(
        fechaHora('2026-11-12T18:05:00Z', 'America/Guatemala'),
        '12/11/2026, 12:05',
    );
});

void test('valores vacíos o no válidos', () => {
    assert.equal(fechaHora(null), '—');
    assert.equal(fechaHora('no es fecha'), '—');
});
