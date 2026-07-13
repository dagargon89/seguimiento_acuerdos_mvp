/**
 * Drawer de detalle de acuerdo (1:1 con renderDrawer del demo) más las
 * funciones nuevas: corresponsables, historial de avances, registrar avance /
 * reprogramar, y concluir/reabrir (solo Dirección).
 */
import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib';
import type { AcuerdoDetalle, Avance } from '../lib';
import { fmtF, fmtL, hoyISO, shiftISO } from '../lib/fechas';
import { EST, mensajeError, vencimientoRelativo } from './EstadoHelpers';
import { Avatar } from './Avatar';
import { Badge } from './Badge';
import { chipEnvio, tipoRecordatorioLabel } from './recordatorioVm';
import { useSesion } from './SessionContext';
import { useToast } from './Toast';

const TIPO_AVANCE_LABEL: Record<Avance['tipo'], string> = {
  avance: 'Avance',
  reprogramacion: 'Reprogramación',
  validacion: 'Validación',
  reapertura: 'Reapertura',
};

interface DrawerProps {
  id: number;
  onClose: () => void;
}

export function Drawer({ id, onClose }: DrawerProps) {
  const { sesion } = useSesion();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const panelRef = useRef<HTMLDivElement>(null);

  const [descripcion, setDescripcion] = useState('');
  const [reprogramar, setReprogramar] = useState(false);
  const [nuevaFecha, setNuevaFecha] = useState('');

  const detalleQ = useQuery({
    queryKey: ['acuerdo', id],
    queryFn: () => api.getAcuerdo(id),
  });

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    panelRef.current?.focus();
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  const invalidar = () => {
    void queryClient.invalidateQueries({ queryKey: ['acuerdo', id] });
    void queryClient.invalidateQueries({ queryKey: ['acuerdos'] });
    void queryClient.invalidateQueries({ queryKey: ['calendario'] });
    void queryClient.invalidateQueries({ queryKey: ['checklist'] });
    void queryClient.invalidateQueries({ queryKey: ['recordatorios'] });
  };

  const avanceMut = useMutation({
    mutationFn: () =>
      api.registrarAvance(id, {
        descripcion,
        nueva_fecha: reprogramar && nuevaFecha ? nuevaFecha : null,
      }),
    onSuccess: () => {
      toast(reprogramar ? 'Avance registrado y fecha compromiso reprogramada.' : 'Avance registrado.');
      setDescripcion('');
      setReprogramar(false);
      setNuevaFecha('');
      invalidar();
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

  const concluirMut = useMutation({
    mutationFn: (nota: string) => api.concluirAcuerdo(id, nota),
    onSuccess: () => {
      toast('El acuerdo se marcó como concluido y saldrá del panel.');
      invalidar();
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

  const reabrirMut = useMutation({
    mutationFn: (nota: string) => api.reabrirAcuerdo(id, nota),
    onSuccess: () => {
      toast('El acuerdo se reabrió y vuelve al panel.');
      invalidar();
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

  const concluir = () => {
    const nota = window.prompt('Nota de validación (opcional):', '');
    if (nota === null) return;
    concluirMut.mutate(nota);
  };

  const reabrir = () => {
    const nota = window.prompt('Nota de reapertura (obligatoria):', '');
    if (nota === null) return;
    reabrirMut.mutate(nota);
  };

  const sel: AcuerdoDetalle | undefined = detalleQ.data;
  const esDireccion = sesion?.usuario.rol === 'direccion';

  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 100 }}>
      <div className="overlay-backdrop" onClick={onClose} />
      <div
        ref={panelRef}
        className="drawer"
        role="dialog"
        aria-modal="true"
        aria-label="Detalle del acuerdo"
        tabIndex={-1}
      >
        <div className="drawer__head">
          <button type="button" className="drawer__close" onClick={onClose} aria-label="Cerrar">
            ✕
          </button>
          <div className="drawer__eyebrow">Detalle del acuerdo</div>
          <div className="drawer__titulo">{sel ? sel.tema ?? 'Sin tema' : 'Cargando…'}</div>
        </div>

        {detalleQ.isError && (
          <div style={{ padding: '26px 30px' }}>
            <div className="alert alert--error">
              <div className="alert__body">{mensajeError(detalleQ.error)}</div>
            </div>
          </div>
        )}

        {sel && (
          <div style={{ padding: '26px 30px', display: 'flex', flexDirection: 'column', gap: 22 }}>
            {(() => {
              const est = EST[sel.estado];
              const { rel, color: relColor } = vencimientoRelativo(sel.fecha_compromiso, sel.estado);
              return (
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <Badge variant={est.variant} size="md" label={est.label} />
                  <span style={{ fontSize: 12.5, fontWeight: 600, color: relColor }}>{rel}</span>
                </div>
              );
            })()}

            <div>
              <div className="detail-label">Acuerdo / acción</div>
              <div style={{ fontSize: 15, fontWeight: 500, lineHeight: 1.6 }}>{sel.accion}</div>
            </div>

            <div>
              <div className="detail-label">Responsable</div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <Avatar nombre={sel.responsable.nombre} size="lg" />
                <div>
                  <div style={{ fontSize: 13.5, fontWeight: 600 }}>{sel.responsable.nombre}</div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{sel.responsable.email}</div>
                </div>
              </div>
            </div>

            <div>
              <div className="detail-label">Corresponsables</div>
              {sel.corresponsables.length === 0 ? (
                <div style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Sin corresponsables.</div>
              ) : (
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                  {sel.corresponsables.map((c) => (
                    <span
                      key={c.id}
                      title={c.email}
                      style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 6,
                        background: 'rgba(91,157,245,.14)',
                        color: 'var(--blue)',
                        border: '1px solid rgba(91,157,245,.25)',
                        borderRadius: 999,
                        padding: '3px 10px 3px 4px',
                        fontSize: 12,
                        fontWeight: 600,
                      }}
                    >
                      <Avatar nombre={c.nombre} size="sm" tono="blue" />
                      {c.nombre}
                    </span>
                  ))}
                </div>
              )}
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <div>
                <div className="detail-label">Fecha compromiso</div>
                <div style={{ fontSize: 13.5, fontWeight: 500 }}>{fmtL(sel.fecha_compromiso)}</div>
              </div>
              <div>
                <div className="detail-label">Reunión de origen</div>
                <div style={{ fontSize: 13.5, fontWeight: 500 }}>{sel.reunion.nombre}</div>
              </div>
              <div>
                <div className="detail-label">Área</div>
                <div style={{ fontSize: 13.5, fontWeight: 500 }}>{sel.area.nombre}</div>
              </div>
              <div>
                <div className="detail-label">Capturado por</div>
                <div style={{ fontSize: 13.5, fontWeight: 500 }}>{sel.capturado_por.nombre}</div>
              </div>
            </div>

            <div>
              <div className="detail-label">Enlace a productos</div>
              {sel.enlace ? (
                <a
                  href={sel.enlace}
                  target="_blank"
                  rel="noreferrer"
                  style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--text-link)' }}
                >
                  {sel.enlace}
                </a>
              ) : (
                <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--text-muted)' }}>Sin enlace registrado</div>
              )}
            </div>

            <div>
              <div className="detail-label">Observaciones</div>
              <div style={{ fontSize: 13.5, lineHeight: 1.55, color: 'var(--text-secondary)' }}>
                {sel.observaciones ?? 'Sin observaciones.'}
              </div>
            </div>

            <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
              <div className="detail-label" style={{ marginBottom: 8 }}>
                Recordatorios de este acuerdo
              </div>
              {sel.recordatorios.map((r) => {
                const c = chipEnvio(r.estado);
                return (
                  <div
                    key={`${r.tipo}-${r.programado_para}`}
                    style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '7px 0', fontSize: 12.5 }}
                  >
                    <span style={{ width: 70, flex: 'none', fontWeight: 600, color: 'var(--text-secondary)' }}>
                      {fmtF(r.programado_para)}
                    </span>
                    <span style={{ flex: 1, color: 'var(--text-secondary)' }}>
                      {tipoRecordatorioLabel(r.tipo, r.programado_para, sel.fecha_compromiso)}
                    </span>
                    <span className={c.className} style={{ fontSize: 10, padding: '3px 9px' }}>
                      {c.label}
                    </span>
                  </div>
                );
              })}
              {sel.recordatorios.length === 0 && (
                <div style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>
                  Sin recordatorios — el acuerdo está concluido.
                </div>
              )}
            </div>

            <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
              <div className="detail-label" style={{ marginBottom: 10 }}>
                Historial de avances
              </div>
              {sel.avances.length === 0 && (
                <div style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Aún no hay avances registrados.</div>
              )}
              {sel.avances.map((av) => (
                <div key={av.id} style={{ padding: '10px 0', borderTop: '1px solid var(--border-subtle)' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                    <span
                      style={{
                        fontFamily: 'var(--font-display)',
                        fontSize: 10,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '.08em',
                        color: 'var(--teal)',
                      }}
                    >
                      {TIPO_AVANCE_LABEL[av.tipo]}
                    </span>
                    <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                      {av.usuario.nombre} · {fmtF(av.created_at.slice(0, 10))}
                    </span>
                  </div>
                  <div style={{ fontSize: 13, lineHeight: 1.5, color: 'var(--text-secondary)' }}>{av.descripcion}</div>
                  {av.nueva_fecha && (
                    <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-brand)', marginTop: 3 }}>
                      Nueva fecha compromiso: {fmtL(av.nueva_fecha)}
                    </div>
                  )}
                </div>
              ))}
            </div>

            {sel.estado !== 'concluido' && (
              <form
                style={{
                  borderTop: '1px solid var(--border)',
                  paddingTop: 20,
                  display: 'flex',
                  flexDirection: 'column',
                  gap: 12,
                }}
                onSubmit={(e) => {
                  e.preventDefault();
                  if (!descripcion.trim()) {
                    toast('Describe el avance.', 'error');
                    return;
                  }
                  avanceMut.mutate();
                }}
              >
                <div className="field">
                  <label className="field__label" htmlFor="avance-desc">
                    Registrar avance
                  </label>
                  <textarea
                    id="avance-desc"
                    className="textarea"
                    style={{ minHeight: 72 }}
                    placeholder="Qué se avanzó o qué falta"
                    value={descripcion}
                    onChange={(e) => setDescripcion(e.target.value)}
                  />
                </div>
                <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, fontWeight: 500, cursor: 'pointer' }}>
                  <input
                    type="checkbox"
                    checked={reprogramar}
                    onChange={(e) => setReprogramar(e.target.checked)}
                  />
                  Reprogramar fecha compromiso
                </label>
                {reprogramar && (
                  <div className="field">
                    <label className="field__label" htmlFor="avance-fecha">
                      Nueva fecha compromiso
                    </label>
                    <input
                      id="avance-fecha"
                      className="input"
                      type="date"
                      min={shiftISO(hoyISO(), 1)}
                      value={nuevaFecha}
                      onChange={(e) => setNuevaFecha(e.target.value)}
                    />
                  </div>
                )}
                <button type="submit" className="btn btn--accent btn--md btn--full" disabled={avanceMut.isPending}>
                  {avanceMut.isPending ? 'Guardando…' : 'Guardar avance'}
                </button>
              </form>
            )}

            {esDireccion && (
              <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
                {sel.estado !== 'concluido' ? (
                  <button
                    type="button"
                    className="btn btn--ghost-teal btn--md btn--full"
                    onClick={concluir}
                    disabled={concluirMut.isPending}
                  >
                    Marcar como concluido
                  </button>
                ) : (
                  <button
                    type="button"
                    className="btn btn--ghost btn--md btn--full"
                    onClick={reabrir}
                    disabled={reabrirMut.isPending}
                  >
                    Reabrir acuerdo
                  </button>
                )}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
