/**
 * Drawer de detalle de acuerdo (1:1 con renderDrawer del demo) más las
 * funciones nuevas: corresponsables, historial de avances, registrar avance /
 * reprogramar, y concluir/reabrir (solo Dirección).
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib';
import type { AcuerdoDetalle, EdicionAcuerdo, TipoEventoActividad } from '../lib';
import { fmtF, fmtL, hoyISO, shiftISO } from '../lib/fechas';
import { EST, mensajeError, revisionMeta, vencimientoRelativo } from './EstadoHelpers';
import { Avatar } from './Avatar';
import { Badge } from './Badge';
import { CorresponsablesPicker } from './CorresponsablesPicker';
import { DatePicker } from './DatePicker';
import { EditorHtml } from './EditorHtml';
import { EnlacesInput } from './EnlacesInput';
import { RichText } from './RichText';
import { Select } from './Select';
import { chipEnvio, tipoRecordatorioLabel } from './recordatorioVm';
import { useSesion } from './SessionContext';
import { useToast } from './Toast';

// Etiqueta y acento de color por tipo de evento de la bitácora. Progreso con
// tokens de estado PJ (regla #11): teal = avance/validación, ámbar = reprogramación,
// rojo = reapertura. Eventos administrativos (crear/editar/corresponsables) en
// color neutro para distinguirlos del progreso.
const TIPO_EVENTO_META: Record<TipoEventoActividad, { label: string; color: string }> = {
  avance:          { label: 'Avance',           color: 'var(--teal)' },
  reprogramacion:  { label: 'Reprogramación',   color: 'var(--amber)' },
  validacion:      { label: 'Validación',       color: 'var(--teal)' },
  reapertura:      { label: 'Reapertura',       color: 'var(--red)' },
  crear:           { label: 'Creación',         color: 'var(--text-muted)' },
  editar:          { label: 'Edición',          color: 'var(--text-muted)' },
  corresponsables: { label: 'Corresponsables',  color: 'var(--text-muted)' },
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

  // Edición estructural (ADR-011) y eliminación (solo Dirección).
  const [editando, setEditando] = useState(false);
  const [confirmandoEliminar, setConfirmandoEliminar] = useState(false);
  const [form, setForm] = useState({
    tema: '',
    accion: '',
    responsable_id: '',
    area_id: '',
    enlaces: [] as string[],
    observaciones: '',
    corresponsables: [] as number[],
  });

  const detalleQ = useQuery({
    queryKey: ['acuerdo', id],
    queryFn: () => api.getAcuerdo(id),
  });
  const usuariosQ = useQuery({ queryKey: ['usuarios'], queryFn: () => api.listUsuarios(), enabled: editando });
  const areasQ = useQuery({ queryKey: ['areas'], queryFn: () => api.listAreas(), enabled: editando });
  const actividadQ = useQuery({
    queryKey: ['actividad', id],
    queryFn: () => api.actividadAcuerdo(id),
    enabled: id > 0,
  });

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    panelRef.current?.focus();
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  const sel: AcuerdoDetalle | undefined = detalleQ.data;
  const u = sesion?.usuario;
  const esDireccion = u?.rol === 'direccion';
  // Edición ESTRUCTURAL (responsable/área/corresponsables): Dirección, coordinación
  // del área o quien capturó (ADR-011); el backend (`puedeEditarEstructura`) exige
  // exactamente esta misma condición, tanto para PATCH /acuerdos/{id} (campos
  // responsable_id/area_id) como para PUT /acuerdos/{id}/corresponsables.
  const puedeEditarEstructura =
    sel !== undefined &&
    u !== undefined &&
    (esDireccion || u.id === sel.capturado_por.id || (u.rol === 'coordinador' && u.area_id === sel.area.id));
  // Participante = responsable o corresponsable del acuerdo.
  const esParticipante =
    sel !== undefined &&
    u !== undefined &&
    (u.id === sel.responsable.id || sel.corresponsables.some((c) => c.id === u.id));

  const invalidar = () => {
    void queryClient.invalidateQueries({ queryKey: ['acuerdo', id] });
    void queryClient.invalidateQueries({ queryKey: ['acuerdos'] });
    void queryClient.invalidateQueries({ queryKey: ['calendario'] });
    void queryClient.invalidateQueries({ queryKey: ['checklist'] });
    void queryClient.invalidateQueries({ queryKey: ['recordatorios'] });
    void queryClient.invalidateQueries({ queryKey: ['actividad', id] });
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

  const solicitarMut = useMutation({
    mutationFn: () => api.solicitarConclusion(id),
    onSuccess: () => {
      toast('Se solicitó la conclusión del acuerdo; queda en revisión.');
      invalidar();
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

  const rechazarMut = useMutation({
    mutationFn: (motivo: string) => api.rechazarConclusion(id, motivo),
    onSuccess: () => {
      toast('Se rechazó la solicitud de conclusión.');
      invalidar();
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

  const editarMut = useMutation({
    mutationFn: async () => {
      const cambios: EdicionAcuerdo = {
        tema: form.tema.trim() ? form.tema.trim() : null,
        accion: form.accion.trim(),
        enlaces: form.enlaces.map((e) => e.trim()).filter((e) => e !== ''),
        observaciones: form.observaciones.trim() ? form.observaciones.trim() : null,
      };
      // Campos estructurales (responsable/área): el backend (`puedeEditarEstructura`)
      // rechaza el PATCH si la clave está presente y quien edita no tiene permiso,
      // sin importar si el valor cambió — por eso solo se envían cuando corresponde.
      if (puedeEditarEstructura) {
        cambios.responsable_id = Number(form.responsable_id);
        cambios.area_id = Number(form.area_id);
      }
      await api.editarAcuerdo(id, cambios);

      // Corresponsables (PUT /acuerdos/{id}/corresponsables) requiere el mismo
      // permiso estructural; un participante-solo-contenido no puede tocarlos.
      if (puedeEditarEstructura) {
        const actuales = (detalleQ.data?.corresponsables ?? []).map((c) => c.id);
        const sinCambios =
          actuales.length === form.corresponsables.length && actuales.every((x) => form.corresponsables.includes(x));
        if (!sinCambios) await api.setCorresponsables(id, form.corresponsables);
      }
    },
    onSuccess: () => {
      toast('El acuerdo se actualizó.');
      setEditando(false);
      invalidar();
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

  const eliminarMut = useMutation({
    mutationFn: () => api.eliminarAcuerdo(id),
    onSuccess: () => {
      toast('El acuerdo se eliminó definitivamente y su evento salió del calendario.');
      invalidar();
      onClose();
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

  const empezarEdicion = (a: AcuerdoDetalle) => {
    setForm({
      tema: a.tema ?? '',
      accion: a.accion,
      responsable_id: String(a.responsable.id),
      area_id: String(a.area.id),
      enlaces: a.enlaces ?? [],
      observaciones: a.observaciones ?? '',
      corresponsables: a.corresponsables.map((c) => c.id),
    });
    setEditando(true);
  };

  const guardarEdicion = () => {
    if (!form.accion.trim()) {
      toast('El acuerdo / acción no puede quedar vacío.', 'error');
      return;
    }
    editarMut.mutate();
  };

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

  const solicitar = () => {
    solicitarMut.mutate();
  };

  const rechazar = () => {
    const motivo = window.prompt('Motivo del rechazo (obligatorio):', '');
    if (motivo === null) return;
    if (!motivo.trim()) {
      toast('El motivo de rechazo no puede quedar vacío.', 'error');
      return;
    }
    rechazarMut.mutate(motivo.trim());
  };

  // Edición de CONTENIDO (tema/acción/enlaces/observaciones): además de quien edita
  // estructura, cualquier participante (spec 2026-07-29). El propio formulario
  // deshabilita los campos estructurales para quien no cumple `puedeEditarEstructura`.
  const puedeEditar = puedeEditarEstructura || esParticipante;
  // Concluir/aprobar/rechazar: Dirección (cualquiera) o coordinación del área del
  // acuerdo (ADR-012). Reabrir sigue siendo solo Dirección.
  const puedeConcluir =
    sel !== undefined &&
    u !== undefined &&
    (esDireccion || (u.rol === 'coordinador' && u.area_id === sel.area.id));
  // Solicitar conclusión: participante, acuerdo no concluido y sin una solicitud ya pendiente.
  const puedeSolicitar =
    esParticipante && sel !== undefined && sel.estado !== 'concluido' && sel.revision_estado !== 'pendiente';
  const enRevision = sel !== undefined && sel.revision_estado === 'pendiente';
  const usuariosActivos = (usuariosQ.data ?? []).filter((x) => x.activo);
  const areas = areasQ.data ?? [];
  // Bitácora: la actividad ya viene ordenada desc del backend; reforzamos el orden
  // en cliente (defensivo) por si cambiara la fuente. Sin mutar el array original.
  const bitacora = useMemo(
    () => [...(actividadQ.data ?? [])].sort((a, b) => b.created_at.localeCompare(a.created_at)),
    [actividadQ.data],
  );

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
              const rm = revisionMeta(sel.revision_estado);
              return (
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <Badge variant={est.variant} size="md" label={est.label} />
                  {rm && <Badge variant={rm.variant} size="md" label={rm.label} />}
                  <span style={{ fontSize: 12.5, fontWeight: 600, color: relColor }}>{rel}</span>
                  {puedeEditar && !editando && (
                    <button
                      type="button"
                      className="btn btn--ghost-teal btn--sm"
                      style={{ marginLeft: 'auto' }}
                      onClick={() => empezarEdicion(sel)}
                    >
                      Editar
                    </button>
                  )}
                </div>
              );
            })()}

            {sel.revision_estado === 'rechazada' && sel.revision_motivo_rechazo && (
              <div className="alert alert--error">
                <div className="alert__body">
                  <strong>Se rechazó la solicitud de conclusión.</strong> Motivo: {sel.revision_motivo_rechazo}
                </div>
              </div>
            )}

            {editando ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                <div className="detail-label" style={{ marginBottom: 0 }}>
                  Editar acuerdo
                </div>
                <div className="field">
                  <label className="field__label" htmlFor="ed-tema">
                    Tema
                  </label>
                  <input
                    id="ed-tema"
                    className="input"
                    value={form.tema}
                    onChange={(e) => setForm((f) => ({ ...f, tema: e.target.value }))}
                  />
                </div>
                <div className="field">
                  <label className="field__label" htmlFor="ed-accion">
                    Acuerdo / acción <span className="req">*</span>
                  </label>
                  <EditorHtml
                    id="ed-accion"
                    value={form.accion}
                    onChange={(v) => setForm((f) => ({ ...f, accion: v }))}
                  />
                </div>
                <div className="field">
                  <label className="field__label" htmlFor="ed-resp">
                    Responsable <span className="req">*</span>
                  </label>
                  <Select
                    id="ed-resp"
                    value={form.responsable_id}
                    placeholder="Selecciona…"
                    disabled={!puedeEditarEstructura}
                    opciones={usuariosActivos.map((x) => ({ value: String(x.id), label: x.nombre }))}
                    onChange={(v) =>
                      setForm((f) => ({
                        ...f,
                        responsable_id: v,
                        corresponsables: f.corresponsables.filter((c) => c !== Number(v)),
                      }))
                    }
                  />
                  {!puedeEditarEstructura && (
                    <p style={{ margin: '4px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>
                      Solo Dirección, la coordinación del área o quien capturó pueden cambiar el responsable.
                    </p>
                  )}
                </div>
                <div className="field">
                  <span className="field__label">Corresponsables</span>
                  <CorresponsablesPicker
                    directorio={usuariosActivos}
                    areas={areas}
                    seleccionados={form.corresponsables}
                    excluirId={form.responsable_id ? Number(form.responsable_id) : null}
                    onChange={(ids) => setForm((f) => ({ ...f, corresponsables: ids }))}
                    disabled={!puedeEditarEstructura}
                  />
                </div>
                <div className="field">
                  <label className="field__label" htmlFor="ed-area">
                    Área <span className="req">*</span>
                  </label>
                  <Select
                    id="ed-area"
                    value={form.area_id}
                    placeholder="Selecciona…"
                    disabled={!puedeEditarEstructura}
                    opciones={areas.map((a) => ({ value: String(a.id), label: a.nombre }))}
                    onChange={(v) => setForm((f) => ({ ...f, area_id: v }))}
                  />
                  {!puedeEditarEstructura && (
                    <p style={{ margin: '4px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>
                      Solo Dirección, la coordinación del área o quien capturó pueden cambiar el área.
                    </p>
                  )}
                </div>
                <div className="field">
                  <span className="field__label">Enlaces a productos</span>
                  <EnlacesInput
                    idBase="ed-enlace"
                    enlaces={form.enlaces}
                    onChange={(enlaces) => setForm((f) => ({ ...f, enlaces }))}
                  />
                </div>
                <div className="field">
                  <label className="field__label" htmlFor="ed-obs">
                    Observaciones
                  </label>
                  <textarea
                    id="ed-obs"
                    className="textarea"
                    style={{ minHeight: 64 }}
                    value={form.observaciones}
                    onChange={(e) => setForm((f) => ({ ...f, observaciones: e.target.value }))}
                  />
                </div>
                <div style={{ display: 'flex', gap: 10 }}>
                  <button type="button" className="btn btn--accent btn--md" onClick={guardarEdicion} disabled={editarMut.isPending}>
                    {editarMut.isPending ? 'Guardando…' : 'Guardar cambios'}
                  </button>
                  <button type="button" className="btn btn--ghost btn--md" onClick={() => setEditando(false)}>
                    Cancelar
                  </button>
                </div>
                <p style={{ margin: 0, fontSize: 11.5, lineHeight: 1.6, color: 'var(--muted)' }}>
                  La fecha compromiso se cambia registrando un avance con reprogramación; el estado lo maneja el
                  sistema y Dirección.
                </p>
              </div>
            ) : (
              <>
            <div>
              <div className="detail-label">Acuerdo / acción</div>
              <div style={{ fontSize: 15, fontWeight: 500 }}>
                <RichText html={sel.accion} />
              </div>
            </div>

            <div>
              <div className="detail-label">Responsable</div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <Avatar nombre={sel.responsable.nombre} size="lg" color={sel.responsable.avatar_color} />
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

            <div className="grid grid-cols-1 sm:grid-cols-[1fr_1fr] gap-[16px]">
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
              <div className="detail-label">Enlaces a productos</div>
              {sel.enlaces.length > 0 ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                  {sel.enlaces.map((url, i) => (
                    <a
                      key={i}
                      href={url}
                      target="_blank"
                      rel="noreferrer"
                      style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--text-link)', wordBreak: 'break-all' }}
                    >
                      {url}
                    </a>
                  ))}
                </div>
              ) : (
                <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--text-muted)' }}>Sin enlaces registrados</div>
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
              <div className="detail-label" style={{ marginBottom: 10 }}>Bitácora</div>
              {actividadQ.isLoading && (
                <div style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Cargando actividad…</div>
              )}
              {!actividadQ.isLoading && bitacora.length === 0 && (
                <div style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Aún no hay actividad registrada.</div>
              )}
              {bitacora.map((ev) => {
                const meta = TIPO_EVENTO_META[ev.tipo];
                return (
                  <div
                    key={ev.id}
                    style={{
                      padding: '10px 0 10px 14px',
                      borderTop: '1px solid var(--border-subtle)',
                      borderLeft: `3px solid ${meta.color}`,
                      marginLeft: 2,
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                      <span aria-hidden style={{ width: 7, height: 7, borderRadius: '50%', background: meta.color, flexShrink: 0 }} />
                      <span style={{ fontFamily: 'var(--font-display)', fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.08em', color: meta.color }}>
                        {meta.label}
                      </span>
                      <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                        {(ev.usuario?.nombre ?? 'Sistema')} · {fmtF(ev.created_at.slice(0, 10))}
                      </span>
                    </div>
                    <div style={{ fontSize: 13, lineHeight: 1.5, color: 'var(--text-secondary)' }}>{ev.descripcion}</div>
                    {ev.nueva_fecha && (
                      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-brand)', marginTop: 3 }}>
                        Nueva fecha compromiso: {fmtL(ev.nueva_fecha)}
                      </div>
                    )}
                  </div>
                );
              })}
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
                    <DatePicker
                      id="avance-fecha"
                      min={shiftISO(hoyISO(), 1)}
                      value={nuevaFecha}
                      onChange={setNuevaFecha}
                    />
                  </div>
                )}
                <button type="submit" className="btn btn--accent btn--md btn--full" disabled={avanceMut.isPending}>
                  {avanceMut.isPending ? 'Guardando…' : 'Guardar avance'}
                </button>
              </form>
            )}

            {sel.estado !== 'concluido' && puedeSolicitar && (
              <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
                <button
                  type="button"
                  className="btn btn--ghost-teal btn--md btn--full"
                  onClick={solicitar}
                  disabled={solicitarMut.isPending}
                >
                  {solicitarMut.isPending ? 'Solicitando…' : 'Solicitar conclusión'}
                </button>
              </div>
            )}

            {sel.estado !== 'concluido' && enRevision && puedeConcluir && (
              <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20, display: 'flex', gap: 10 }}>
                <button
                  type="button"
                  className="btn btn--ghost-teal btn--md btn--full"
                  onClick={concluir}
                  disabled={concluirMut.isPending}
                >
                  {concluirMut.isPending ? 'Aprobando…' : 'Aprobar'}
                </button>
                <button
                  type="button"
                  className="btn btn--ghost-rojo btn--md btn--full"
                  onClick={rechazar}
                  disabled={rechazarMut.isPending}
                >
                  {rechazarMut.isPending ? 'Rechazando…' : 'Rechazar'}
                </button>
              </div>
            )}

            {sel.estado !== 'concluido' && !enRevision && puedeConcluir && (
              <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
                <button
                  type="button"
                  className="btn btn--ghost-teal btn--md btn--full"
                  onClick={concluir}
                  disabled={concluirMut.isPending}
                >
                  Marcar como concluido
                </button>
              </div>
            )}

            {sel.estado === 'concluido' && esDireccion && (
              <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
                <button
                  type="button"
                  className="btn btn--ghost btn--md btn--full"
                  onClick={reabrir}
                  disabled={reabrirMut.isPending}
                >
                  Reabrir acuerdo
                </button>
              </div>
            )}

            {esDireccion && (
              <div style={{ borderTop: '1px solid var(--border)', paddingTop: 20 }}>
                {!confirmandoEliminar ? (
                  <button
                    type="button"
                    className="btn btn--ghost-rojo btn--md btn--full"
                    onClick={() => setConfirmandoEliminar(true)}
                  >
                    Eliminar acuerdo…
                  </button>
                ) : (
                  <div
                    style={{
                      display: 'flex',
                      flexDirection: 'column',
                      gap: 10,
                      background: 'rgba(240,101,74,.08)',
                      border: '1px solid rgba(240,101,74,.3)',
                      borderRadius: 12,
                      padding: '14px 16px',
                    }}
                  >
                    <div style={{ fontSize: 13, lineHeight: 1.55 }}>
                      Se borrará definitivamente el acuerdo con sus avances y recordatorios, y su evento saldrá del
                      calendario. Esta acción no se puede deshacer.
                    </div>
                    <div style={{ display: 'flex', gap: 10 }}>
                      <button
                        type="button"
                        className="btn btn--sm"
                        style={{ background: 'var(--red)', color: '#ffffff' }}
                        onClick={() => eliminarMut.mutate()}
                        disabled={eliminarMut.isPending}
                      >
                        {eliminarMut.isPending ? 'Eliminando…' : 'Eliminar definitivamente'}
                      </button>
                      <button type="button" className="btn btn--ghost btn--sm" onClick={() => setConfirmandoEliminar(false)}>
                        Cancelar
                      </button>
                    </div>
                  </div>
                )}
              </div>
            )}
              </>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
