/**
 * Recordatorios 1:1 con el demo: dos columnas (Próximos envíos / Historial),
 * vista previa de correo, resumen periódico (Dirección/Coordinación) y
 * configuración del esquema global (solo Dirección).
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../lib';
import type { RecordatorioVista } from '../lib';
import { MESES } from '../lib/fechas';
import { mensajeError, nombreCorto, truncar } from '../components/EstadoHelpers';
import { ConfigRecordatoriosModal } from '../components/ConfigRecordatoriosModal';
import { EmailModal } from '../components/EmailModal';
import { ResumenModal } from '../components/ResumenModal';
import { chipEnvio, tipoRecordatorioLabel } from '../components/recordatorioVm';
import { useSesion } from '../components/SessionContext';

export function Recordatorios() {
  const { sesion } = useSesion();
  const [emailRec, setEmailRec] = useState<RecordatorioVista | null>(null);
  const [verResumen, setVerResumen] = useState(false);
  const [verConfig, setVerConfig] = useState(false);

  const configQ = useQuery({ queryKey: ['config-recordatorios'], queryFn: () => api.getConfigRecordatorios() });
  const proximosQ = useQuery({ queryKey: ['recordatorios', 'proximos'], queryFn: () => api.listRecordatoriosProximos() });
  const historialQ = useQuery({ queryKey: ['recordatorios', 'historial'], queryFn: () => api.listRecordatoriosHistorial() });

  const rol = sesion?.usuario.rol;
  const cfg = configQ.data;
  const esquema = cfg
    ? `avisos ${cfg.dias_antes.join(', ')} días antes${cfg.dia_compromiso ? ', otro el día del compromiso' : ''} y seguimiento cada ${cfg.vencido_cada_dias} días al vencer (máx. ${cfg.vencido_max_repeticiones})`
    : 'esquema global en carga…';

  const fila = (r: RecordatorioVista, colorDia: string) => {
    const p = r.programado_para.split('-');
    const estadoChip = r.estado_envio ?? (r.enviado ? 'enviado' : 'programado');
    const c = chipEnvio(estadoChip);
    return (
      <div
        key={r.key}
        style={{ display: 'flex', alignItems: 'center', gap: 14, padding: '13px 16px', borderTop: '1px solid var(--pj-neutral-100)' }}
      >
        <div style={{ width: 48, flex: 'none', textAlign: 'center' }}>
          <div style={{ fontSize: 17, fontWeight: 600, color: colorDia, lineHeight: 1.1 }}>{+p[2]}</div>
          <div style={{ fontSize: 9.5, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.12em', color: 'var(--text-muted)' }}>
            {MESES[+p[1] - 1].slice(0, 3)}
          </div>
        </div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 10.5, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.1em', color: 'var(--text-muted)', marginBottom: 3 }}>
            {tipoRecordatorioLabel(r.tipo, r.programado_para, r.fecha_compromiso)} · a {nombreCorto(r.destinatario.nombre)}
          </div>
          <div style={{ fontSize: 13, fontWeight: 500, lineHeight: 1.4 }}>{truncar(r.accion, 70)}</div>
        </div>
        <span
          title={r.error ?? undefined}
          style={{
            flex: 'none',
            fontSize: 10.5,
            fontWeight: 600,
            padding: '4px 10px',
            borderRadius: 999,
            background: c.bg,
            color: c.color,
          }}
        >
          {c.label}
        </span>
        <button type="button" className="btn btn--ghost btn--sm" onClick={() => setEmailRec(r)}>
          Ver correo
        </button>
      </div>
    );
  };

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 16, flexWrap: 'wrap', marginBottom: 24 }}>
        <div style={{ flex: 1, minWidth: 300 }}>
          <div className="section-header__eyebrow">Automatización</div>
          <h2 className="section-header__title">Recordatorios</h2>
          <p className="section-header__subtitle">
            Se generan automáticamente a partir de la fecha compromiso con el esquema global vigente: {esquema}. Envío
            simulado a las 9:00 h. Cada persona ve únicamente los recordatorios de los acuerdos que le corresponden.
          </p>
        </div>
        {(rol === 'direccion' || rol === 'coordinador') && (
          <button type="button" className="btn btn--ghost btn--md" style={{ flex: 'none' }} onClick={() => setVerResumen(true)}>
            Ver resumen {rol === 'direccion' ? 'general' : 'del área'}
          </button>
        )}
        {rol === 'direccion' && (
          <button type="button" className="btn btn--accent btn--md" style={{ flex: 'none' }} onClick={() => setVerConfig(true)}>
            Configurar avisos
          </button>
        )}
      </div>

      {(proximosQ.isError || historialQ.isError) && (
        <div className="alert alert--error" style={{ marginBottom: 16 }}>
          <div className="alert__body">{mensajeError(proximosQ.error ?? historialQ.error)}</div>
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24, alignItems: 'start' }}>
        <div>
          <h3 style={{ margin: '0 0 10px', fontFamily: 'var(--font-display)', fontWeight: 500, fontSize: 16, color: 'var(--text-brand)' }}>
            Próximos envíos
          </h3>
          <div className="panel-card">
            {(proximosQ.data ?? []).map((r) => fila(r, 'var(--pj-purple-700)'))}
            {proximosQ.data?.length === 0 && (
              <div style={{ padding: 24, fontSize: 13, color: 'var(--text-muted)', textAlign: 'center' }}>
                No hay recordatorios programados.
              </div>
            )}
            {proximosQ.isLoading && (
              <div style={{ padding: 24, fontSize: 13, color: 'var(--text-muted)', textAlign: 'center' }}>Cargando…</div>
            )}
          </div>
        </div>
        <div>
          <h3 style={{ margin: '0 0 10px', fontFamily: 'var(--font-display)', fontWeight: 500, fontSize: 16, color: 'var(--text-brand)' }}>
            Historial de envíos
          </h3>
          <div className="panel-card">
            {(historialQ.data ?? []).map((r) => fila(r, 'var(--text-secondary)'))}
            {historialQ.data?.length === 0 && (
              <div style={{ padding: 24, fontSize: 13, color: 'var(--text-muted)', textAlign: 'center' }}>
                Aún no se ha enviado ningún recordatorio.
              </div>
            )}
            {historialQ.isLoading && (
              <div style={{ padding: 24, fontSize: 13, color: 'var(--text-muted)', textAlign: 'center' }}>Cargando…</div>
            )}
          </div>
        </div>
      </div>

      {emailRec && <EmailModal rec={emailRec} onClose={() => setEmailRec(null)} />}
      {verResumen && <ResumenModal onClose={() => setVerResumen(false)} />}
      {verConfig && cfg && <ConfigRecordatoriosModal config={cfg} onClose={() => setVerConfig(false)} />}
    </div>
  );
}
