/**
 * Pruebas de lógica pura de helpers de fecha (sin dependencia del mock).
 */
import { describe, expect, it } from 'vitest';
import { fmtF, fmtL, shiftISO, toISO, parseISO } from '../fechas';

describe('fechas — helpers puros', () => {
  it('fmtL formatea una fecha ISO como "D de mes de AAAA"', () => {
    expect(fmtL('2026-07-08')).toBe('8 de julio de 2026');
  });

  it('fmtF formatea una fecha ISO como "D de mes" (sin año)', () => {
    expect(fmtF('2026-01-15')).toBe('15 de enero');
  });

  it('shiftISO desplaza N días respetando el cambio de mes', () => {
    expect(shiftISO('2026-01-31', 1)).toBe('2026-02-01');
    expect(shiftISO('2026-03-05', -10)).toBe('2026-02-23');
  });

  it('toISO/parseISO son inversas para una fecha dada', () => {
    expect(toISO(parseISO('2026-12-25'))).toBe('2026-12-25');
  });
});
