/**
 * Presentación compartida de recordatorios (portada de recVm()/tipoLabel() del demo).
 */
import type { TipoRecordatorio } from '../lib';
import { parseISO } from '../lib/fechas';

/** Etiqueta del tipo; para "previo" calcula N con la fecha compromiso real. */
export function tipoRecordatorioLabel(
  tipo: TipoRecordatorio,
  programadoPara: string,
  fechaCompromiso: string | null,
): string {
  if (tipo === 'previo') {
    const n = fechaCompromiso
      ? Math.round((parseISO(fechaCompromiso).getTime() - parseISO(programadoPara).getTime()) / 864e5)
      : null;
    return n && n > 0 ? `Aviso previo (${n} ${n === 1 ? 'día' : 'días'} antes)` : 'Aviso previo';
  }
  if (tipo === 'dia') return 'Día de compromiso';
  if (tipo === 'vencido') return 'Seguimiento de vencido';
  return 'Resumen periódico';
}

export interface ChipEnvio {
  label: string;
  bg: string;
  color: string;
}

/** Chip Enviado/Programado/Fallido (colores 1:1 con el demo + fallido en rojo). */
export function chipEnvio(estado: 'programado' | 'enviado' | 'fallido'): ChipEnvio {
  if (estado === 'enviado') return { label: 'Enviado', bg: '#e8f5ee', color: '#2e7d50' };
  if (estado === 'fallido') return { label: 'Fallido', bg: '#fdecea', color: '#c0392b' };
  return { label: 'Programado', bg: '#f3e8f4', color: '#53155a' };
}
