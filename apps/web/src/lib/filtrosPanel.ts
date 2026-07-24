import type { Acuerdo } from './types';

/** Criterios de filtrado client-side del Panel (todos opcionales vía valor vacío/0). */
export interface CriteriosFiltro {
  area: number;        // 0 = todas
  responsable: number; // 0 = todos
  q: string;           // texto libre, case-insensitive; '' = sin filtro
  desde: string;       // ISO YYYY-MM-DD o '' (sin límite inferior)
  hasta: string;       // ISO YYYY-MM-DD o '' (sin límite superior)
}

/**
 * Filtra en memoria los acuerdos ya cargados en el Panel. El rango opera sobre
 * `fecha_compromiso` (ISO YYYY-MM-DD, comparación lexicográfica = cronológica),
 * inclusivo en ambos extremos. No hace red — es lógica pura y testeable.
 */
export function filtrarAcuerdos(acuerdos: Acuerdo[], c: CriteriosFiltro): Acuerdo[] {
  const q = c.q.trim().toLowerCase();
  return acuerdos.filter((a) => {
    if (c.area && a.area.id !== c.area) return false;
    if (c.responsable && a.responsable.id !== c.responsable) return false;
    if (c.desde && a.fecha_compromiso < c.desde) return false;
    if (c.hasta && a.fecha_compromiso > c.hasta) return false;
    if (q && !`${a.tema ?? ''} ${a.accion} ${a.responsable.nombre}`.toLowerCase().includes(q)) return false;
    return true;
  });
}
