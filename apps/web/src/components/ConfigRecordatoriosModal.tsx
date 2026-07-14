/**
 * Modal de configuración del esquema global de recordatorios (nuevo,
 * solo Dirección) — mantiene el lenguaje visual del demo.
 */
import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib';
import type { ConfigRecordatorios } from '../lib';
import { camposError, mensajeError } from './EstadoHelpers';
import { Select } from './Select';
import { useToast } from './Toast';

interface ConfigRecordatoriosModalProps {
  config: ConfigRecordatorios;
  onClose: () => void;
}

export function ConfigRecordatoriosModal({ config, onClose }: ConfigRecordatoriosModalProps) {
  const { toast } = useToast();
  const queryClient = useQueryClient();

  const [diasCsv, setDiasCsv] = useState(config.dias_antes.join(', '));
  const [diaCompromiso, setDiaCompromiso] = useState(config.dia_compromiso);
  const [vencidoCada, setVencidoCada] = useState(String(config.vencido_cada_dias));
  const [vencidoMax, setVencidoMax] = useState(String(config.vencido_max_repeticiones));
  const [frecuencia, setFrecuencia] = useState<ConfigRecordatorios['resumen_frecuencia']>(config.resumen_frecuencia);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  const guardarMut = useMutation({
    mutationFn: (cfg: ConfigRecordatorios) => api.setConfigRecordatorios(cfg),
    onSuccess: () => {
      toast('El esquema de recordatorios se actualizó.');
      void queryClient.invalidateQueries({ queryKey: ['config-recordatorios'] });
      void queryClient.invalidateQueries({ queryKey: ['recordatorios'] });
      void queryClient.invalidateQueries({ queryKey: ['acuerdo'] });
      onClose();
    },
    onError: (e) => {
      const campos = camposError(e);
      setError(campos.dias_antes ? `Avisos previos: ${campos.dias_antes}` : mensajeError(e));
      toast(mensajeError(e), 'error');
    },
  });

  const guardar = () => {
    const dias = diasCsv
      .split(/[,\s]+/)
      .filter((x) => x !== '')
      .map(Number);
    if (dias.length === 0 || dias.some((d) => !Number.isInteger(d) || d < 0 || d > 30)) {
      setError('Escribe los avisos previos como días separados por coma (0–30), por ejemplo: 7, 3, 1.');
      return;
    }
    const cada = Number(vencidoCada);
    const max = Number(vencidoMax);
    if (!Number.isInteger(cada) || cada < 1 || !Number.isInteger(max) || max < 0) {
      setError('El seguimiento de vencidos requiere números válidos (cada N días, máximo de repeticiones).');
      return;
    }
    setError(null);
    guardarMut.mutate({
      dias_antes: dias,
      dia_compromiso: diaCompromiso,
      vencido_cada_dias: cada,
      vencido_max_repeticiones: max,
      resumen_frecuencia: frecuencia,
    });
  };

  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 120, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div className="overlay-backdrop" style={{ position: 'fixed' }} onClick={onClose} />
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Configurar avisos"
        className="modal-card"
        style={{
          width: 520,
          maxWidth: '92vw',
          maxHeight: '86vh',
          overflowY: 'auto',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, padding: '18px 22px', borderBottom: '1px solid var(--border)' }}>
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 10.5, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.14em', color: 'var(--text-muted)', marginBottom: 5 }}>
              Configuración · solo Dirección
            </div>
            <div style={{ fontSize: 16, fontWeight: 600, lineHeight: 1.35 }}>Esquema global de recordatorios</div>
          </div>
          <button type="button" className="modal__close" onClick={onClose} aria-label="Cerrar">
            ✕
          </button>
        </div>
        <div style={{ padding: '20px 22px 24px', display: 'flex', flexDirection: 'column', gap: 16 }}>
          {error && (
            <div className="alert alert--error">
              <div className="alert__body">{error}</div>
            </div>
          )}
          <div className="field">
            <label className="field__label" htmlFor="cfg-dias">
              Avisos previos (días antes, separados por coma) <span className="req">*</span>
            </label>
            <input
              id="cfg-dias"
              className="input"
              placeholder="Ej. 7, 3, 1"
              value={diasCsv}
              onChange={(e) => setDiasCsv(e.target.value)}
            />
          </div>
          <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, fontWeight: 500, cursor: 'pointer' }}>
            <input type="checkbox" checked={diaCompromiso} onChange={(e) => setDiaCompromiso(e.target.checked)} />
            Enviar aviso el día del compromiso
          </label>
          <div className="grid grid-cols-1 sm:grid-cols-[1fr_1fr] gap-[16px]">
            <div className="field">
              <label className="field__label" htmlFor="cfg-cada">
                Vencidos: repetir cada (días)
              </label>
              <input
                id="cfg-cada"
                className="input"
                type="number"
                min={1}
                value={vencidoCada}
                onChange={(e) => setVencidoCada(e.target.value)}
              />
            </div>
            <div className="field">
              <label className="field__label" htmlFor="cfg-max">
                Vencidos: máximo de envíos
              </label>
              <input
                id="cfg-max"
                className="input"
                type="number"
                min={0}
                value={vencidoMax}
                onChange={(e) => setVencidoMax(e.target.value)}
              />
            </div>
          </div>
          <div className="field">
            <label className="field__label" htmlFor="cfg-frec">
              Frecuencia del resumen periódico
            </label>
            <Select
              id="cfg-frec"
              buscable={false}
              value={frecuencia}
              opciones={[
                { value: 'semanal', label: 'Semanal' },
                { value: 'quincenal', label: 'Quincenal' },
                { value: 'mensual', label: 'Mensual' },
              ]}
              onChange={(v) => setFrecuencia(v as ConfigRecordatorios['resumen_frecuencia'])}
            />
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 4 }}>
            <button type="button" className="btn btn--accent btn--md" onClick={guardar} disabled={guardarMut.isPending}>
              {guardarMut.isPending ? 'Guardando…' : 'Guardar configuración'}
            </button>
            <button type="button" className="btn btn--ghost btn--md" onClick={onClose}>
              Cancelar
            </button>
          </div>
          <p style={{ margin: 0, fontSize: 11.5, lineHeight: 1.6, color: 'var(--text-muted)' }}>
            Este esquema aplica a todos los acuerdos sin esquema personalizado. Los acuerdos con recordatorios
            propios (definidos al capturar) conservan su configuración.
          </p>
        </div>
      </div>
    </div>
  );
}
