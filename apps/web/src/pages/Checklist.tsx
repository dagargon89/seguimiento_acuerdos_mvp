/**
 * Checklist de validación (Dirección; coordinación filtrada a su área, ADR-012): acuerdos abiertos con
 * vencidos primero; permite concluir con nota opcional (confirmación inline).
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib';
import { fmtF } from '../lib/fechas';
import { EST, mensajeError, truncar, vencimientoRelativo } from '../components/EstadoHelpers';
import { Avatar } from '../components/Avatar';
import { Drawer } from '../components/Drawer';
import { RevisionBadge } from '../components/RevisionBadge';
import { useToast } from '../components/Toast';

export function Checklist() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [confirmando, setConfirmando] = useState<number | null>(null);
  const [nota, setNota] = useState('');
  const [selId, setSelId] = useState<number | null>(null);
  const [vista, setVista] = useState<'validar' | 'revision'>('validar');

  const checklistQ = useQuery({ queryKey: ['checklist', vista], queryFn: () => api.getChecklist(vista) });

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
    <div style={{ maxWidth: 1000, margin: '0 auto' }}>
      <div className="anim-in" style={{ marginBottom: 28 }}>
        <div className="section-header__eyebrow">Revisión y validación</div>
        <h2 className="section-header__title">Validaciones</h2>
        <p className="section-header__subtitle">
          {vista === 'validar'
            ? 'Dirección valida cualquier acuerdo; una coordinación valida los de su área. Revisa el último avance y concluye; al hacerlo desaparece del panel y se detienen sus recordatorios.'
            : 'Solicitudes de conclusión enviadas por responsables/corresponsables, pendientes de tu aprobación o rechazo. Abre el acuerdo para aprobar (concluir) o rechazar con motivo.'}
        </p>
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 18 }}>
        <button
          type="button"
          className={`btn btn--sm ${vista === 'validar' ? 'btn--accent' : 'btn--ghost'}`}
          onClick={() => setVista('validar')}
        >
          Por validar
        </button>
        <button
          type="button"
          className={`btn btn--sm ${vista === 'revision' ? 'btn--accent' : 'btn--ghost'}`}
          onClick={() => setVista('revision')}
        >
          Solicitudes de revisión
        </button>
      </div>

      {checklistQ.isError && (
        <div className="alert alert--error" style={{ marginBottom: 16 }}>
          <div className="alert__body">{mensajeError(checklistQ.error)}</div>
        </div>
      )}

      <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
        {items.map(({ acuerdo: a, total_avances, ultimo_avance }) => {
          const { rel, color } = vencimientoRelativo(a.fecha_compromiso, a.estado);
          const esVencido = a.estado === 'vencido';
          return (
            <div
              key={a.id}
              className="anim-in"
              style={{
                background: 'var(--surface)',
                border: `1px solid ${esVencido ? 'rgba(240,101,74,.35)' : 'var(--border)'}`,
                borderRadius: 14,
                padding: '20px 24px',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'flex-start', gap: 16 }}>
                <span
                  style={{
                    width: 9,
                    height: 9,
                    borderRadius: '50%',
                    flex: 'none',
                    marginTop: 7,
                    background: EST[a.estado].dot,
                    boxShadow: `0 0 8px ${EST[a.estado].dot}`,
                  }}
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
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                    <span className="tema-label">{a.tema ?? 'Sin tema'}</span>
                    <RevisionBadge estado={a.revision_estado} />
                  </div>
                  <div style={{ fontSize: 14, fontWeight: 500, lineHeight: 1.5 }}>{a.accion}</div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginTop: 8, flexWrap: 'wrap' }}>
                    <Avatar nombre={a.responsable.nombre} size="sm" />
                    <span style={{ fontSize: 12.5, color: 'var(--text2)' }}>{a.responsable.nombre}</span>
                    {a.corresponsables.map((c) => (
                      <Avatar key={c.id} nombre={c.nombre} size="sm" tono="blue" title={`Corresponsable: ${c.nombre}`} />
                    ))}
                    <span style={{ fontFamily: 'var(--font-display)', fontSize: 12.5, fontWeight: 600 }}>
                      · {fmtF(a.fecha_compromiso)}
                    </span>
                    <span style={{ fontSize: 12, fontWeight: 600, color }}>{rel}</span>
                    {a.enlaces.length > 0 && (
                      <a
                        href={a.enlaces[0]}
                        target="_blank"
                        rel="noreferrer"
                        onClick={(e) => e.stopPropagation()}
                        style={{ fontSize: 12, fontWeight: 600, color: 'var(--teal)' }}
                      >
                        {a.enlaces.length === 1 ? 'Producto ↗' : `${a.enlaces.length} productos ↗`}
                      </a>
                    )}
                  </div>
                  <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 6 }}>
                    {total_avances === 0
                      ? 'Sin avances registrados'
                      : `${total_avances} ${total_avances === 1 ? 'avance' : 'avances'} · último: ${truncar(ultimo_avance?.descripcion ?? '', 90)}`}
                  </div>
                </div>
                <div style={{ flex: 'none' }}>
                  {vista === 'validar' && confirmando !== a.id && (
                    <button
                      type="button"
                      className="btn btn--accent"
                      style={{ padding: '10px 18px', fontSize: 12.5 }}
                      onClick={() => {
                        setConfirmando(a.id);
                        setNota('');
                      }}
                    >
                      Concluir
                    </button>
                  )}
                  {vista === 'revision' && (
                    <button
                      type="button"
                      className="btn btn--ghost-teal btn--sm"
                      onClick={() => setSelId(a.id)}
                    >
                      Revisar →
                    </button>
                  )}
                </div>
              </div>
              {confirmando === a.id && (
                <div
                  style={{
                    marginTop: 12,
                    marginLeft: 25,
                    padding: '14px 16px',
                    background: 'var(--surface2)',
                    border: '1px solid var(--border)',
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
          <div className="panel-card" style={{ padding: 28, fontSize: 13, color: 'var(--muted)', textAlign: 'center' }}>
            {vista === 'validar' ? 'No hay acuerdos pendientes de validar.' : 'No hay solicitudes de revisión pendientes.'}
          </div>
        )}
        {checklistQ.isLoading && (
          <div className="panel-card" style={{ padding: 28, fontSize: 13, color: 'var(--muted)', textAlign: 'center' }}>
            Cargando…
          </div>
        )}
      </div>

      {selId !== null && <Drawer id={selId} onClose={() => setSelId(null)} />}
    </div>
  );
}
