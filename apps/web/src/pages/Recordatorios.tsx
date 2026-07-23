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
import { EmailModal } from '../components/EmailModal';
import { ResumenModal } from '../components/ResumenModal';
import { chipEnvio, tipoRecordatorioLabel } from '../components/recordatorioVm';
import { useSesion } from '../components/SessionContext';

export function Recordatorios() {
  const { sesion } = useSesion();
  const [emailRec, setEmailRec] = useState<RecordatorioVista | null>(null);
  const [verResumen, setVerResumen] = useState(false);

  const configQ = useQuery({ queryKey: ['config-recordatorios'], queryFn: () => api.getConfigRecordatorios() });
  const proximosQ = useQuery({ queryKey: ['recordatorios', 'proximos'], queryFn: () => api.listRecordatoriosProximos() });
  const historialQ = useQuery({ queryKey: ['recordatorios', 'historial'], queryFn: () => api.listRecordatoriosHistorial() });

  const rol = sesion?.usuario.rol;
  const cfg = configQ.data;
  const esquema = cfg
    ? `avisos ${cfg.dias_antes.join(', ')} días antes${cfg.dia_compromiso ? ', otro el día del compromiso' : ''} y seguimiento cada ${cfg.vencido_cada_dias} días al vencer (máx. ${cfg.vencido_max_repeticiones})`
    : 'esquema global en carga…';

  const fila = (r: RecordatorioVista, tile: 'proximo' | 'historial') => {
    const p = r.programado_para.split('-');
    const estadoChip = r.estado_envio ?? (r.enviado ? 'enviado' : 'programado');
    const c = chipEnvio(estadoChip);
    return (
      <div
        key={r.key}
        style={{ display: 'flex', alignItems: 'center', gap: 14, padding: '14px 18px', borderTop: '1px solid var(--border-subtle)' }}
      >
        <div className={`fecha-tile fecha-tile--${tile}`}>
          <div className="fecha-tile__dia">{+p[2]}</div>
          <div className="fecha-tile__mes">{MESES[+p[1] - 1].slice(0, 3)}</div>
        </div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 10.5, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.08em', color: 'var(--muted)', marginBottom: 3 }}>
            {tipoRecordatorioLabel(r.tipo, r.programado_para, r.fecha_compromiso)} · a {nombreCorto(r.destinatario.nombre)}
          </div>
          <div style={{ fontSize: 13, fontWeight: 500, lineHeight: 1.45 }}>{truncar(r.accion, 70)}</div>
        </div>
        <span title={r.error ?? undefined} className={c.className}>
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
      <div className="anim-in" style={{ display: 'flex', alignItems: 'flex-end', gap: 16, flexWrap: 'wrap', marginBottom: 28 }}>
        <div style={{ flex: 1, minWidth: 300 }}>
          <div className="section-header__eyebrow">Automatización</div>
          <h2 className="section-header__title">Recordatorios</h2>
          <p className="section-header__subtitle">
            Se generan automáticamente a partir de la fecha compromiso con el esquema global vigente: {esquema}. Envío
            simulado a las 9:00 h. Sección de administración: seguimiento de los avisos automáticos por correo.
          </p>
        </div>
        {(rol === 'direccion' || rol === 'coordinador') && (
          <button type="button" className="btn btn--ghost btn--md" style={{ flex: 'none' }} onClick={() => setVerResumen(true)}>
            Ver resumen {rol === 'direccion' ? 'general' : 'del área'}
          </button>
        )}
      </div>

      {(proximosQ.isError || historialQ.isError) && (
        <div className="alert alert--error" style={{ marginBottom: 16 }}>
          <div className="alert__body">{mensajeError(proximosQ.error ?? historialQ.error)}</div>
        </div>
      )}

      <div className="grid grid-cols-1 min-[901px]:grid-cols-[1fr_1fr] gap-[24px] items-start">
        <div className="anim-in anim-in--1">
          <h3 style={{ margin: '0 0 10px', fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 16, color: 'var(--text)' }}>
            Próximos envíos
          </h3>
          <div className="panel-card">
            {(proximosQ.data ?? []).map((r) => fila(r, 'proximo'))}
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
        <div className="anim-in anim-in--2">
          <h3 style={{ margin: '0 0 10px', fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 16, color: 'var(--text)' }}>
            Historial de envíos
          </h3>
          <div className="panel-card">
            {(historialQ.data ?? []).map((r) => fila(r, 'historial'))}
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
    </div>
  );
}
