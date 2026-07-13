/**
 * Posicionamiento compartido de paneles desplegables (Select, DatePicker):
 * el panel se renderiza en un portal con `position:fixed` anclado al
 * disparador — así no lo recorta ningún contenedor con overflow (hoja de
 * captura, drawer, modales) — con volteo vertical si no cabe abajo.
 * Cierra con clic fuera y Escape (en captura, para no cerrar también el
 * modal anfitrión); se reposiciona al hacer scroll o cambiar el tamaño.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { CSSProperties } from 'react';

const ALTO_MAX = 320;
const MARGEN = 8;

interface OpcionesDesplegable {
  /** Ancho fijo del panel en px; si se omite, hereda el del ancla. */
  ancho?: number;
  /** Ancho mínimo cuando hereda el del ancla. */
  anchoMin?: number;
}

export function useDesplegable(abierto: boolean, cerrar: () => void, opciones: OpcionesDesplegable = {}) {
  const { ancho, anchoMin = 0 } = opciones;
  const anclaRef = useRef<HTMLButtonElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const [estilo, setEstilo] = useState<CSSProperties | null>(null);

  const calcular = useCallback(() => {
    const r = anclaRef.current?.getBoundingClientRect();
    if (!r) return;
    const anchoPanel = ancho ?? Math.max(r.width, anchoMin);
    const left = Math.max(MARGEN, Math.min(r.left, window.innerWidth - anchoPanel - MARGEN));
    const espacioAbajo = window.innerHeight - r.bottom - MARGEN;
    const espacioArriba = r.top - MARGEN;
    const haciaArriba = espacioAbajo < 220 && espacioArriba > espacioAbajo;
    setEstilo({
      position: 'fixed',
      left,
      width: anchoPanel,
      ...(haciaArriba
        ? { bottom: window.innerHeight - r.top + 6, maxHeight: Math.min(ALTO_MAX, espacioArriba) }
        : { top: r.bottom + 6, maxHeight: Math.min(ALTO_MAX, espacioAbajo) }),
    });
  }, [ancho, anchoMin]);

  useEffect(() => {
    if (!abierto) {
      setEstilo(null);
      return;
    }
    calcular();

    const onMousedown = (e: MouseEvent) => {
      const t = e.target as Node;
      if (!anclaRef.current?.contains(t) && !panelRef.current?.contains(t)) cerrar();
    };
    const onKeydown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        // En captura: cierra solo el desplegable, sin que el Esc llegue al
        // listener del modal anfitrión (que cerraría todo el modal).
        e.stopPropagation();
        cerrar();
        anclaRef.current?.focus();
      }
    };
    const onScroll = (e: Event) => {
      if (panelRef.current?.contains(e.target as Node)) return;
      calcular();
    };

    document.addEventListener('mousedown', onMousedown);
    document.addEventListener('keydown', onKeydown, true);
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', calcular);
    return () => {
      document.removeEventListener('mousedown', onMousedown);
      document.removeEventListener('keydown', onKeydown, true);
      window.removeEventListener('scroll', onScroll, true);
      window.removeEventListener('resize', calcular);
    };
  }, [abierto, calcular, cerrar]);

  return { anclaRef, panelRef, estilo };
}
