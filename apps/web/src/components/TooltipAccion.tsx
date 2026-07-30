import { useState, type ReactNode } from 'react';
import { Markdown } from './Markdown';

/**
 * Envuelve el texto truncado (2 líneas) de la columna "Acuerdo / acción" y
 * muestra, al pasar el cursor o al enfocar (teclado), un panel flotante con
 * la acción completa renderizada como Markdown (Task 12).
 *
 * No detiene la propagación del click: el click en la fila sigue abriendo
 * el Drawer (comportamiento existente, no se toca).
 */
export function TooltipAccion({ accion, children }: { accion: string; children: ReactNode }) {
  const [abierto, setAbierto] = useState(false);
  return (
    <span
      style={{ position: 'relative', display: 'inline-block', maxWidth: '100%' }}
      onMouseEnter={() => setAbierto(true)}
      onMouseLeave={() => setAbierto(false)}
      onFocus={() => setAbierto(true)}
      onBlur={() => setAbierto(false)}
      tabIndex={0}
    >
      {children}
      {abierto && (
        <span
          role="tooltip"
          className="tooltip-accion"
          style={{
            position: 'absolute',
            zIndex: 200,
            top: '100%',
            left: 0,
            marginTop: 6,
            width: 'min(420px, 80vw)',
            maxWidth: 'calc(100vw - 24px)',
            padding: '12px 14px',
            background: 'var(--surface)',
            border: '1px solid var(--border)',
            borderRadius: 10,
            boxShadow: '0 10px 30px rgba(0,0,0,.18)',
            textAlign: 'left',
            whiteSpace: 'normal',
          }}
        >
          <Markdown source={accion} />
        </span>
      )}
    </span>
  );
}
