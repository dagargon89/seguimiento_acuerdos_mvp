/**
 * Vista previa del correo de un recordatorio (1:1 con emailVm()/renderEmail()
 * del demo, dirigido al destinatario del recordatorio).
 */
import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../lib';
import type { RecordatorioVista } from '../lib';
import { fmtF, fmtL } from '../lib/fechas';
import { EST, nombreCorto } from './EstadoHelpers';

interface EmailModalProps {
  rec: RecordatorioVista;
  onClose: () => void;
}

export function EmailModal({ rec, onClose }: EmailModalProps) {
  const detalleQ = useQuery({
    queryKey: ['acuerdo', rec.acuerdo_id],
    queryFn: () => api.getAcuerdo(rec.acuerdo_id!),
    enabled: rec.acuerdo_id !== null,
  });

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  const a = detalleQ.data ?? null;

  let titulo: string;
  let subject: string;
  let intro: string;
  if (rec.tipo === 'previo') {
    titulo = 'Recordatorio de acuerdo';
    subject = `Recordatorio: ${rec.accion}`;
    intro = `Te recordamos que el siguiente acuerdo vence el ${rec.fecha_compromiso ? fmtL(rec.fecha_compromiso) : '—'}. Si ya está resuelto, registra el avance en el panel.`;
  } else if (rec.tipo === 'dia') {
    titulo = 'Hoy vence un acuerdo';
    subject = `Vence hoy: ${rec.accion}`;
    intro = 'Hoy es la fecha compromiso del siguiente acuerdo. Registra el avance en el panel para que Dirección pueda validarlo.';
  } else if (rec.tipo === 'vencido') {
    titulo = 'Acuerdo vencido';
    subject = `Seguimiento: acuerdo vencido — ${rec.tema ?? rec.accion}`;
    intro = 'El siguiente acuerdo venció y sigue abierto. Registra un avance o reprograma la fecha compromiso con el equipo.';
  } else if (rec.tipo === 'solicitud_avance') {
    titulo = 'Solicitud de avances';
    subject = 'Solicitud de avances: registra el estado de tus acuerdos';
    intro =
      'Te pedimos registrar el avance de los acuerdos abiertos que tienes asignados. Actualiza cada uno en el panel para que Dirección tenga el estado al día.';
  } else {
    titulo = 'Resumen periódico de pendientes';
    subject = 'Resumen periódico: acuerdos abiertos';
    intro = 'Este es el resumen periódico de los acuerdos abiertos que te corresponden, ordenados por fecha compromiso.';
  }

  const est = a ? EST[a.estado] : null;

  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 120, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div className="overlay-backdrop" style={{ position: 'fixed' }} onClick={onClose} />
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Vista previa del correo"
        className="modal-card"
        style={{
          width: 640,
          maxWidth: '92vw',
          maxHeight: '86vh',
          overflowY: 'auto',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, padding: '18px 22px', borderBottom: '1px solid var(--border)' }}>
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 10.5, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.14em', color: 'var(--text-muted)', marginBottom: 5 }}>
              Simulación del correo que llegaría
            </div>
            <div style={{ fontSize: 16, fontWeight: 600, lineHeight: 1.35 }}>{subject}</div>
          </div>
          <button type="button" className="modal__close" onClick={onClose} aria-label="Cerrar">
            ✕
          </button>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '14px 22px', borderBottom: '1px solid var(--border-subtle)' }}>
          <span
            style={{
              width: 36,
              height: 36,
              borderRadius: '50%',
              background: 'var(--teal)',
              color: 'var(--on-teal)',
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
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>para {rec.destinatario.email}</div>
          </div>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', flex: 'none' }}>{fmtF(rec.programado_para)} · 9:00</div>
        </div>
        <div style={{ padding: '0 0 26px' }}>
          <div
            style={{
              background:
                'linear-gradient(135deg, rgba(47,191,165,.14), rgba(91,157,245,.08)), var(--sidebar-bg)',
              padding: '20px 26px',
            }}
          >
            <img
              src="/assets/logo-horizontal-white.png"
              alt="Participa Juárez"
              style={{ height: 26, display: 'block', marginBottom: 12 }}
            />
            <div style={{ fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 19, color: '#e9ecf2' }}>{titulo}</div>
          </div>
          <div style={{ padding: '22px 26px 0' }}>
            <p style={{ margin: '0 0 12px', fontSize: 14 }}>Hola, {nombreCorto(rec.destinatario.nombre)}:</p>
            <p style={{ margin: '0 0 18px', fontSize: 13.5, lineHeight: 1.65, color: 'var(--text-secondary)' }}>{intro}</p>
            {rec.acuerdo_id !== null && (
              <div
                style={{
                  background: 'var(--surface2)',
                  border: '1px solid var(--border)',
                  borderRadius: 10,
                  padding: '16px 18px',
                  display: 'flex',
                  flexDirection: 'column',
                  gap: 9,
                  marginBottom: 22,
                }}
              >
                <FilaEmail label="Acuerdo" valor={rec.accion} negrita />
                <FilaEmail label="Tema" valor={rec.tema ?? 'Sin tema'} />
                <FilaEmail label="Responsable" valor={a ? a.responsable.nombre : '…'} />
                <FilaEmail
                  label="Fecha compromiso"
                  valor={rec.fecha_compromiso ? fmtL(rec.fecha_compromiso) : '—'}
                  negrita
                />
                <div className="max-sm:flex-wrap" style={{ display: 'flex', gap: 10, fontSize: 13 }}>
                  <span style={{ width: 130, flex: 'none', fontWeight: 600, color: 'var(--text-muted)' }}>Estado actual</span>
                  <span style={{ fontWeight: 600, color: est ? est.color : 'var(--text-secondary)' }}>
                    {est ? est.label : '…'}
                  </span>
                </div>
              </div>
            )}
            <div style={{ textAlign: 'center', marginBottom: 22 }}>
              <span
                style={{
                  display: 'inline-block',
                  background: 'var(--teal)',
                  color: 'var(--on-teal)',
                  fontWeight: 600,
                  fontSize: 13.5,
                  padding: '11px 26px',
                  borderRadius: 10,
                }}
              >
                Abrir panel de seguimiento
              </span>
            </div>
            <p style={{ margin: 0, fontSize: 11.5, lineHeight: 1.6, color: 'var(--text-muted)', textAlign: 'center' }}>
              Este correo se generó automáticamente a partir del Formato de Reunión Operativa.
              <br />
              Si el acuerdo ya se cumplió, registra el avance en el panel para detener los recordatorios.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}

function FilaEmail({ label, valor, negrita = false }: { label: string; valor: string; negrita?: boolean }) {
  return (
    <div className="max-sm:flex-wrap" style={{ display: 'flex', gap: 10, fontSize: 13 }}>
      <span style={{ width: 130, flex: 'none', fontWeight: 600, color: 'var(--text-muted)' }}>{label}</span>
      <span style={{ fontWeight: negrita ? 600 : 400 }}>{valor}</span>
    </div>
  );
}
