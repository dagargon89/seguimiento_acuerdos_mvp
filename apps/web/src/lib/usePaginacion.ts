import { useEffect, useMemo, useState } from 'react';

export const TAM_PAGINA_DEFAULT = 10;

export interface Paginacion<T> {
  /** Página actual (1-indexada). */
  pagina: number;
  /** Total de páginas (mínimo 1). */
  totalPaginas: number;
  /** Elementos de la página actual. */
  pagina_items: T[];
  /** Índice del primer elemento mostrado (1-indexado); 0 si la lista está vacía. */
  desde: number;
  /** Índice del último elemento mostrado; 0 si la lista está vacía. */
  hasta: number;
  /** Total de elementos en la lista completa. */
  total: number;
  irA: (p: number) => void;
  anterior: () => void;
  siguiente: () => void;
}

/**
 * Paginación en cliente sobre una lista ya cargada. La página vuelve a 1
 * cuando cambia la lista (p. ej. al aplicar un filtro o búsqueda) y se acota
 * si el total de páginas se reduce.
 */
export function usePaginacion<T>(items: T[], tamPagina = TAM_PAGINA_DEFAULT): Paginacion<T> {
  const [pagina, setPagina] = useState(1);
  const total = items.length;
  const totalPaginas = Math.max(1, Math.ceil(total / tamPagina));

  // Volver a la primera página cuando cambia el conjunto de datos.
  useEffect(() => {
    setPagina(1);
  }, [items]);

  // Acotar si el total de páginas se reduce por debajo de la página actual.
  useEffect(() => {
    if (pagina > totalPaginas) setPagina(totalPaginas);
  }, [pagina, totalPaginas]);

  const paginaActual = Math.min(pagina, totalPaginas);
  const inicio = (paginaActual - 1) * tamPagina;

  const pagina_items = useMemo(
    () => items.slice(inicio, inicio + tamPagina),
    [items, inicio, tamPagina],
  );

  const irA = (p: number) => setPagina(Math.min(Math.max(1, p), totalPaginas));

  return {
    pagina: paginaActual,
    totalPaginas,
    pagina_items,
    desde: total === 0 ? 0 : inicio + 1,
    hasta: Math.min(inicio + tamPagina, total),
    total,
    irA,
    anterior: () => irA(paginaActual - 1),
    siguiente: () => irA(paginaActual + 1),
  };
}
