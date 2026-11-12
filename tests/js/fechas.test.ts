// Pruebas del formato de fechas UTC en la zona horaria configurada.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    duracion,
    fechaDia,
    fechaHora,
} from '../../resources/js/lib/fechas.ts';

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

void test('día local y duración', () => {
    assert.equal(fechaDia('2026-11-12'), '12/11/2026');
    assert.equal(duracion(9), '9 s');
    assert.equal(duracion(65), '1 min 05 s');
    assert.equal(duracion(7509), '2 h 05 min 09 s');
});
