/**
 * Helpers de fecha portados del demo vanilla (fmtF / fmtL / dias / shift).
 * Todas las fechas se anclan a mediodía local para evitar bordes de TZ.
 */

export const MESES = [
  'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
  'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
] as const;

/** Hoy a mediodía local. */
export function hoy(): Date {
  const t = new Date();
  t.setHours(12, 0, 0, 0);
  return t;
}

export function parseISO(iso: string): Date {
  const [y, m, d] = iso.slice(0, 10).split('-').map(Number);
  return new Date(y, m - 1, d, 12, 0, 0, 0);
}

export function toISO(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${dd}`;
}

export function hoyISO(): string {
  return toISO(hoy());
}

/** "8 de julio" (fmtF del demo). */
export function fmtF(iso: string): string {
  const p = iso.split('-');
  return `${+p[2]} de ${MESES[+p[1] - 1]}`;
}

/** "8 de julio de 2026" (fmtL del demo). */
export function fmtL(iso: string): string {
  const p = iso.split('-');
  return `${+p[2]} de ${MESES[+p[1] - 1]} de ${p[0]}`;
}

/** Días calendario desde hoy hasta la fecha (negativo = pasada). */
export function diasDesdeHoy(iso: string): number {
  return Math.round((parseISO(iso).getTime() - hoy().getTime()) / 864e5);
}

export function shiftISO(iso: string, n: number): string {
  const t = parseISO(iso);
  t.setDate(t.getDate() + n);
  return toISO(t);
}

/** Mes actual como "YYYY-MM" (vista calendario). */
export function mesActualISO(): string {
  return hoyISO().slice(0, 7);
}
