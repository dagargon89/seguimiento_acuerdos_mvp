/**
 * Usuarios y permisos 1:1 con renderUsuarios del demo, ahora contra el
 * contrato: baja/reactivación con editarUsuario y alta con crearUsuario
 * (solo Dirección; el mock rechaza 403/422 y se muestra como toast/alert).
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib';
import type { AltaUsuario, Rol } from '../lib';
import { ROL_LABEL, camposError, mensajeError } from '../components/EstadoHelpers';
import { Avatar } from '../components/Avatar';
import { Badge } from '../components/Badge';
import { useSesion } from '../components/SessionContext';
import { useToast } from '../components/Toast';

interface FormAlta {
  nombre: string;
  email: string;
  rol: Rol;
  area_id: string;
}

const formVacio = (): FormAlta => ({ nombre: '', email: '', rol: 'responsable', area_id: '' });

export function Usuarios() {
  const { sesion } = useSesion();
  const { toast } = useToast();
  const queryClient = useQueryClient();

  const [nuevo, setNuevo] = useState<FormAlta>(formVacio());
  const [altaError, setAltaError] = useState<string | null>(null);
  const [altaCampos, setAltaCampos] = useState<Record<string, string>>({});

  const usuariosQ = useQuery({ queryKey: ['usuarios'], queryFn: () => api.listUsuarios() });
  const areasQ = useQuery({ queryKey: ['areas'], queryFn: () => api.listAreas() });

  const areas = areasQ.data ?? [];
  const areaNombre = (id: number | null) => (id === null ? null : areas.find((a) => a.id === id)?.nombre ?? '—');

  const invalidar = () => void queryClient.invalidateQueries({ queryKey: ['usuarios'] });

  const estadoMut = useMutation({
    mutationFn: ({ id, activo }: { id: number; activo: boolean }) => api.editarUsuario(id, { activo }),
    onSuccess: (u) => {
      toast(u.activo ? `${u.nombre} se reactivó y puede volver a entrar al panel.` : `${u.nombre} quedó dada de baja.`);
      invalidar();
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

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
      area_id: nuevo.rol === 'coordinador' ? Number(nuevo.area_id) : null,
    });
  };

  const setCampo = <K extends keyof FormAlta>(campo: K, valor: FormAlta[K]) => {
    setNuevo((n) => {
      const sig = { ...n, [campo]: valor };
      if (campo === 'rol' && valor !== 'coordinador') sig.area_id = '';
      return sig;
    });
    if (altaError) setAltaError(null);
  };

  return (
    <div style={{ maxWidth: 880, margin: '0 auto' }}>
      <div style={{ marginBottom: 24 }}>
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

      <div className="panel-card" style={{ marginBottom: 20 }}>
        <table className="acuerdos-table">
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
            {(usuariosQ.data ?? []).map((u) => (
              <tr key={u.id} style={{ cursor: 'default', opacity: u.activo ? 1 : 0.65 }}>
                <td>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                    <Avatar nombre={u.nombre} size="md" />
                    <span style={{ fontSize: 13, fontWeight: 500 }}>{u.nombre}</span>
                  </span>
                </td>
                <td style={{ fontSize: 12.5, color: 'var(--text-secondary)' }}>{u.email}</td>
                <td>
                  <span className={`rol-chip rol-chip--${u.rol}`}>{ROL_LABEL[u.rol]}</span>
                </td>
                <td style={{ fontSize: 12.5 }}>{areaNombre(u.area_id) ?? '—'}</td>
                <td>
                  {u.activo ? (
                    <Badge variant="success" size="sm" label="Activo" />
                  ) : (
                    <Badge variant="neutral" size="sm" label="Baja" />
                  )}
                </td>
                <td style={{ textAlign: 'right' }}>
                  {u.id === sesion?.usuario.id ? (
                    <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Tu cuenta</span>
                  ) : (
                    <button
                      type="button"
                      className="btn btn--ghost btn--sm"
                      disabled={estadoMut.isPending}
                      onClick={() => estadoMut.mutate({ id: u.id, activo: !u.activo })}
                    >
                      {u.activo ? 'Dar de baja' : 'Reactivar'}
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {altaError && (
        <div style={{ marginBottom: 16 }}>
          <div className="alert alert--error">
            <div className="alert__body">{altaError}</div>
          </div>
        </div>
      )}

      <div className="panel-card" style={{ padding: '24px 28px' }}>
        <div style={{ fontSize: 11, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '.14em', color: 'var(--text-brand)', marginBottom: 16 }}>
          Dar de alta a una persona
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 18 }}>
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
            <select className="select" id="nu-rol" value={nuevo.rol} onChange={(e) => setCampo('rol', e.target.value as Rol)}>
              {(Object.keys(ROL_LABEL) as Rol[]).map((r) => (
                <option key={r} value={r}>
                  {ROL_LABEL[r]}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label className="field__label" htmlFor="nu-area">
              Área {nuevo.rol === 'coordinador' && <span className="req">*</span>}
            </label>
            <select
              className="select"
              id="nu-area"
              value={nuevo.area_id}
              disabled={nuevo.rol !== 'coordinador'}
              style={altaCampos.area_id ? { borderColor: 'var(--status-error)' } : undefined}
              onChange={(e) => setCampo('area_id', e.target.value)}
            >
              <option value="">—</option>
              {areas.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.nombre}
                </option>
              ))}
            </select>
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
