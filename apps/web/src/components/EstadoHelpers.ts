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
  en_proceso: { label: 'En proceso', variant: 'brand', color: 'var(--blue)', dot: 'var(--blue)' },
  vencido: { label: 'Vencido', variant: 'error', color: 'var(--red)', dot: 'var(--red)' },
  concluido: { label: 'Concluido', variant: 'success', color: 'var(--teal)', dot: 'var(--teal)' },
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
  if (estado === 'concluido') return { rel: 'Entregado', color: 'var(--teal)' };
  const dd = diasDesdeHoy(fechaISO);
  if (dd < 0) {
    return { rel: dd === -1 ? 'Venció ayer' : `Venció hace ${-dd} días`, color: 'var(--red)' };
  }
  if (dd === 0) return { rel: 'Vence hoy', color: 'var(--amber)' };
  if (dd === 1) return { rel: 'Vence mañana', color: 'var(--amber)' };
  return { rel: `Vence en ${dd} días`, color: 'var(--muted)' };
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

/** Código de error del contrato (p.ej. 'usuario_no_registrado', 'cuenta_pendiente'). */
export function codigoError(e: unknown): string | null {
  return comoErrorApi(e)?.error ?? null;
}
