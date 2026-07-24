import { describe, expect, it } from 'vitest';
import type { Acuerdo } from '../types';
import { filtrarAcuerdos } from '../filtrosPanel';

function mk(over: {
  id: number; areaId: number; respId: number; respNombre: string;
  tema?: string; accion?: string; fecha: string;
}): Acuerdo {
  return {
    id: over.id,
    area: { id: over.areaId, nombre: `Área ${over.areaId}` },
    responsable: { id: over.respId, nombre: over.respNombre, email: '', avatar_color: null },
    tema: over.tema ?? null,
    accion: over.accion ?? 'Acción',
    fecha_compromiso: over.fecha,
  } as unknown as Acuerdo;
}

const SIN_FILTRO = { area: 0, responsable: 0, q: '', desde: '', hasta: '' };

const base: Acuerdo[] = [
  mk({ id: 1, areaId: 1, respId: 10, respNombre: 'Ana',   tema: 'Presupuesto', fecha: '2026-07-05' }),
  mk({ id: 2, areaId: 2, respId: 11, respNombre: 'Beto',  accion: 'Revisar obra', fecha: '2026-07-15' }),
  mk({ id: 3, areaId: 1, respId: 11, respNombre: 'Beto',  tema: 'Informe', fecha: '2026-07-25' }),
];

describe('filtrarAcuerdos', () => {
  it('sin criterios devuelve todos', () => {
    expect(filtrarAcuerdos(base, SIN_FILTRO).map((a) => a.id)).toEqual([1, 2, 3]);
  });

  it('filtra por área', () => {
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, area: 1 }).map((a) => a.id)).toEqual([1, 3]);
  });

  it('filtra por responsable', () => {
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, responsable: 11 }).map((a) => a.id)).toEqual([2, 3]);
  });

  it('filtra por texto en tema, acción o responsable (case-insensitive)', () => {
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, q: 'obra' }).map((a) => a.id)).toEqual([2]);
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, q: 'ANA' }).map((a) => a.id)).toEqual([1]);
  });

  it('filtra por rango de fechas, inclusivo en ambos extremos', () => {
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, desde: '2026-07-15', hasta: '2026-07-25' }).map((a) => a.id)).toEqual([2, 3]);
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, desde: '2026-07-15' }).map((a) => a.id)).toEqual([2, 3]);
    expect(filtrarAcuerdos(base, { ...SIN_FILTRO, hasta: '2026-07-15' }).map((a) => a.id)).toEqual([1, 2]);
  });

  it('combina área + rango + responsable + texto', () => {
    const r = filtrarAcuerdos(base, { area: 1, responsable: 11, q: 'informe', desde: '2026-07-20', hasta: '' });
    expect(r.map((a) => a.id)).toEqual([3]);
  });
});
