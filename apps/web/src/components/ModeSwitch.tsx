interface ModeSwitchProps<K extends string> {
  opciones: ReadonlyArray<{ k: K; label: string }>;
  activo: K;
  onChange: (k: K) => void;
}

/** Conmutador de modos de vista (1:1 con .mode-switch del demo). */
export function ModeSwitch<K extends string>({ opciones, activo, onChange }: ModeSwitchProps<K>) {
  return (
    <div className="mode-switch">
      {opciones.map((m) => (
        <button
          key={m.k}
          type="button"
          className={`mode-btn${activo === m.k ? ' is-active' : ''}`}
          onClick={() => onChange(m.k)}
        >
          {m.label}
        </button>
      ))}
    </div>
  );
}
