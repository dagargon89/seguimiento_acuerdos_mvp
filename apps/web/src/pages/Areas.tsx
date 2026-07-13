/**
 * Administración de áreas (solo Dirección, ADR-004/ADR-008): catálogo completo
 * (activas e inactivas vía listAreas(true)), alta, renombrado inline y
 * desactivar/reactivar (baja lógica — las áreas referenciadas por acuerdos y
 * usuarios conservan su historial). Sigue el patrón de Usuarios.tsx.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib';
import type { Area, EdicionArea } from '../lib';
import { camposError, mensajeError } from '../components/EstadoHelpers';
import { Badge } from '../components/Badge';
import { useToast } from '../components/Toast';

export function Areas() {
  const { toast } = useToast();
  const queryClient = useQueryClient();

  const [nombreNueva, setNombreNueva] = useState('');
  const [altaError, setAltaError] = useState<string | null>(null);
  // Renombrado inline: id del área en edición y el borrador del nombre.
  const [editandoId, setEditandoId] = useState<number | null>(null);
  const [nombreEdit, setNombreEdit] = useState('');

  const areasQ = useQuery({ queryKey: ['areas', 'todas'], queryFn: () => api.listAreas(true) });

  // Invalida el catálogo completo y el de activas (selects de Captura/Usuarios).
  const invalidar = () => void queryClient.invalidateQueries({ queryKey: ['areas'] });

  const editarMut = useMutation({
    mutationFn: ({ id, cambios }: { id: number; cambios: EdicionArea }) => api.editarArea(id, cambios),
    onSuccess: (a, { cambios }) => {
      if (cambios.activa === false) toast(`El área "${a.nombre}" quedó desactivada y ya no aparece en los selects.`);
      else if (cambios.activa === true) toast(`El área "${a.nombre}" se reactivó.`);
      else toast('El área se renombró correctamente.');
      setEditandoId(null);
      invalidar();
    },
    onError: (e) => toast(mensajeError(e), 'error'),
  });

  const altaMut = useMutation({
    mutationFn: (nombre: string) => api.crearArea({ nombre }),
    onSuccess: (a) => {
      toast(`El área "${a.nombre}" quedó dada de alta.`);
      setNombreNueva('');
      setAltaError(null);
      invalidar();
    },
    onError: (e) => {
      const campos = camposError(e);
      setAltaError(campos.nombre ?? mensajeError(e));
      toast(mensajeError(e), 'error');
    },
  });

  const crear = () => {
    if (!nombreNueva.trim()) {
      setAltaError('Escribe el nombre del área.');
      return;
    }
    setAltaError(null);
    altaMut.mutate(nombreNueva.trim());
  };

  const empezarRenombrar = (a: Area) => {
    setEditandoId(a.id);
    setNombreEdit(a.nombre);
  };

  const guardarRenombrar = (id: number) => {
    if (!nombreEdit.trim()) {
      toast('El nombre no puede estar vacío.', 'error');
      return;
    }
    editarMut.mutate({ id, cambios: { nombre: nombreEdit.trim() } });
  };

  const areas = areasQ.data ?? [];

  return (
    <div style={{ maxWidth: 920, margin: '0 auto' }}>
      <div className="anim-in" style={{ marginBottom: 28 }}>
        <div className="section-header__eyebrow">Administración · solo Dirección</div>
        <h2 className="section-header__title">Áreas</h2>
        <p className="section-header__subtitle">
          Administra el catálogo de áreas al que se asignan acuerdos y coordinaciones. Desactivar un área la
          oculta de los selects sin borrar su historial; puedes reactivarla cuando quieras.
        </p>
      </div>

      {areasQ.isError && (
        <div className="alert alert--error" style={{ marginBottom: 16 }}>
          <div className="alert__body">{mensajeError(areasQ.error)}</div>
        </div>
      )}

      <div className="panel-card anim-in anim-in--1" style={{ marginBottom: 22 }}>
        <table className="acuerdos-table">
          <thead>
            <tr>
              <th>Área</th>
              <th>Estado</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {areas.map((a) => (
              <tr key={a.id} style={{ cursor: 'default', opacity: a.activa ? 1 : 0.5 }}>
                <td style={{ verticalAlign: 'middle' }}>
                  {editandoId === a.id ? (
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8, width: '100%', maxWidth: 380 }}>
                      <input
                        className="input"
                        aria-label={`Nuevo nombre para ${a.nombre}`}
                        value={nombreEdit}
                        autoFocus
                        onChange={(e) => setNombreEdit(e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') guardarRenombrar(a.id);
                          if (e.key === 'Escape') setEditandoId(null);
                        }}
                        style={{ padding: '8px 11px', fontSize: 13 }}
                      />
                    </span>
                  ) : (
                    <span style={{ fontSize: 13.5, fontWeight: 500 }}>{a.nombre}</span>
                  )}
                </td>
                <td style={{ verticalAlign: 'middle' }}>
                  {a.activa ? (
                    <Badge variant="success" size="sm" label="Activa" />
                  ) : (
                    <Badge variant="neutral" size="sm" label="Baja" />
                  )}
                </td>
                <td style={{ textAlign: 'right', verticalAlign: 'middle' }}>
                  <span style={{ display: 'inline-flex', gap: 8 }}>
                    {editandoId === a.id ? (
                      <>
                        <button
                          type="button"
                          className="btn btn--accent btn--sm"
                          disabled={editarMut.isPending}
                          onClick={() => guardarRenombrar(a.id)}
                        >
                          {editarMut.isPending ? 'Guardando…' : 'Guardar'}
                        </button>
                        <button type="button" className="btn btn--ghost btn--sm" onClick={() => setEditandoId(null)}>
                          Cancelar
                        </button>
                      </>
                    ) : (
                      <>
                        <button
                          type="button"
                          className="btn btn--ghost-teal btn--sm"
                          disabled={editarMut.isPending}
                          onClick={() => empezarRenombrar(a)}
                        >
                          Renombrar
                        </button>
                        <button
                          type="button"
                          className="btn btn--ghost btn--sm"
                          disabled={editarMut.isPending}
                          onClick={() => editarMut.mutate({ id: a.id, cambios: { activa: !a.activa } })}
                        >
                          {a.activa ? 'Desactivar' : 'Reactivar'}
                        </button>
                      </>
                    )}
                  </span>
                </td>
              </tr>
            ))}
            {areas.length === 0 && !areasQ.isLoading && (
              <tr>
                <td colSpan={3} style={{ textAlign: 'center', padding: 28, color: 'var(--muted)', cursor: 'default' }}>
                  No hay áreas registradas todavía.
                </td>
              </tr>
            )}
            {areasQ.isLoading && (
              <tr>
                <td colSpan={3} style={{ textAlign: 'center', padding: 28, color: 'var(--muted)', cursor: 'default' }}>
                  Cargando…
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

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
          Dar de alta un área
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr auto', gap: 18, alignItems: 'end' }}>
          <div className="field">
            <label className="field__label" htmlFor="na-nombre">
              Nombre <span className="req">*</span>
            </label>
            <input
              className="input"
              id="na-nombre"
              placeholder="Ej. Comunicación y difusión"
              value={nombreNueva}
              style={altaError ? { borderColor: 'var(--red)' } : undefined}
              onChange={(e) => {
                setNombreNueva(e.target.value);
                if (altaError) setAltaError(null);
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter') crear();
              }}
            />
            {altaError && <span style={{ fontSize: 11.5, color: 'var(--red)' }}>{altaError}</span>}
          </div>
          <button
            type="button"
            className="btn btn--accent btn--md"
            style={{ marginBottom: altaError ? 24 : 0 }}
            onClick={crear}
            disabled={altaMut.isPending}
          >
            {altaMut.isPending ? 'Guardando…' : 'Dar de alta'}
          </button>
        </div>
      </div>
    </div>
  );
}
