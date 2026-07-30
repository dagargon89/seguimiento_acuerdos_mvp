import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { RichText } from './RichText';

const ANCHO_TOOLTIP = 420;
const MARGEN_VIEWPORT = 12;
const ESPACIO_TRIGGER = 6;

type Posicion = { top: number; left: number; abrirArriba: boolean };

/**
 * Envuelve el texto truncado (2 líneas) de la columna "Acuerdo / acción" y
 * muestra, al pasar el cursor o al enfocar (teclado), un panel flotante con
 * la acción completa renderizada como Markdown (Task 12).
 *
 * El panel se monta vía portal en `document.body` con `position: fixed`
 * (fix ronda 1): las tablas de Panel/MisAcuerdos viven dentro de un
 * `.panel-card` con `overflow: hidden` (la clase declara el shorthand
 * completo; el `overflowX: 'auto'` inline del wrapper solo sobrescribe el
 * longhand horizontal, `overflow-y` sigue en `hidden`). Un tooltip
 * `position: absolute` anidado ahí queda recortado sin poder hacer scroll
 * en filas cercanas al borde inferior del contenedor. Al escapar por portal
 * y posicionarse con `getBoundingClientRect()` del trigger, el tooltip ya no
 * depende del overflow de ningún ancestro.
 *
 * No detiene la propagación del click: el click en la fila sigue abriendo
 * el Drawer (comportamiento existente, no se toca).
 */
type Razon = 'hoverTrigger' | 'hoverTooltip' | 'foco';

export function TooltipAccion({ accion, children }: { accion: string; children: ReactNode }) {
  const [abierto, setAbierto] = useState(false);
  const [posicion, setPosicion] = useState<Posicion | null>(null);
  const triggerRef = useRef<HTMLSpanElement>(null);
  const tooltipRef = useRef<HTMLSpanElement>(null);
  // Conjunto de razones activas (hover del trigger, hover del propio panel —
  // vía portal ya no es descendiente del trigger, así que necesita su propio
  // mouseenter/mouseleave para no cerrarse al mover el cursor hacia un enlace
  // dentro del Markdown — o foco por teclado). Abierto mientras haya ≥1.
  const razonesRef = useRef<Set<Razon>>(new Set());

  const calcularPosicion = () => {
    const trigger = triggerRef.current;
    if (!trigger) return;
    const rect = trigger.getBoundingClientRect();
    const ancho = Math.min(ANCHO_TOOLTIP, window.innerWidth * 0.8);
    let left = rect.left;
    if (left + ancho > window.innerWidth - MARGEN_VIEWPORT) {
      left = window.innerWidth - MARGEN_VIEWPORT - ancho;
    }
    if (left < MARGEN_VIEWPORT) left = MARGEN_VIEWPORT;
    setPosicion({ top: rect.bottom + ESPACIO_TRIGGER, left, abrirArriba: false });
  };

  const marcar = (razon: Razon) => {
    const eraVacio = razonesRef.current.size === 0;
    razonesRef.current.add(razon);
    if (eraVacio) calcularPosicion();
    setAbierto(true);
  };
  const desmarcar = (razon: Razon) => {
    razonesRef.current.delete(razon);
    if (razonesRef.current.size === 0) {
      setAbierto(false);
      setPosicion(null);
    }
  };
  const cerrar = () => {
    razonesRef.current.clear();
    setAbierto(false);
    setPosicion(null);
  };

  // Tras el primer render del tooltip (abajo del trigger), si se sale del
  // viewport por abajo lo volteamos a abrir hacia arriba. Corre antes del
  // paint (useLayoutEffect) para que el ajuste no parpadee.
  useLayoutEffect(() => {
    if (!abierto || !posicion || posicion.abrirArriba) return;
    const tooltip = tooltipRef.current;
    const trigger = triggerRef.current;
    if (!tooltip || !trigger) return;
    const tooltipRect = tooltip.getBoundingClientRect();
    if (tooltipRect.bottom > window.innerHeight - MARGEN_VIEWPORT) {
      const rect = trigger.getBoundingClientRect();
      setPosicion({
        top: rect.top - tooltipRect.height - ESPACIO_TRIGGER,
        left: posicion.left,
        abrirArriba: true,
      });
    }
  }, [abierto, posicion]);

  // Si el usuario hace scroll (en la ventana o en cualquier contenedor con
  // overflow, p.ej. el wrapper horizontal de la tabla) o cambia el tamaño de
  // la ventana mientras el tooltip está abierto, la posición fija calculada
  // queda obsoleta; lo cerramos en vez de dejarlo flotando desalineado.
  useEffect(() => {
    if (!abierto) return;
    const onScrollOrResize = () => cerrar();
    window.addEventListener('scroll', onScrollOrResize, true);
    window.addEventListener('resize', onScrollOrResize);
    return () => {
      window.removeEventListener('scroll', onScrollOrResize, true);
      window.removeEventListener('resize', onScrollOrResize);
    };
  }, [abierto]);

  return (
    <span
      ref={triggerRef}
      style={{ position: 'relative', display: 'inline-block', maxWidth: '100%' }}
      onMouseEnter={() => marcar('hoverTrigger')}
      onMouseLeave={() => desmarcar('hoverTrigger')}
      onFocus={() => marcar('foco')}
      onBlur={() => desmarcar('foco')}
      tabIndex={0}
    >
      {children}
      {abierto &&
        posicion &&
        createPortal(
          <span
            ref={tooltipRef}
            role="tooltip"
            className="tooltip-accion"
            onMouseEnter={() => marcar('hoverTooltip')}
            onMouseLeave={() => desmarcar('hoverTooltip')}
            onFocus={() => marcar('foco')}
            onBlur={() => desmarcar('foco')}
            style={{
              position: 'fixed',
              zIndex: 200,
              top: posicion.top,
              left: posicion.left,
              width: 'min(420px, 80vw)',
              padding: '12px 14px',
              background: 'var(--surface)',
              border: '1px solid var(--border)',
              borderRadius: 10,
              boxShadow: '0 10px 30px rgba(0,0,0,.18)',
              textAlign: 'left',
              whiteSpace: 'normal',
            }}
          >
            <RichText html={accion} />
          </span>,
          document.body,
        )}
    </span>
  );
}
