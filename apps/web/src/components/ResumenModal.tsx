/**
 * Modal del resumen periódico (portado de renderResumen del demo; ahora
 * consume api.getResumen(), que agrega por responsable según el contrato).
 */
import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../lib';
import { mensajeError, nombreCorto } from './EstadoHelpers';
import { Avatar } from './Avatar';
import { useSesion } from './SessionContext';

interface ResumenModalProps {
  onClose: () => void;
}

export function ResumenModal({ onClose }: ResumenModalProps) {
  const { sesion } = useSesion();
  const resumenQ = useQuery({ queryKey: ['resumen'], queryFn: () => api.getResumen() });

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  const r = resumenQ.data;
  const ambito = r ? (r.ambito === 'general' ? 'todas las áreas' : r.area?.nombre ?? 'el área') : '';
  const abiertos = r ? r.en_proceso + r.vencidos : 0;
  const u = sesion?.usuario;

  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 120, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div className="overlay-backdrop" style={{ background: 'rgba(26,26,26,.5)', position: 'fixed' }} onClick={onClose} />
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Resumen periódico"
        style={{
          position: 'relative',
          width: 680,
          maxWidth: '92vw',
          maxHeight: '86vh',
          overflowY: 'auto',
          background: '#ffffff',
          borderRadius: 12,
          boxShadow: '0 24px 64px rgba(58,13,65,.35)',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, padding: '18px 22px', borderBottom: '1px solid var(--border-default)' }}>
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 10.5, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.14em', color: 'var(--text-muted)', marginBottom: 5 }}>
              Simulación del resumen periódico por correo
            </div>
            <div style={{ fontSize: 16, fontWeight: 600, lineHeight: 1.35 }}>
              {r
                ? `Resumen ${r.ambito === 'general' ? 'general' : 'del área'}: ${abiertos} ${abiertos === 1 ? 'acuerdo abierto' : 'acuerdos abiertos'} (${ambito})`
                : 'Cargando resumen…'}
            </div>
          </div>
          <button type="button" className="modal__close" onClick={onClose} aria-label="Cerrar">
            ✕
          </button>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '14px 22px', borderBottom: '1px solid var(--pj-neutral-100)' }}>
          <span
            style={{
              width: 36,
              height: 36,
              borderRadius: '50%',
              background: 'var(--pj-purple-700)',
              color: 'var(--pj-lime-400)',
              fontSize: 12,
              fontWeight: 700,
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              flex: 'none',
            }}
          >
            PJ
          </span>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 13.5, fontWeight: 600 }}>
              Panel de acuerdos · Participa Juárez{' '}
              <span style={{ fontWeight: 400, color: 'var(--text-muted)' }}>&lt;acuerdos@planjuarez.org&gt;</span>
            </div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
              para {u?.email ?? '—'} · cada lunes, 9:00
            </div>
          </div>
        </div>
        <div style={{ padding: '22px 26px 26px' }}>
          {resumenQ.isError && (
            <div className="alert alert--error">
              <div className="alert__body">{mensajeError(resumenQ.error)}</div>
            </div>
          )}
          {r && u && (
            <>
              <p style={{ margin: '0 0 12px', fontSize: 14 }}>Hola, {nombreCorto(u.nombre)}:</p>
              <p style={{ margin: '0 0 16px', fontSize: 13.5, lineHeight: 1.65, color: 'var(--text-secondary)' }}>
                Este es el resumen de los acuerdos abiertos de {ambito}, por persona responsable.
              </p>
              <div style={{ display: 'flex', gap: 18, flexWrap: 'wrap', marginBottom: 16, fontSize: 13 }}>
                <span><strong style={{ color: '#53155a' }}>{r.en_proceso}</strong> en proceso</span>
                <span><strong style={{ color: '#c0392b' }}>{r.vencidos}</strong> vencidos</span>
                <span><strong style={{ color: '#b45309' }}>{r.por_vencer_7d}</strong> por vencer (≤7 días)</span>
                <span><strong style={{ color: '#2e7d50' }}>{r.concluidos}</strong> concluidos</span>
              </div>
              <div>
                {r.por_responsable.map((p) => (
                  <div
                    key={p.responsable.id}
                    style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 0', borderTop: '1px solid var(--pj-neutral-100)', fontSize: 13 }}
                  >
                    <Avatar nombre={p.responsable.nombre} size="md" />
                    <span style={{ flex: 1, minWidth: 0, fontWeight: 500 }}>{p.responsable.nombre}</span>
                    <span style={{ width: 110, flex: 'none', color: '#53155a', fontWeight: 600 }}>
                      {p.en_proceso} en proceso
                    </span>
                    <span style={{ width: 95, flex: 'none', color: p.vencidos > 0 ? '#c0392b' : 'var(--text-muted)', fontWeight: 600 }}>
                      {p.vencidos} {p.vencidos === 1 ? 'vencido' : 'vencidos'}
                    </span>
                    <span style={{ width: 110, flex: 'none', color: 'var(--text-secondary)' }}>
                      {p.por_vencer_7d} por vencer
                    </span>
                  </div>
                ))}
                {r.por_responsable.length === 0 && (
                  <div style={{ padding: '16px 0', fontSize: 13, color: 'var(--text-muted)' }}>
                    No hay acuerdos abiertos. 🎉
                  </div>
                )}
              </div>
              <p style={{ margin: '18px 0 0', fontSize: 11.5, lineHeight: 1.6, color: 'var(--text-muted)', textAlign: 'center' }}>
                Este correo se enviaría automáticamente a la dirección y a cada coordinación de área según la frecuencia configurada.
              </p>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
