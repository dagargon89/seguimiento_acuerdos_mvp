/**
 * Select propio con estética Cívica Nocturna y búsqueda integrada en el
 * desplegable (sustituye a los <select> nativos). Sin dependencias nuevas:
 * portal + posicionamiento de useDesplegable, navegación con teclado
 * (flechas/Enter/Esc) y semántica combobox/listbox.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { CSSProperties, KeyboardEvent as ReactKeyboardEvent } from 'react';
import { createPortal } from 'react-dom';
import { useDesplegable } from './useDesplegable';

export interface OpcionSelect {
  value: string;
  label: string;
}

interface SelectProps {
  opciones: OpcionSelect[];
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  /** Muestra el campo de búsqueda dentro del desplegable (default true). */
  buscable?: boolean;
  disabled?: boolean;
  id?: string;
  ariaLabel?: string;
  /** normal (formularios) · toolbar (filtros del panel) · cell (hoja de captura). */
  variante?: 'normal' | 'toolbar' | 'cell';
  /** Estilo extra del disparador (p. ej. minWidth en tablas). */
  estilo?: CSSProperties;
}

export function Select({
  opciones,
  value,
  onChange,
  placeholder = 'Selecciona…',
  buscable = true,
  disabled = false,
  id,
  ariaLabel,
  variante = 'normal',
  estilo,
}: SelectProps) {
  const [abierto, setAbierto] = useState(false);
  const [busqueda, setBusqueda] = useState('');
  const [activa, setActiva] = useState(0);
  const listaRef = useRef<HTMLDivElement>(null);
  const buscadorRef = useRef<HTMLInputElement>(null);

  const cerrar = useCallback(() => setAbierto(false), []);
  const { anclaRef, panelRef, estilo: estiloPanel } = useDesplegable(abierto, cerrar, { anchoMin: 210 });

  const seleccionada = opciones.find((o) => o.value === value) ?? null;

  const filtradas = useMemo(() => {
    const q = busqueda.trim().toLowerCase();
    return q ? opciones.filter((o) => o.label.toLowerCase().includes(q)) : opciones;
  }, [opciones, busqueda]);

  const abrir = () => {
    if (disabled) return;
    setBusqueda('');
    const idx = opciones.findIndex((o) => o.value === value);
    setActiva(Math.max(idx, 0));
    setAbierto(true);
  };

  // Enfoca el buscador (o la lista) al abrir el panel.
  useEffect(() => {
    if (!abierto) return;
    (buscable ? buscadorRef.current : listaRef.current)?.focus();
  }, [abierto, buscable]);

  // Mantiene visible la opción activa al navegar con teclado.
  useEffect(() => {
    if (!abierto) return;
    listaRef.current?.querySelector('[data-activa="1"]')?.scrollIntoView({ block: 'nearest' });
  }, [abierto, activa]);

  const elegir = (v: string) => {
    onChange(v);
    setAbierto(false);
    anclaRef.current?.focus();
  };

  const onTeclas = (e: ReactKeyboardEvent) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActiva((i) => Math.min(i + 1, filtradas.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActiva((i) => Math.max(i - 1, 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const op = filtradas[activa];
      if (op) elegir(op.value);
    }
  };

  return (
    <>
      <button
        ref={anclaRef}
        type="button"
        id={id}
        className={`cselect cselect--${variante}`}
        style={estilo}
        disabled={disabled}
        aria-label={ariaLabel}
        aria-haspopup="listbox"
        aria-expanded={abierto}
        onClick={() => (abierto ? cerrar() : abrir())}
        onKeyDown={(e) => {
          if (!abierto && (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            abrir();
          }
        }}
      >
        <span className={`cselect__valor${seleccionada ? '' : ' cselect__valor--placeholder'}`}>
          {seleccionada ? seleccionada.label : placeholder}
        </span>
        <span className="cselect__caret" aria-hidden="true">
          ▾
        </span>
      </button>

      {abierto &&
        estiloPanel &&
        createPortal(
          <div ref={panelRef} className="dropdown-panel" style={estiloPanel} onKeyDown={onTeclas}>
            {buscable && (
              <input
                ref={buscadorRef}
                className="cselect__buscador"
                placeholder="Buscar…"
                aria-label="Buscar opción"
                value={busqueda}
                onChange={(e) => {
                  setBusqueda(e.target.value);
                  setActiva(0);
                }}
              />
            )}
            <div ref={listaRef} className="cselect__lista" role="listbox" tabIndex={-1}>
              {filtradas.map((o, i) => (
                <button
                  key={o.value === '' ? '∅' : o.value}
                  type="button"
                  role="option"
                  aria-selected={o.value === value}
                  data-activa={i === activa ? '1' : undefined}
                  className={`cselect__opcion${o.value === value ? ' is-sel' : ''}${i === activa ? ' is-activa' : ''}`}
                  onMouseEnter={() => setActiva(i)}
                  onClick={() => elegir(o.value)}
                >
                  <span className="cselect__opcion-label">{o.label}</span>
                  {o.value === value && <span aria-hidden="true">✓</span>}
                </button>
              ))}
              {filtradas.length === 0 && <div className="cselect__vacio">Sin coincidencias</div>}
            </div>
          </div>,
          document.body,
        )}
    </>
  );
}
