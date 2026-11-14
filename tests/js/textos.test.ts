import assert from 'node:assert/strict';
import { test } from 'node:test';
import { plural } from '../../resources/js/lib/textos.ts';

void test('usa el singular solo para uno', () => {
    assert.equal(plural(1, 'grupo', 'grupos'), 'grupo');
    assert.equal(plural(0, 'grupo', 'grupos'), 'grupos');
    assert.equal(plural(2, 'grupo', 'grupos'), 'grupos');
});
