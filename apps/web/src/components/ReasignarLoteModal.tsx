import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../lib';

interface Props {
  n: number;
  ocupado: boolean;
  onCancel: () => void;
  onConfirm: (responsableId: number) => void;
}

export function ReasignarLoteModal({ n, ocupado, onCancel, onConfirm }: Props) {
  const usuariosQ = useQuery({ queryKey: ['usuarios'], queryFn: () => api.listUsuarios() });
  const [sel, setSel] = useState<number>(0);
  // Solo roles operativos pueden ser responsables (no 'pendiente').
  const operativos = (usuariosQ.data ?? []).filter((u) => u.rol !== 'pendiente' && u.activo);
  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 130, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div className="overlay-backdrop" style={{ position: 'fixed' }} onClick={ocupado ? undefined : onCancel} />
      <div role="dialog" aria-modal="true" aria-label="Reasignar responsable en lote" className="modal-card" style={{ width: 420, maxWidth: '92vw' }}>
        <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)', fontWeight: 600 }}>
          Reasignar {n} {n === 1 ? 'acuerdo' : 'acuerdos'}
        </div>
        <div style={{ padding: '18px 22px' }}>
          <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Nuevo responsable</label>
          <select
            value={sel}
            onChange={(e) => setSel(Number(e.target.value))}
            style={{ width: '100%', padding: '10px 12px', fontSize: 13 }}
          >
            <option value={0}>Selecciona…</option>
            {operativos.map((u) => (
              <option key={u.id} value={u.id}>{u.nombre}</option>
            ))}
          </select>
        </div>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', padding: '0 22px 18px' }}>
          <button type="button" className="btn btn--ghost btn--sm" onClick={onCancel} disabled={ocupado}>
            Cancelar
          </button>
          <button type="button" className="btn btn--accent btn--sm" disabled={ocupado || sel === 0} onClick={() => onConfirm(sel)}>
            {ocupado ? 'Reasignando…' : 'Reasignar'}
          </button>
        </div>
      </div>
    </div>
  );
}
