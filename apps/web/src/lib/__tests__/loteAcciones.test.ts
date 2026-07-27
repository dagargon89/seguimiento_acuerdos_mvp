import { describe, expect, it, vi } from 'vitest';
import { clasificarError, ejecutarLote, notaReprogramacion, resumenLote } from '../loteAcciones';

describe('notaReprogramacion', () => {
  it('compone la nota con la fecha en formato largo', () => {
    expect(notaReprogramacion('2026-08-15')).toBe('Reprogramación en lote al 15 de agosto de 2026');
  });
});

describe('clasificarError', () => {
  it('403 → sin_permiso; otro → error', () => {
    expect(clasificarError({ status: 403, error: 'prohibido' })).toBe('sin_permiso');
    expect(clasificarError({ status: 500 })).toBe('error');
    expect(clasificarError(new Error('x'))).toBe('error');
  });
});

describe('resumenLote', () => {
  it('cuenta ok/total y separa los fallidos', () => {
    const r = resumenLote([
      { id: 1, ok: true },
      { id: 2, ok: false, motivo: 'sin_permiso' },
      { id: 3, ok: true },
    ]);
    expect(r).toEqual({
      ok: 2,
      total: 3,
      fallidos: [{ id: 2, ok: false, motivo: 'sin_permiso' }],
    });
  });
});

describe('ejecutarLote', () => {
  it('aplica la acción a cada id y preserva el orden de ids', async () => {
    const res = await ejecutarLote([3, 1, 2], async () => undefined);
    expect(res.map((r) => r.id)).toEqual([3, 1, 2]);
    expect(res.every((r) => r.ok)).toBe(true);
  });

  it('un rechazo no aborta el resto; clasifica el fallo por id', async () => {
    const accion = vi.fn(async (id: number) => {
      if (id === 2) throw { status: 403 };
      return undefined;
    });
    const res = await ejecutarLote([1, 2, 3], accion, { concurrencia: 1 });
    expect(accion).toHaveBeenCalledTimes(3);
    expect(res).toEqual([
      { id: 1, ok: true },
      { id: 2, ok: false, motivo: 'sin_permiso' },
      { id: 3, ok: true },
    ]);
  });

  it('respeta el límite de concurrencia', async () => {
    let enVuelo = 0;
    let maxEnVuelo = 0;
    const liberar: Array<() => void> = [];
    const accion = (_id: number) =>
      new Promise<void>((resolve) => {
        enVuelo += 1;
        maxEnVuelo = Math.max(maxEnVuelo, enVuelo);
        liberar.push(() => {
          enVuelo -= 1;
          resolve();
        });
      });
    const p = ejecutarLote([1, 2, 3, 4, 5], accion, { concurrencia: 2 });
    // Deja que arranquen los primeros workers antes de liberar.
    await Promise.resolve();
    await Promise.resolve();
    while (liberar.length) liberar.shift()!();
    await p;
    expect(maxEnVuelo).toBeLessThanOrEqual(2);
  });
});
