import { useState } from 'react';
import { hoyISO } from '../lib/fechas';

interface Props {
  n: number;
  ocupado: boolean;
  onCancel: () => void;
  onConfirm: (fechaISO: string) => void;
}

export function ReprogramarLoteModal({ n, ocupado, onCancel, onConfirm }: Props) {
  const [fecha, setFecha] = useState('');
  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 130, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div className="overlay-backdrop" style={{ position: 'fixed' }} onClick={ocupado ? undefined : onCancel} />
      <div role="dialog" aria-modal="true" aria-label="Reprogramar en lote" className="modal-card" style={{ width: 420, maxWidth: '92vw' }}>
        <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)', fontWeight: 600 }}>
          Reprogramar {n} {n === 1 ? 'acuerdo' : 'acuerdos'}
        </div>
        <div style={{ padding: '18px 22px' }}>
          <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Nueva fecha compromiso</label>
          <input
            type="date"
            value={fecha}
            min={hoyISO()}
            onChange={(e) => setFecha(e.target.value)}
            style={{ width: '100%', padding: '10px 12px', fontSize: 13 }}
          />
          <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '10px 0 0' }}>
            Se registrará un avance con nota automática en cada acuerdo. Los vencidos volverán a “en proceso”.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', padding: '0 22px 18px' }}>
          <button type="button" className="btn btn--ghost btn--sm" onClick={onCancel} disabled={ocupado}>
            Cancelar
          </button>
          <button type="button" className="btn btn--accent btn--sm" disabled={ocupado || !fecha} onClick={() => onConfirm(fecha)}>
            {ocupado ? 'Reprogramando…' : 'Reprogramar'}
          </button>
        </div>
      </div>
    </div>
  );
}
