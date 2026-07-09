/**
 * Checklist de validación (nuevo, solo Dirección): acuerdos abiertos con
 * vencidos primero; permite concluir con nota opcional (confirmación inline).
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib';
import { fmtF } from '../lib/fechas';
import { EST, mensajeError, truncar, vencimientoRelativo } from '../components/EstadoHelpers';
import { Avatar } from '../components/Avatar';
import { Drawer } from '../components/Drawer';
import { useToast } from '../components/Toast';

export function Checklist() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [confirmando, setConfirmando] = useState<number | null>(null);
  const [nota, setNota] = useState('');
  const [selId, setSelId] = useState<number | null>(null);

  const checklistQ = useQuery({ queryKey: ['checklist'], queryFn: () => api.getChecklist() });

  const concluirMut = useMutation({
    mutationFn: ({ id, nota: n }: { id: number; nota: string }) => api.concluirAcuerdo(id, n),
    onSuccess: () => {
      toast('El acuerdo se marcó como concluido y saldrá del panel.');
      setConfirmando(null);
      setNota('');
      void queryClient.invalidateQueries({ queryKey: ['checklist'] });
      void queryClient.invalidateQueries({ queryKey: ['acuerdos'] });
      void queryClient.invalidateQueries({ queryKey: ['calendario'] });
      void queryClient.invalidateQueries({ queryKey: ['recordatorios'] });
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

  const items = checklistQ.data ?? [];

  return (
    <div style={{ maxWidth: 980, margin: '0 auto' }}>
      <div style={{ marginBottom: 24 }}>
        <div className="section-header__eyebrow">Validación de acuerdos · solo Dirección</div>
        <h2 className="section-header__title">Checklist de validación</h2>
        <p className="section-header__subtitle">
          Solo Dirección puede marcar un acuerdo como concluido. Revisa el último avance de cada compromiso y
          valídalo; al concluirlo desaparece del panel y se detienen sus recordatorios.
        </p>
      </div>

      {checklistQ.isError && (
        <div className="alert alert--error" style={{ marginBottom: 16 }}>
          <div className="alert__body">{mensajeError(checklistQ.error)}</div>
        </div>
      )}

      <div className="panel-card">
        {items.map(({ acuerdo: a, total_avances, ultimo_avance }) => {
          const { rel, color } = vencimientoRelativo(a.fecha_compromiso, a.estado);
          const esVencido = a.estado === 'vencido';
          return (
            <div
              key={a.id}
              style={{
                borderTop: '1px solid var(--pj-neutral-100)',
                padding: '14px 18px',
                background: esVencido ? 'var(--status-error-bg)' : undefined,
              }}
            >
              <div style={{ display: 'flex', alignItems: 'flex-start', gap: 14 }}>
                <span
                  style={{ width: 8, height: 8, borderRadius: '50%', flex: 'none', marginTop: 6, background: EST[a.estado].dot }}
                />
                <div
                  style={{ flex: 1, minWidth: 0, cursor: 'pointer' }}
                  onClick={() => setSelId(a.id)}
                  role="button"
                  tabIndex={0}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') setSelId(a.id);
                  }}
                >
                  <div className="tema-label" style={{ display: 'block', marginBottom: 3 }}>
                    {a.tema ?? 'Sin tema'}
                  </div>
                  <div style={{ fontSize: 13.5, fontWeight: 500, lineHeight: 1.45 }}>{a.accion}</div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 6, flexWrap: 'wrap' }}>
                    <Avatar nombre={a.responsable.nombre} size="sm" />
                    <span style={{ fontSize: 12, color: 'var(--text-secondary)' }}>{a.responsable.nombre}</span>
                    {a.corresponsables.map((c) => (
                      <Avatar key={c.id} nombre={c.nombre} size="sm" title={`Corresponsable: ${c.nombre}`} />
                    ))}
                    <span style={{ fontSize: 12, fontWeight: 500 }}>· {fmtF(a.fecha_compromiso)}</span>
                    <span style={{ fontSize: 12, fontWeight: 600, color }}>{rel}</span>
                    {a.enlace && (
                      <a
                        href={a.enlace}
                        target="_blank"
                        rel="noreferrer"
                        onClick={(e) => e.stopPropagation()}
                        style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-link)' }}
                      >
                        Producto ↗
                      </a>
                    )}
                  </div>
                  <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 4 }}>
                    {total_avances === 0
                      ? 'Sin avances registrados'
                      : `${total_avances} ${total_avances === 1 ? 'avance' : 'avances'} · último: ${truncar(ultimo_avance?.descripcion ?? '', 90)}`}
                  </div>
                </div>
                <div style={{ flex: 'none' }}>
                  {confirmando !== a.id && (
                    <button
                      type="button"
                      className="btn btn--accent btn--sm"
                      onClick={() => {
                        setConfirmando(a.id);
                        setNota('');
                      }}
                    >
                      Concluir
                    </button>
                  )}
                </div>
              </div>
              {confirmando === a.id && (
                <div
                  style={{
                    marginTop: 12,
                    marginLeft: 22,
                    padding: '14px 16px',
                    background: '#ffffff',
                    border: '1px solid var(--border-default)',
                    borderRadius: 10,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 10,
                  }}
                >
                  <div className="field">
                    <label className="field__label" htmlFor={`nota-${a.id}`}>
                      Nota de validación (opcional)
                    </label>
                    <textarea
                      id={`nota-${a.id}`}
                      className="textarea"
                      style={{ minHeight: 56 }}
                      placeholder="Ej. Producto revisado y publicado."
                      value={nota}
                      onChange={(e) => setNota(e.target.value)}
                    />
                  </div>
                  <div style={{ display: 'flex', gap: 10 }}>
                    <button
                      type="button"
                      className="btn btn--accent btn--sm"
                      disabled={concluirMut.isPending}
                      onClick={() => concluirMut.mutate({ id: a.id, nota })}
                    >
                      {concluirMut.isPending ? 'Guardando…' : 'Confirmar conclusión'}
                    </button>
                    <button type="button" className="btn btn--ghost btn--sm" onClick={() => setConfirmando(null)}>
                      Cancelar
                    </button>
                  </div>
                </div>
              )}
            </div>
          );
        })}
        {items.length === 0 && !checklistQ.isLoading && (
          <div style={{ padding: 28, fontSize: 13, color: 'var(--text-muted)', textAlign: 'center' }}>
            No hay acuerdos pendientes de validar.
          </div>
        )}
        {checklistQ.isLoading && (
          <div style={{ padding: 28, fontSize: 13, color: 'var(--text-muted)', textAlign: 'center' }}>Cargando…</div>
        )}
      </div>

      {selId !== null && <Drawer id={selId} onClose={() => setSelId(null)} />}
    </div>
  );
}
