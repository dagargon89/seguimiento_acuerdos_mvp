import { describe, expect, it } from 'vitest';
import type { Acuerdo, Resumen } from '../types';
import { COLUMNAS_ACUERDO, filaAcuerdo, filasResumen, nombreArchivo } from '../exportar';

function mk(over: Partial<Acuerdo> & { id: number }): Acuerdo {
  return {
    id: over.id,
    reunion: over.reunion ?? { id: 1, nombre: 'Reunión de dirección', fecha: '2026-07-01' },
    area: over.area ?? { id: 1, nombre: 'Educación', activa: true },
    tema: over.tema ?? null,
    accion: over.accion ?? 'Acción X',
    responsable: over.responsable ?? { id: 10, nombre: 'Ana López', email: '', avatar_color: null },
    corresponsables: over.corresponsables ?? [],
    fecha_compromiso: over.fecha_compromiso ?? '2026-07-15',
    estado: over.estado ?? 'en_proceso',
    enlaces: over.enlaces ?? [],
    observaciones: over.observaciones ?? null,
    concluido_por: over.concluido_por ?? null,
    concluido_at: over.concluido_at ?? null,
  } as unknown as Acuerdo;
}

describe('filaAcuerdo', () => {
  it('tiene tantas columnas como encabezados', () => {
    expect(filaAcuerdo(mk({ id: 1 }))).toHaveLength(COLUMNAS_ACUERDO.length);
  });

  it('usa etiqueta humana del estado y formatea fechas', () => {
    const f = filaAcuerdo(mk({ id: 1, estado: 'vencido', fecha_compromiso: '2026-07-08' }));
    expect(f[COLUMNAS_ACUERDO.indexOf('Estado')]).toBe('Vencido');
    expect(f[COLUMNAS_ACUERDO.indexOf('Fecha compromiso')]).toBe('8 de julio de 2026');
  });

  it('une corresponsables y enlaces; nulos → cadena vacía', () => {
    const f = filaAcuerdo(mk({
      id: 2, tema: null, observaciones: null,
      corresponsables: [
        { id: 11, nombre: 'Beto Ruiz', email: '', avatar_color: null },
        { id: 12, nombre: 'Ceci Mora', email: '', avatar_color: null },
      ],
      enlaces: ['https://a.test/1', 'https://a.test/2'],
    }));
    expect(f[COLUMNAS_ACUERDO.indexOf('Corresponsables')]).toBe('Beto Ruiz, Ceci Mora');
    expect(f[COLUMNAS_ACUERDO.indexOf('Enlaces')]).toBe('https://a.test/1\nhttps://a.test/2');
    expect(f[COLUMNAS_ACUERDO.indexOf('Tema')]).toBe('');
    expect(f[COLUMNAS_ACUERDO.indexOf('Observaciones')]).toBe('');
  });

  it('concluido: incluye quién y cuándo (solo la fecha del datetime)', () => {
    const f = filaAcuerdo(mk({
      id: 3, estado: 'concluido',
      concluido_por: { id: 1, nombre: 'Dir Gen', email: '', avatar_color: null },
      concluido_at: '2026-07-20 10:30:00',
    }));
    expect(f[COLUMNAS_ACUERDO.indexOf('Concluido por')]).toBe('Dir Gen');
    expect(f[COLUMNAS_ACUERDO.indexOf('Concluido el')]).toBe('20 de julio de 2026');
  });
});

describe('filasResumen', () => {
  const r: Resumen = {
    ambito: 'general', area: null,
    en_proceso: 3, vencidos: 2, por_vencer_7d: 1, concluidos: 5,
    por_responsable: [
      { responsable: { id: 10, nombre: 'Ana López', email: '', avatar_color: null }, en_proceso: 2, vencidos: 1, por_vencer_7d: 0 },
    ],
  };
  it('totales cubren los 4 indicadores', () => {
    expect(filasResumen(r).totales).toEqual([
      ['En proceso', '3'], ['Vencidos', '2'], ['Por vencer (≤7 días)', '1'], ['Concluidos', '5'],
    ]);
  });
  it('una fila por responsable con sus tres conteos', () => {
    expect(filasResumen(r).porResponsable).toEqual([
      ['Ana López', '2', '1', '0'],
    ]);
  });
});

describe('nombreArchivo', () => {
  it('compone base_fecha.ext', () => {
    expect(nombreArchivo('acuerdos', '2026-07-27', 'xlsx')).toBe('acuerdos_2026-07-27.xlsx');
    expect(nombreArchivo('resumen', '2026-07-27', 'pdf')).toBe('resumen_2026-07-27.pdf');
  });
});
