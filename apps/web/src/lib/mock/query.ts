/**
 * Helpers mínimos de consulta y fechas para el mock (Demo-First v2).
 * Solo los usa api.mock.ts — las pantallas jamás importan de aquí.
 */

const DAY_MS = 86_400_000;

/** Hoy a mediodía local (evita bordes de TZ — lección BQS). */
export function hoy(): Date {
  const t = new Date();
  t.setHours(12, 0, 0, 0);
  return t;
}

export function toIsoDate(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${dd}`;
}

export function parseIsoDate(iso: string): Date {
  const [y, m, d] = iso.slice(0, 10).split('-').map(Number);
  return new Date(y, m - 1, d, 12, 0, 0, 0);
}

/** Días de diferencia (b - a) en días calendario. */
export function diffDias(a: Date, b: Date): number {
  return Math.round((b.getTime() - a.getTime()) / DAY_MS);
}

export function shiftIsoDate(iso: string, dias: number): string {
  const d = parseIsoDate(iso);
  d.setDate(d.getDate() + dias);
  return toIsoDate(d);
}

/** Re-basa un valor fecha/datetime ISO conservando la hora si existe. */
export function rebaseValor(valor: string, offsetDias: number): string {
  const fecha = shiftIsoDate(valor.slice(0, 10), offsetDias);
  return valor.length > 10 ? `${fecha}${valor.slice(10)}` : fecha;
}

const RE_FECHA = /^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}:\d{2})?$/;

/**
 * Recorre las tablas del db clonado y desplaza todas las fechas `offsetDias`,
 * manteniendo vivo el escenario del demo en cualquier fecha real (H-06).
 */
export function rebaseDb<T extends Record<string, unknown[]>>(db: T, offsetDias: number): T {
  if (offsetDias === 0) return db;
  for (const tabla of Object.values(db)) {
    for (const fila of tabla as Record<string, unknown>[]) {
      for (const [k, v] of Object.entries(fila)) {
        if (typeof v === 'string' && RE_FECHA.test(v)) {
          fila[k] = rebaseValor(v, offsetDias);
        }
      }
    }
  }
  return db;
}

export function nowDateTime(): string {
  const d = new Date();
  const hh = String(d.getHours()).padStart(2, '0');
  const mm = String(d.getMinutes()).padStart(2, '0');
  const ss = String(d.getSeconds()).padStart(2, '0');
  return `${toIsoDate(d)} ${hh}:${mm}:${ss}`;
}

/** Latencia realista del mock. */
export function delay(ms = 180): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}

export function nextId(rows: Array<{ id: number }>): number {
  return rows.reduce((max, r) => Math.max(max, r.id), 0) + 1;
}
