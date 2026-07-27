import type { Acuerdo, Resumen } from './types';
import { EST } from '../components/EstadoHelpers';
import { fmtL } from './fechas';

/** Encabezados/orden de columnas del listado exportado (estable). */
export const COLUMNAS_ACUERDO = [
  'ID', 'Reunión', 'Fecha reunión', 'Área', 'Tema', 'Acción',
  'Responsable', 'Corresponsables', 'Fecha compromiso', 'Estado',
  'Enlaces', 'Observaciones', 'Concluido por', 'Concluido el',
] as const;

const fFecha = (iso: string | null): string => (iso ? fmtL(iso.slice(0, 10)) : '');

/** Una fila (todo string) por acuerdo, en el orden de COLUMNAS_ACUERDO. */
export function filaAcuerdo(a: Acuerdo): string[] {
  return [
    String(a.id),
    a.reunion.nombre,
    fFecha(a.reunion.fecha),
    a.area.nombre,
    a.tema ?? '',
    a.accion,
    a.responsable.nombre,
    a.corresponsables.map((c) => c.nombre).join(', '),
    fFecha(a.fecha_compromiso),
    EST[a.estado].label,
    a.enlaces.join('\n'),
    a.observaciones ?? '',
    a.concluido_por?.nombre ?? '',
    fFecha(a.concluido_at),
  ];
}

/** Totales + tabla por responsable del resumen, como filas de texto. */
export function filasResumen(r: Resumen): { totales: string[][]; porResponsable: string[][] } {
  return {
    totales: [
      ['En proceso', String(r.en_proceso)],
      ['Vencidos', String(r.vencidos)],
      ['Por vencer (≤7 días)', String(r.por_vencer_7d)],
      ['Concluidos', String(r.concluidos)],
    ],
    porResponsable: r.por_responsable.map((p) => [
      p.responsable.nombre, String(p.en_proceso), String(p.vencidos), String(p.por_vencer_7d),
    ]),
  };
}

/** "acuerdos_2026-07-27.xlsx" */
export function nombreArchivo(base: string, hoyISO: string, ext: 'xlsx' | 'pdf'): string {
  return `${base}_${hoyISO}.${ext}`;
}
