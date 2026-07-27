import { fmtL } from './fechas';
import { statusError } from '../components/EstadoHelpers';

export type MotivoFallo = 'sin_permiso' | 'error';
export interface ResultadoItem {
  id: number;
  ok: boolean;
  motivo?: MotivoFallo;
}

/** "Reprogramación en lote al 15 de agosto de 2026" */
export function notaReprogramacion(fechaISO: string): string {
  return `Reprogramación en lote al ${fmtL(fechaISO)}`;
}

/** 403 → sin permiso; cualquier otro error → error genérico. */
export function clasificarError(e: unknown): MotivoFallo {
  return statusError(e) === 403 ? 'sin_permiso' : 'error';
}

export function resumenLote(resultados: ResultadoItem[]): {
  ok: number;
  total: number;
  fallidos: ResultadoItem[];
} {
  const fallidos = resultados.filter((r) => !r.ok);
  return { ok: resultados.length - fallidos.length, total: resultados.length, fallidos };
}

/**
 * Ejecuta `accion` por cada id con concurrencia acotada. Un rechazo NO aborta
 * el resto: se captura por id y se clasifica. El resultado preserva el orden de `ids`.
 */
export async function ejecutarLote(
  ids: number[],
  accion: (id: number) => Promise<unknown>,
  opts: { concurrencia?: number; clasificar?: (e: unknown) => MotivoFallo } = {},
): Promise<ResultadoItem[]> {
  const concurrencia = Math.max(1, opts.concurrencia ?? 5);
  const clasificar = opts.clasificar ?? clasificarError;
  const porId = new Map<number, ResultadoItem>();
  let cursor = 0;
  const worker = async (): Promise<void> => {
    while (cursor < ids.length) {
      const id = ids[cursor++];
      try {
        await accion(id);
        porId.set(id, { id, ok: true });
      } catch (e) {
        porId.set(id, { id, ok: false, motivo: clasificar(e) });
      }
    }
  };
  await Promise.all(
    Array.from({ length: Math.min(concurrencia, ids.length) }, () => worker()),
  );
  return ids.map((id) => porId.get(id)!);
}
