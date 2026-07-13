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
  if (tipo === 'asignacion') return 'Asignación del acuerdo';
  return 'Resumen periódico';
}

export interface ChipEnvio {
  label: string;
  className: string;
}

/** Chip Enviado/Programado/Fallido (paleta Cívica Nocturna vía .chip-envio--*). */
export function chipEnvio(estado: 'programado' | 'enviado' | 'fallido'): ChipEnvio {
  if (estado === 'enviado') return { label: 'Enviado', className: 'chip-envio chip-envio--enviado' };
  if (estado === 'fallido') return { label: 'Fallido', className: 'chip-envio chip-envio--fallido' };
  return { label: 'Programado', className: 'chip-envio chip-envio--programado' };
}
