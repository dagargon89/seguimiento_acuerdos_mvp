/**
 * Usuarios y permisos 1:1 con renderUsuarios del demo, ahora contra el
 * contrato: baja/reactivación con editarUsuario y alta con crearUsuario
 * (solo Dirección; el mock rechaza 403/422 y se muestra como toast/alert).
 *
 * ADR-012: el área puede asignarse a CUALQUIER rol (no solo coordinación) y se
 * puede editar el rol/área de un usuario ya activo desde esta misma tabla.
 */
import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib';
import type { AltaUsuario, Rol } from '../lib';
import { ROL_LABEL, camposError, mensajeError } from '../components/EstadoHelpers';
import { Avatar } from '../components/Avatar';
import { Badge } from '../components/Badge';
import { Paginacion } from '../components/Paginacion';
import { Select } from '../components/Select';
import { useSesion } from '../components/SessionContext';
import { useToast } from '../components/Toast';
import { usePaginacion } from '../lib/usePaginacion';

interface FormAlta {
  nombre: string;
  email: string;
  rol: Rol;
  area_id: string;
}

const formVacio = (): FormAlta => ({ nombre: '', email: '', rol: 'responsable', area_id: '' });

/** Roles asignables (el alta directa y la aprobación nunca dejan a alguien como pendiente). */
const ROLES_ASIGNABLES = (Object.keys(ROL_LABEL) as Rol[]).filter((r) => r !== 'pendiente');

interface Aprobacion {
  rol: Rol;
  area_id: string;
}

export function Usuarios() {
  const { sesion } = useSesion();
  const { toast } = useToast();
  const queryClient = useQueryClient();

  const [nuevo, setNuevo] = useState<FormAlta>(formVacio());
  const [altaError, setAltaError] = useState<string | null>(null);
  const [altaCampos, setAltaCampos] = useState<Record<string, string>>({});
  // Selección de rol/área por cada usuario pendiente (id → aprobación en edición).
  const [aprobaciones, setAprobaciones] = useState<Record<number, Aprobacion>>({});
  // Edición inline de usuarios activos (id presente → esa fila está en modo edición).
  const [ediciones, setEdiciones] = useState<Record<number, Aprobacion>>({});

  const usuariosQ = useQuery({ queryKey: ['usuarios'], queryFn: () => api.listUsuarios() });
  const areasQ = useQuery({ queryKey: ['areas'], queryFn: () => api.listAreas() });

  // Pendientes primero: son los que requieren acción de Dirección.
  const usuariosOrdenados = useMemo(
    () =>
      [...(usuariosQ.data ?? [])].sort((a, b) => {
        const aPendiente = a.rol === 'pendiente' ? 0 : 1;
        const bPendiente = b.rol === 'pendiente' ? 0 : 1;
        return aPendiente - bPendiente;
      }),
    [usuariosQ.data],
  );
  const pag = usePaginacion(usuariosOrdenados);

  const areas = areasQ.data ?? [];
  const areaNombre = (id: number | null) => (id === null ? null : areas.find((a) => a.id === id)?.nombre ?? '—');
  const opcionesArea = areas.map((a) => ({ value: String(a.id), label: a.nombre }));
  const opcionesRol = ROLES_ASIGNABLES.map((r) => ({ value: r, label: ROL_LABEL[r] }));

  const invalidar = () => void queryClient.invalidateQueries({ queryKey: ['usuarios'] });

  const estadoMut = useMutation({
    mutationFn: ({ id, activo }: { id: number; activo: boolean }) => api.editarUsuario(id, { activo }),
    onSuccess: (u) => {
      toast(u.activo ? `${u.nombre} se reactivó y puede volver a entrar al panel.` : `${u.nombre} quedó dada de baja.`);
      invalidar();
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

  // ── Aprobación de pendientes ─────────────────────────────────────────────
  const aprobarMut = useMutation({
    mutationFn: ({ id, rol, area_id }: { id: number; rol: Rol; area_id: number | null }) =>
      api.editarUsuario(id, { rol, area_id }),
    onSuccess: (u) => {
      toast(`${u.nombre} quedó aprobada como ${ROL_LABEL[u.rol]} y ya puede entrar al panel.`);
      setAprobaciones((prev) => {
        const sig = { ...prev };
        delete sig[u.id];
        return sig;
      });
      invalidar();
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

  const aprobacionDe = (id: number): Aprobacion => aprobaciones[id] ?? { rol: 'responsable', area_id: '' };

  const setAprobacion = (id: number, cambios: Partial<Aprobacion>) => {
    setAprobaciones((prev) => {
      const actual = prev[id] ?? { rol: 'responsable' as Rol, area_id: '' };
      return { ...prev, [id]: { ...actual, ...cambios } };
    });
  };

  const aprobar = (id: number) => {
    const a = aprobacionDe(id);
    if (a.rol === 'coordinador' && !a.area_id) {
      toast('Una coordinación necesita un área asignada.', 'error');
      return;
    }
    aprobarMut.mutate({ id, rol: a.rol, area_id: a.area_id ? Number(a.area_id) : null });
  };

  // ── Edición inline de usuarios activos ───────────────────────────────────
  const editarMut = useMutation({
    mutationFn: ({ id, rol, area_id }: { id: number; rol: Rol; area_id: number | null }) =>
      api.editarUsuario(id, { rol, area_id }),
    onSuccess: (u) => {
      toast(`Se actualizaron el rol y el área de ${u.nombre}.`);
      cancelarEdicion(u.id);
      invalidar();
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

  const iniciarEdicion = (id: number, rol: Rol, area_id: number | null) =>
    setEdiciones((prev) => ({ ...prev, [id]: { rol, area_id: area_id === null ? '' : String(area_id) } }));

  const cancelarEdicion = (id: number) =>
    setEdiciones((prev) => {
      const sig = { ...prev };
      delete sig[id];
      return sig;
    });

  const setEdicion = (id: number, cambios: Partial<Aprobacion>) =>
    setEdiciones((prev) => ({ ...prev, [id]: { ...prev[id], ...cambios } }));

  const guardarEdicion = (id: number) => {
    const e = ediciones[id];
    if (!e) return;
    if (e.rol === 'coordinador' && !e.area_id) {
      toast('Una coordinación necesita un área asignada.', 'error');
      return;
    }
    editarMut.mutate({ id, rol: e.rol, area_id: e.area_id ? Number(e.area_id) : null });
  };

  // ── Alta directa ─────────────────────────────────────────────────────────
  const altaMut = useMutation({
    mutationFn: (alta: AltaUsuario) => api.crearUsuario(alta),
    onSuccess: (u) => {
      toast(`${u.nombre} quedó dada de alta con rol ${ROL_LABEL[u.rol]}.`);
      setNuevo(formVacio());
      setAltaError(null);
      setAltaCampos({});
      invalidar();
    },
    onError: (e) => {
      setAltaError(mensajeError(e));
      setAltaCampos(camposError(e));
      toast(mensajeError(e), 'error');
    },
  });

  const darDeAlta = () => {
    if (!nuevo.nombre.trim() || !nuevo.email.trim() || (nuevo.rol === 'coordinador' && !nuevo.area_id)) {
      setAltaError('Completa nombre y correo; si el rol es Coordinación, también el área.');
      return;
    }
    setAltaError(null);
    setAltaCampos({});
    altaMut.mutate({
      nombre: nuevo.nombre.trim(),
      email: nuevo.email.trim(),
      rol: nuevo.rol,
      area_id: nuevo.area_id ? Number(nuevo.area_id) : null,
    });
  };

  const setCampo = <K extends keyof FormAlta>(campo: K, valor: FormAlta[K]) => {
    setNuevo((n) => ({ ...n, [campo]: valor }));
    if (altaError) setAltaError(null);
  };

  return (
    <div style={{ maxWidth: 920, margin: '0 auto' }}>
      <div className="anim-in" style={{ marginBottom: 28 }}>
        <div className="section-header__eyebrow">Administración · solo Dirección</div>
        <h2 className="section-header__title">Usuarios y permisos</h2>
        <p className="section-header__subtitle">
          Da de alta o de baja a las personas que pueden entrar al panel. El rol define qué ve cada quien: la
          dirección ve todo, una coordinación ve su área y cada responsable ve solo sus compromisos.
        </p>
      </div>

      {usuariosQ.isError && (
        <div className="alert alert--error" style={{ marginBottom: 16 }}>
          <div className="alert__body">{mensajeError(usuariosQ.error)}</div>
        </div>
      )}

      <div className="panel-card anim-in anim-in--1" style={{ marginBottom: 22, overflowX: 'auto' }}>
        <table className="acuerdos-table" style={{ minWidth: 760 }}>
          <thead>
            <tr>
              <th>Persona</th>
              <th>Correo</th>
              <th>Rol</th>
              <th>Área</th>
              <th>Estado</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {pag.pagina_items.map((u) => {
              const esPendiente = u.rol === 'pendiente' && u.activo;
              const editando = ediciones[u.id] !== undefined;
              const esPropio = u.id === sesion?.usuario.id;
              // Valores de los selects según el modo de la fila.
              const rolSel = esPendiente ? aprobacionDe(u.id).rol : ediciones[u.id]?.rol ?? u.rol;
              const areaSel = esPendiente ? aprobacionDe(u.id).area_id : ediciones[u.id]?.area_id ?? '';
              const setRol = (v: string) =>
                esPendiente ? setAprobacion(u.id, { rol: v as Rol }) : setEdicion(u.id, { rol: v as Rol });
              const setArea = (v: string) =>
                esPendiente ? setAprobacion(u.id, { area_id: v }) : setEdicion(u.id, { area_id: v });
              const editableRolArea = esPendiente || editando;

              return (
                <tr key={u.id} style={{ cursor: 'default', opacity: u.activo ? 1 : 0.5 }}>
                  <td>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                      <Avatar nombre={u.nombre} size="md" />
                      <span style={{ fontSize: 13, fontWeight: 500 }}>{u.nombre}</span>
                    </span>
                  </td>
                  <td style={{ fontSize: 12.5, color: 'var(--text-secondary)' }}>{u.email}</td>
                  <td>
                    {editableRolArea ? (
                      <Select
                        ariaLabel={`Rol para ${u.nombre}`}
                        buscable={false}
                        value={rolSel}
                        opciones={opcionesRol}
                        onChange={setRol}
                        estilo={{ minWidth: 130 }}
                      />
                    ) : (
                      <span className={`rol-chip rol-chip--${u.rol}`}>{ROL_LABEL[u.rol]}</span>
                    )}
                  </td>
                  <td style={{ fontSize: 12.5 }}>
                    {editableRolArea ? (
                      <Select
                        ariaLabel={`Área para ${u.nombre}`}
                        value={areaSel}
                        placeholder="—"
                        opciones={opcionesArea}
                        onChange={setArea}
                        estilo={{ minWidth: 110 }}
                      />
                    ) : (
                      areaNombre(u.area_id) ?? '—'
                    )}
                  </td>
                  <td>
                    {u.activo ? (
                      <Badge variant="success" size="sm" label="Activo" />
                    ) : (
                      <Badge variant="neutral" size="sm" label="Baja" />
                    )}
                  </td>
                  <td style={{ textAlign: 'right' }}>
                    {esPropio ? (
                      <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Tu cuenta</span>
                    ) : (
                      <span style={{ display: 'inline-flex', gap: 8 }}>
                        {esPendiente && (
                          <button
                            type="button"
                            className="btn btn--accent btn--sm"
                            disabled={aprobarMut.isPending}
                            onClick={() => aprobar(u.id)}
                          >
                            {aprobarMut.isPending ? 'Aprobando…' : 'Aprobar'}
                          </button>
                        )}
                        {editando && (
                          <>
                            <button
                              type="button"
                              className="btn btn--accent btn--sm"
                              disabled={editarMut.isPending}
                              onClick={() => guardarEdicion(u.id)}
                            >
                              {editarMut.isPending ? 'Guardando…' : 'Guardar'}
                            </button>
                            <button
                              type="button"
                              className="btn btn--ghost btn--sm"
                              disabled={editarMut.isPending}
                              onClick={() => cancelarEdicion(u.id)}
                            >
                              Cancelar
                            </button>
                          </>
                        )}
                        {!esPendiente && !editando && u.activo && (
                          <button
                            type="button"
                            className="btn btn--ghost btn--sm"
                            onClick={() => iniciarEdicion(u.id, u.rol, u.area_id)}
                          >
                            Editar
                          </button>
                        )}
                        {!editando && (
                          <button
                            type="button"
                            className="btn btn--ghost btn--sm"
                            disabled={estadoMut.isPending}
                            onClick={() => estadoMut.mutate({ id: u.id, activo: !u.activo })}
                          >
                            {u.activo ? (u.rol === 'pendiente' ? 'Rechazar' : 'Dar de baja') : 'Reactivar'}
                          </button>
                        )}
                      </span>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <Paginacion estado={pag} sustantivo="usuarios" />

      {altaError && (
        <div style={{ marginBottom: 16 }}>
          <div className="alert alert--error">
            <div className="alert__body">{altaError}</div>
          </div>
        </div>
      )}

      <div className="panel-card anim-in anim-in--2" style={{ padding: '26px 30px' }}>
        <div
          style={{
            fontFamily: 'var(--font-display)',
            fontWeight: 600,
            fontSize: 16,
            color: 'var(--text)',
            marginBottom: 18,
          }}
        >
          Dar de alta a una persona
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-[1fr_1fr] gap-[18px]">
          <div className="field">
            <label className="field__label" htmlFor="nu-nombre">
              Nombre completo <span className="req">*</span>
            </label>
            <input
              className="input"
              id="nu-nombre"
              placeholder="Ej. Elizabeth Ramos"
              value={nuevo.nombre}
              style={altaCampos.nombre ? { borderColor: 'var(--status-error)' } : undefined}
              onChange={(e) => setCampo('nombre', e.target.value)}
            />
          </div>
          <div className="field">
            <label className="field__label" htmlFor="nu-email">
              Correo <span className="req">*</span>
            </label>
            <input
              className="input"
              id="nu-email"
              placeholder="persona@planjuarez.org"
              value={nuevo.email}
              style={altaCampos.email ? { borderColor: 'var(--status-error)' } : undefined}
              onChange={(e) => setCampo('email', e.target.value)}
            />
            {altaCampos.email && (
              <span style={{ fontSize: 11.5, color: 'var(--status-error)' }}>{altaCampos.email}</span>
            )}
          </div>
          <div className="field">
            <label className="field__label" htmlFor="nu-rol">
              Rol
            </label>
            <Select
              id="nu-rol"
              buscable={false}
              value={nuevo.rol}
              opciones={opcionesRol}
              onChange={(v) => setCampo('rol', v as Rol)}
            />
          </div>
          <div className="field">
            <label className="field__label" htmlFor="nu-area">
              Área {nuevo.rol === 'coordinador' && <span className="req">*</span>}
            </label>
            <Select
              id="nu-area"
              value={nuevo.area_id}
              placeholder="—"
              opciones={opcionesArea}
              estilo={altaCampos.area_id ? { borderColor: 'var(--red)' } : undefined}
              onChange={(v) => setCampo('area_id', v)}
            />
          </div>
        </div>
        <div style={{ marginTop: 18 }}>
          <button type="button" className="btn btn--accent btn--md" onClick={darDeAlta} disabled={altaMut.isPending}>
            {altaMut.isPending ? 'Guardando…' : 'Dar de alta'}
          </button>
        </div>
      </div>
    </div>
  );
}
