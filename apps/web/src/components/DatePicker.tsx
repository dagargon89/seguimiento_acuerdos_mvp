/**
 * Selector de fecha propio con estética Cívica Nocturna (sustituye a los
 * <input type="date"> nativos): calendario mensual en desplegable, con
 * navegación de mes, mínimo opcional, día de hoy resaltado y atajo "Hoy".
 * Valores en ISO (YYYY-MM-DD), TZ-safe vía helpers de lib/fechas.
 */
import { useCallback, useState } from 'react';
import type { CSSProperties } from 'react';
import { createPortal } from 'react-dom';
import { MESES, fmtF, fmtL, hoyISO, toISO } from '../lib/fechas';
import { useDesplegable } from './useDesplegable';

const DIAS_SEMANA = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

interface DatePickerProps {
  /** Fecha ISO (YYYY-MM-DD) o '' si aún no hay selección. */
  value: string;
  onChange: (iso: string) => void;
  /** Fecha ISO mínima seleccionable (inclusive). */
  min?: string;
  id?: string;
  ariaLabel?: string;
  placeholder?: string;
  /** normal (formularios) · cell (hoja de captura). */
  variante?: 'normal' | 'cell';
  /** Estilo extra del disparador. */
  estilo?: CSSProperties;
}

export function DatePicker({
  value,
  onChange,
  min,
  id,
  ariaLabel,
  placeholder = 'Selecciona fecha…',
  variante = 'normal',
  estilo,
}: DatePickerProps) {
  const [abierto, setAbierto] = useState(false);
  // Mes mostrado en el calendario: {anio, mes 1..12}.
  const [vista, setVista] = useState(() => {
    const base = value || hoyISO();
    return { anio: Number(base.slice(0, 4)), mes: Number(base.slice(5, 7)) };
  });

  const cerrar = useCallback(() => setAbierto(false), []);
  const { anclaRef, panelRef, estilo: estiloPanel } = useDesplegable(abierto, cerrar, { ancho: 276 });

  const abrir = () => {
    const base = value || hoyISO();
    setVista({ anio: Number(base.slice(0, 4)), mes: Number(base.slice(5, 7)) });
    setAbierto(true);
  };

  const cambiarMes = (delta: number) => {
    setVista(({ anio, mes }) => {
      const d = new Date(anio, mes - 1 + delta, 1, 12);
      return { anio: d.getFullYear(), mes: d.getMonth() + 1 };
    });
  };

  const elegir = (iso: string) => {
    onChange(iso);
    setAbierto(false);
    anclaRef.current?.focus();
  };

  const hoyIso = hoyISO();
  const deshabilitado = (iso: string) => (min !== undefined ? iso < min : false);

  // Rejilla del mes en curso (lunes primero).
  const diasEnMes = new Date(vista.anio, vista.mes, 0).getDate();
  const offset = (new Date(vista.anio, vista.mes - 1, 1, 12).getDay() + 6) % 7;
  const celdas: Array<string | null> = [];
  for (let i = 0; i < offset; i++) celdas.push(null);
  for (let d = 1; d <= diasEnMes; d++) celdas.push(toISO(new Date(vista.anio, vista.mes - 1, d, 12)));

  return (
    <>
      <button
        ref={anclaRef}
        type="button"
        id={id}
        className={`cselect cselect--${variante === 'cell' ? 'cell' : 'normal'}`}
        style={estilo}
        aria-label={ariaLabel}
        aria-haspopup="dialog"
        aria-expanded={abierto}
        onClick={() => (abierto ? cerrar() : abrir())}
      >
        <span className={`cselect__valor${value ? '' : ' cselect__valor--placeholder'}`}>
          {value ? (variante === 'cell' ? fmtF(value) : fmtL(value)) : placeholder}
        </span>
        <span className="dp__icono" aria-hidden="true">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round">
            <rect x="3.5" y="5" width="17" height="15.5" rx="2.5" />
            <path d="M3.5 10h17M8 2.8V6M16 2.8V6" />
          </svg>
        </span>
      </button>

      {abierto &&
        estiloPanel &&
        createPortal(
          <div ref={panelRef} className="dropdown-panel dp" style={estiloPanel} role="dialog" aria-label="Elegir fecha">
            <div className="dp__cabecera">
              <button type="button" className="dp__nav" aria-label="Mes anterior" onClick={() => cambiarMes(-1)}>
                ‹
              </button>
              <span className="dp__mes">
                {MESES[vista.mes - 1]} de {vista.anio}
              </span>
              <button type="button" className="dp__nav" aria-label="Mes siguiente" onClick={() => cambiarMes(1)}>
                ›
              </button>
            </div>
            <div className="dp__semana">
              {DIAS_SEMANA.map((d) => (
                <span key={d}>{d}</span>
              ))}
            </div>
            <div className="dp__grid">
              {celdas.map((iso, i) =>
                iso === null ? (
                  <span key={`v-${i}`} />
                ) : (
                  <button
                    key={iso}
                    type="button"
                    className={`dp__dia${iso === value ? ' is-sel' : ''}${iso === hoyIso ? ' is-hoy' : ''}`}
                    disabled={deshabilitado(iso)}
                    aria-label={fmtL(iso)}
                    onClick={() => elegir(iso)}
                  >
                    {Number(iso.slice(8, 10))}
                  </button>
                ),
              )}
            </div>
            <div className="dp__pie">
              <button type="button" className="dp__hoy" disabled={deshabilitado(hoyIso)} onClick={() => elegir(hoyIso)}>
                Hoy · {fmtF(hoyIso)}
              </button>
            </div>
          </div>,
          document.body,
        )}
    </>
  );
}
