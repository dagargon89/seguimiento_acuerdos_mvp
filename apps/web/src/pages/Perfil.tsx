/**
 * Perfil del usuario en sesión: datos personales (vía API, doc 05 PATCH /me)
 * y cambio de contraseña (vía Firebase, solo cuentas email/password).
 * Reutiliza el patrón de mutación/toast/tokens de Usuarios.tsx.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib';
import { ROL_LABEL, camposError, mensajeError } from '../components/EstadoHelpers';
import { Avatar } from '../components/Avatar';
import { useSesion } from '../components/SessionContext';
import { useToast } from '../components/Toast';
import { cambiarPassword, proveedorEsPassword } from '../lib/firebase';
import { validarCambioPassword } from '../lib/perfil';
import { AVATAR_COLOR_DEFAULT, AVATAR_PRESETS, esColorHexValido } from '../lib/avatarColores';

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

  // Color del avatar: preview local inmediato; se persiste vía PATCH /me.
  const [colorSel, setColorSel] = useState<string | null>(usuario?.avatar_color ?? null);

  const colorMut = useMutation({
    mutationFn: (color: string | null) => api.editarMiPerfil({ avatar_color: color }),
    onSuccess: (usuarioActualizado) => {
      if (sesion) setSesion({ ...sesion, usuario: usuarioActualizado });
      setColorSel(usuarioActualizado.avatar_color ?? null);
      void queryClient.invalidateQueries({ queryKey: ['me'] });
      void queryClient.invalidateQueries({ queryKey: ['usuarios'] });
      toast('El color de tu avatar se actualizó.');
    },
    onError: (e) => {
      setColorSel(usuario?.avatar_color ?? null); // revertir preview
      toast(mensajeError(e), 'error');
    },
  });

  const elegirColor = (color: string | null) => {
    setColorSel(color); // preview inmediato
    colorMut.mutate(color);
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
    <div style={{ maxWidth: 640, margin: '0 auto' }}>
      <div className="anim-in" style={{ marginBottom: 28 }}>
        <div className="section-header__eyebrow">Tu cuenta</div>
        <h2 className="section-header__title">Mi perfil</h2>
      </div>

      <div className="panel-card anim-in anim-in--1" style={{ padding: '26px 30px', marginBottom: 20 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 22 }}>
          <Avatar nombre={usuario.nombre} size="xl" color={colorSel} />
          <div>
            <div style={{ fontSize: 16, fontWeight: 600 }}>{usuario.nombre}</div>
            <div style={{ fontSize: 12.5, color: 'var(--muted)' }}>{usuario.email}</div>
          </div>
          <span className={`rol-chip rol-chip--${usuario.rol}`} style={{ marginLeft: 'auto' }}>
            {ROL_LABEL[usuario.rol]}
          </span>
        </div>

        <div
          style={{
            fontSize: 10.5,
            fontWeight: 600,
            textTransform: 'uppercase',
            letterSpacing: '.1em',
            color: 'var(--muted)',
            borderTop: '1px solid var(--border)',
            paddingTop: 20,
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

        <div className="grid grid-cols-1 sm:grid-cols-[1fr_1fr] gap-[18px]">
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

        <div
          style={{
            fontSize: 10.5,
            fontWeight: 600,
            textTransform: 'uppercase',
            letterSpacing: '.1em',
            color: 'var(--muted)',
            borderTop: '1px solid var(--border)',
            paddingTop: 20,
            marginTop: 24,
            marginBottom: 6,
          }}
        >
          Color de tu avatar
        </div>
        <p style={{ margin: '0 0 14px', fontSize: 12.5, lineHeight: 1.6, color: 'var(--text-muted)' }}>
          Elige un color para tus iniciales. Se verá en todo el panel, también para el resto del equipo.
        </p>

        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
          {AVATAR_PRESETS.map((c) => {
            const activo = (colorSel ?? '').toLowerCase() === c.hex.toLowerCase();
            return (
              <button
                key={c.hex}
                type="button"
                title={c.nombre}
                aria-label={`Color ${c.nombre}`}
                aria-pressed={activo}
                disabled={colorMut.isPending}
                onClick={() => elegirColor(c.hex)}
                style={{
                  width: 30,
                  height: 30,
                  borderRadius: '50%',
                  background: c.hex,
                  cursor: 'pointer',
                  border: activo ? '2px solid var(--text)' : '2px solid transparent',
                  boxShadow: activo ? '0 0 0 2px var(--surface), 0 0 0 3px var(--text)' : 'none',
                }}
              />
            );
          })}

          {/* Color personalizado */}
          <label
            title="Color personalizado"
            style={{
              position: 'relative',
              width: 30,
              height: 30,
              borderRadius: '50%',
              cursor: 'pointer',
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              border: '1px dashed var(--border)',
              overflow: 'hidden',
              background:
                'conic-gradient(from 0deg, #e5606b, #d99a2b, #37b24d, #2fbfa5, #5b9df5, #a878e6, #e5606b)',
            }}
          >
            <input
              type="color"
              value={colorSel && esColorHexValido(colorSel) ? colorSel : AVATAR_COLOR_DEFAULT}
              disabled={colorMut.isPending}
              onChange={(e) => elegirColor(e.target.value)}
              style={{ position: 'absolute', inset: 0, opacity: 0, cursor: 'pointer', width: '100%', height: '100%' }}
              aria-label="Elegir color personalizado"
            />
          </label>

          <button
            type="button"
            className="btn btn--ghost btn--sm"
            disabled={colorMut.isPending || colorSel === null}
            onClick={() => elegirColor(null)}
            style={{ marginLeft: 4 }}
          >
            Restablecer
          </button>
        </div>
      </div>

      <div className="panel-card anim-in anim-in--2" style={{ padding: '26px 30px' }}>
        <div
          style={{
            fontSize: 10.5,
            fontWeight: 600,
            textTransform: 'uppercase',
            letterSpacing: '.1em',
            color: 'var(--muted)',
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
