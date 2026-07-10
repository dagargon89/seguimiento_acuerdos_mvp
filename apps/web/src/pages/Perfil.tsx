/**
 * Perfil del usuario en sesión: datos personales (vía API, doc 05 PATCH /me)
 * y cambio de contraseña (vía Firebase, solo cuentas email/password).
 * Reutiliza el patrón de mutación/toast/tokens de Usuarios.tsx.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib';
import { ROL_LABEL, camposError, mensajeError } from '../components/EstadoHelpers';
import { useSesion } from '../components/SessionContext';
import { useToast } from '../components/Toast';
import { cambiarPassword, proveedorEsPassword } from '../lib/firebase';
import { validarCambioPassword } from '../lib/perfil';

export function Perfil() {
  const { sesion, setSesion } = useSesion();
  const { toast } = useToast();
  const queryClient = useQueryClient();

  const usuario = sesion?.usuario ?? null;

  const areasQ = useQuery({ queryKey: ['areas'], queryFn: () => api.listAreas() });
  const areaNombre = usuario?.area_id == null ? '—' : areasQ.data?.find((a) => a.id === usuario.area_id)?.nombre ?? '—';

  const [nombre, setNombre] = useState(usuario?.nombre ?? '');
  const [nombreError, setNombreError] = useState<string | null>(null);
  const [nombreCampos, setNombreCampos] = useState<Record<string, string>>({});

  const nombreMut = useMutation({
    mutationFn: () => api.editarMiPerfil({ nombre: nombre.trim() }),
    onSuccess: (usuarioActualizado) => {
      if (sesion) setSesion({ ...sesion, usuario: usuarioActualizado });
      void queryClient.invalidateQueries({ queryKey: ['me'] });
      setNombreError(null);
      setNombreCampos({});
      toast('Tus datos se actualizaron correctamente.');
    },
    onError: (e) => {
      setNombreError(mensajeError(e));
      setNombreCampos(camposError(e));
      toast(mensajeError(e), 'error');
    },
  });

  const guardarNombre = () => {
    if (!nombre.trim()) {
      setNombreError('El nombre no puede estar vacío.');
      return;
    }
    setNombreError(null);
    setNombreCampos({});
    nombreMut.mutate();
  };

  const [passActual, setPassActual] = useState('');
  const [passNueva, setPassNueva] = useState('');
  const [passConfirmar, setPassConfirmar] = useState('');
  const [passError, setPassError] = useState<string | null>(null);

  const passMut = useMutation({
    mutationFn: () => cambiarPassword(passActual, passNueva),
    onSuccess: () => {
      setPassActual('');
      setPassNueva('');
      setPassConfirmar('');
      setPassError(null);
      toast('Contraseña actualizada.');
    },
    onError: (e) => {
      const msg = mensajeError(e);
      setPassError(msg);
      toast(msg, 'error');
    },
  });

  const cambiarPasswordSubmit = () => {
    const error = validarCambioPassword(passActual, passNueva, passConfirmar);
    if (error) {
      setPassError(error);
      return;
    }
    setPassError(null);
    passMut.mutate();
  };

  const esCuentaPassword = proveedorEsPassword();

  if (!usuario) return null;

  return (
    <div>
      <div style={{ marginBottom: 24 }}>
        <div className="section-header__eyebrow">Mi cuenta</div>
        <h2 className="section-header__title">Perfil</h2>
        <p className="section-header__subtitle">Consulta tus datos y administra tu acceso al panel.</p>
      </div>

      <div className="panel-card" style={{ padding: '24px 28px', marginBottom: 20 }}>
        <div
          style={{
            fontSize: 11,
            fontWeight: 600,
            textTransform: 'uppercase',
            letterSpacing: '.14em',
            color: 'var(--text-brand)',
            marginBottom: 16,
          }}
        >
          Datos personales
        </div>

        {nombreError && (
          <div className="alert alert--error" style={{ marginBottom: 16 }}>
            <div className="alert__body">{nombreError}</div>
          </div>
        )}

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 18 }}>
          <div className="field">
            <label className="field__label" htmlFor="perfil-nombre">
              Nombre completo <span className="req">*</span>
            </label>
            <input
              className="input"
              id="perfil-nombre"
              value={nombre}
              style={nombreCampos.nombre ? { borderColor: 'var(--status-error)' } : undefined}
              onChange={(e) => {
                setNombre(e.target.value);
                if (nombreError) setNombreError(null);
              }}
            />
            {nombreCampos.nombre && (
              <span style={{ fontSize: 11.5, color: 'var(--status-error)' }}>{nombreCampos.nombre}</span>
            )}
          </div>
          <div className="field">
            <label className="field__label">Correo</label>
            <div style={{ fontSize: 13, color: 'var(--text-secondary)', padding: '8px 0' }}>{usuario.email}</div>
          </div>
          <div className="field">
            <label className="field__label">Rol</label>
            <div style={{ fontSize: 13, color: 'var(--text-secondary)', padding: '8px 0' }}>
              {ROL_LABEL[usuario.rol]}
            </div>
          </div>
          <div className="field">
            <label className="field__label">Área</label>
            <div style={{ fontSize: 13, color: 'var(--text-secondary)', padding: '8px 0' }}>{areaNombre}</div>
          </div>
        </div>

        <div style={{ marginTop: 18 }}>
          <button type="button" className="btn btn--accent btn--md" onClick={guardarNombre} disabled={nombreMut.isPending}>
            {nombreMut.isPending ? 'Guardando…' : 'Guardar'}
          </button>
        </div>
      </div>

      <div className="panel-card" style={{ padding: '24px 28px' }}>
        <div
          style={{
            fontSize: 11,
            fontWeight: 600,
            textTransform: 'uppercase',
            letterSpacing: '.14em',
            color: 'var(--text-brand)',
            marginBottom: 16,
          }}
        >
          Contraseña
        </div>

        {esCuentaPassword ? (
          <>
            {passError && (
              <div className="alert alert--error" style={{ marginBottom: 16 }}>
                <div className="alert__body">{passError}</div>
              </div>
            )}
            <div style={{ display: 'grid', gap: 18 }}>
              <div className="field">
                <label className="field__label" htmlFor="perfil-pass-actual">
                  Contraseña actual <span className="req">*</span>
                </label>
                <input
                  className="input"
                  id="perfil-pass-actual"
                  type="password"
                  value={passActual}
                  onChange={(e) => {
                    setPassActual(e.target.value);
                    if (passError) setPassError(null);
                  }}
                />
              </div>
              <div className="field">
                <label className="field__label" htmlFor="perfil-pass-nueva">
                  Nueva contraseña <span className="req">*</span>
                </label>
                <input
                  className="input"
                  id="perfil-pass-nueva"
                  type="password"
                  value={passNueva}
                  onChange={(e) => {
                    setPassNueva(e.target.value);
                    if (passError) setPassError(null);
                  }}
                />
              </div>
              <div className="field">
                <label className="field__label" htmlFor="perfil-pass-confirmar">
                  Confirmar nueva contraseña <span className="req">*</span>
                </label>
                <input
                  className="input"
                  id="perfil-pass-confirmar"
                  type="password"
                  value={passConfirmar}
                  onChange={(e) => {
                    setPassConfirmar(e.target.value);
                    if (passError) setPassError(null);
                  }}
                />
              </div>
            </div>
            <div style={{ marginTop: 18 }}>
              <button
                type="button"
                className="btn btn--accent btn--md"
                onClick={cambiarPasswordSubmit}
                disabled={passMut.isPending}
              >
                {passMut.isPending ? 'Actualizando…' : 'Cambiar contraseña'}
              </button>
            </div>
          </>
        ) : (
          <p style={{ fontSize: 13, color: 'var(--text-secondary)' }}>
            Tu cuenta inicia sesión con Google; gestiona tu contraseña desde tu cuenta de Google.
          </p>
        )}
      </div>
    </div>
  );
}
