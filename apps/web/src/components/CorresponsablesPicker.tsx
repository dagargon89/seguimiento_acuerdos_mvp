/**
 * Selector de corresponsables: chips removibles + select para agregar
 * personas del directorio (función nueva — mantiene el lenguaje visual del demo).
 */
import type { Usuario } from '../lib';
import { Avatar } from './Avatar';

interface CorresponsablesPickerProps {
  directorio: Usuario[];
  seleccionados: number[];
  excluirId?: number | null;
  onChange: (ids: number[]) => void;
  compacto?: boolean;
}

export function CorresponsablesPicker({
  directorio,
  seleccionados,
  excluirId = null,
  onChange,
  compacto = false,
}: CorresponsablesPickerProps) {
  const elegibles = directorio.filter(
    (u) => u.activo && u.id !== excluirId && !seleccionados.includes(u.id),
  );
  const chips = seleccionados
    .map((id) => directorio.find((u) => u.id === id))
    .filter((u): u is Usuario => Boolean(u));

  const quitar = (id: number) => onChange(seleccionados.filter((x) => x !== id));
  const agregar = (valor: string) => {
    const id = Number(valor);
    if (id && !seleccionados.includes(id)) onChange([...seleccionados, id]);
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: compacto ? 4 : 8 }}>
      {chips.length > 0 && (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
          {chips.map((u) => (
            <span
              key={u.id}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 6,
                background: 'rgba(91,157,245,.14)',
                color: 'var(--blue)',
                border: '1px solid rgba(91,157,245,.25)',
                borderRadius: 999,
                padding: compacto ? '2px 8px 2px 3px' : '3px 10px 3px 4px',
                fontSize: compacto ? 11 : 12,
                fontWeight: 600,
              }}
            >
              <Avatar nombre={u.nombre} size="sm" tono="blue" />
              {u.nombre}
              <button
                type="button"
                aria-label={`Quitar a ${u.nombre}`}
                onClick={() => quitar(u.id)}
                style={{
                  border: 'none',
                  background: 'transparent',
                  color: 'inherit',
                  cursor: 'pointer',
                  fontSize: compacto ? 10 : 11,
                  lineHeight: 1,
                  padding: 2,
                }}
              >
                ✕
              </button>
            </span>
          ))}
        </div>
      )}
      <select
        className={compacto ? 'cell-input cell-input--select' : 'select'}
        value=""
        aria-label="Agregar corresponsable"
        onChange={(e) => agregar(e.target.value)}
      >
        <option value="">
          {chips.length > 0 ? '+ Agregar corresponsable…' : 'Corresponsables (opcional)…'}
        </option>
        {elegibles.map((u) => (
          <option key={u.id} value={u.id}>
            {u.nombre}
          </option>
        ))}
      </select>
    </div>
  );
}
