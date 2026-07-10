/**
 * Mapa estado→presentación (máquina de estados v2: SOLO 3 estados) y
 * helpers de vista portados del demo (`vm()`), más utilidades de error de API.
 */
import type { EstadoAcuerdo, Rol } from '../lib';
import { diasDesdeHoy } from '../lib/fechas';

export type BadgeVariant = 'brand' | 'success' | 'error' | 'neutral';

export interface EstadoMeta {
  label: string;
  variant: BadgeVariant;
  color: string;
  dot: string;
}

export const EST: Record<EstadoAcuerdo, EstadoMeta> = {
  en_proceso: { label: 'En proceso', variant: 'brand', color: '#53155a', dot: '#8b3093' },
  vencido: { label: 'Vencido', variant: 'error', color: '#c0392b', dot: '#c0392b' },
  concluido: { label: 'Concluido', variant: 'success', color: '#2e7d50', dot: '#2e7d50' },
};

export const ROL_LABEL: Record<Rol, string> = {
  direccion: 'Dirección',
  coordinador: 'Coordinación',
  responsable: 'Responsable',
  pendiente: 'Pendiente de aprobación',
};

/** "Vence hoy / mañana / en N días / Venció hace N días" + color (portado de vm()). */
export function vencimientoRelativo(
  fechaISO: string,
  estado: EstadoAcuerdo = 'en_proceso',
): { rel: string; color: string } {
  if (estado === 'concluido') return { rel: 'Entregado', color: '#2e7d50' };
  const dd = diasDesdeHoy(fechaISO);
  if (dd < 0) {
    return { rel: dd === -1 ? 'Venció ayer' : `Venció hace ${-dd} días`, color: '#c0392b' };
  }
  if (dd === 0) return { rel: 'Vence hoy', color: '#b45309' };
  if (dd === 1) return { rel: 'Vence mañana', color: '#b45309' };
  return { rel: `Vence en ${dd} días`, color: '#737373' };
}

export function iniciales(nombre: string): string {
  return nombre
    .split(' ')
    .map((w) => w[0] ?? '')
    .join('')
    .slice(0, 2)
    .toUpperCase();
}

export function nombreCorto(nombre: string): string {
  return nombre.split(' ')[0] ?? nombre;
}

export function truncar(texto: string, max: number): string {
  return texto.length > max ? `${texto.slice(0, max)}…` : texto;
}

// ── Errores del contrato ({error, mensaje, status, campos?}) ──
interface ErrorApi {
  error?: string;
  mensaje?: string;
  status?: number;
  campos?: Record<string, string>;
}

function comoErrorApi(e: unknown): ErrorApi | null {
  return typeof e === 'object' && e !== null ? (e as ErrorApi) : null;
}

export function mensajeError(e: unknown): string {
  const err = comoErrorApi(e);
  if (err?.mensaje) return err.mensaje;
  if (e instanceof Error) return e.message;
  return 'Ocurrió un error inesperado.';
}

export function camposError(e: unknown): Record<string, string> {
  return comoErrorApi(e)?.campos ?? {};
}

export function statusError(e: unknown): number | null {
  return comoErrorApi(e)?.status ?? null;
}
