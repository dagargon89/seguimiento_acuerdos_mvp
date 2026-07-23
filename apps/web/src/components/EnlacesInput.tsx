/**
 * Editor de múltiples enlaces de productos (0..N). Lista dinámica de inputs con
 * botón "+ Agregar enlace" y quitar por fila. Se usa en la captura (vista
 * tarjeta) y en el drawer de edición; la vista "Hoja de captura" usa un textarea
 * directo por compacidad (ver Captura.tsx). Las cadenas vacías se permiten
 * mientras se edita y se filtran al guardar.
 */

interface Props {
  enlaces: string[];
  onChange: (enlaces: string[]) => void;
  /** Prefijo para los `id`/`htmlFor` de cada input (accesibilidad). */
  idBase?: string;
}

export function EnlacesInput({ enlaces, onChange, idBase = 'enlace' }: Props) {
  const setAt = (i: number, v: string) => onChange(enlaces.map((e, idx) => (idx === i ? v : e)));
  const quitar = (i: number) => onChange(enlaces.filter((_, idx) => idx !== i));
  const agregar = () => onChange([...enlaces, '']);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      {enlaces.map((e, i) => (
        <div key={i} style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <input
            className="input"
            id={`${idBase}-${i}`}
            placeholder="URL del documento o carpeta en Drive"
            value={e}
            onChange={(ev) => setAt(i, ev.target.value)}
            style={{ flex: 1 }}
          />
          <button
            type="button"
            className="captura-bloque__quitar"
            onClick={() => quitar(i)}
            title="Quitar este enlace"
            aria-label="Quitar este enlace"
          >
            ✕
          </button>
        </div>
      ))}
      <button
        type="button"
        onClick={agregar}
        style={{
          alignSelf: 'flex-start',
          background: 'none',
          border: 'none',
          padding: '2px 0',
          cursor: 'pointer',
          fontSize: 12.5,
          fontWeight: 600,
          color: 'var(--text-link)',
        }}
      >
        + Agregar enlace
      </button>
    </div>
  );
}
