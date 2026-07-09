/**
 * Login demo 1:1 con renderLogin() del demo: selector de cuentas.
 * En producción (USA_MOCK=false) esta misma tarjeta muestra el login real de
 * Firebase Auth (Google + email/password), ADR-002. El resto del flujo
 * (resolver la sesión con GET /me) lo maneja App vía onAuthStateChanged.
 */
import { useState } from 'react';
import type { FormEvent } from 'react';
import { api, sesionDemo, USA_MOCK } from '../lib';
import { ROL_LABEL, mensajeError } from '../components/EstadoHelpers';
import { Avatar } from '../components/Avatar';
import { useSesion } from '../components/SessionContext';
import { useToast } from '../components/Toast';

interface LoginProps {
  errorAcceso?: string | null;
  onLoginGoogle?: () => Promise<void>;
  onLoginEmailPassword?: (email: string, password: string) => Promise<void>;
}

export function Login({ errorAcceso = null, onLoginGoogle, onLoginEmailPassword }: LoginProps) {
  return (
    <div className="login-wrap">
      <div className="login-card">
        <img
          src="/assets/logo-horizontal-color.png"
          alt="Participa Juárez"
          style={{ height: 34, display: 'block', margin: '0 auto 18px' }}
        />
        <h1 style={{ fontFamily: 'var(--font-display)', fontWeight: 500, fontSize: 21, textAlign: 'center', margin: '0 0 6px' }}>
          Panel de seguimiento de acuerdos
        </h1>
        <p style={{ fontSize: 13, color: 'var(--text-secondary)', textAlign: 'center', margin: '0 0 22px' }}>
          Inicia sesión con tu cuenta. Cada rol ve un panel distinto: la dirección ve todo, una coordinación ve su
          área y cada responsable ve solo sus compromisos.
        </p>
        {USA_MOCK ? (
          <LoginDemo />
        ) : (
          <LoginReal
            errorAcceso={errorAcceso}
            onLoginGoogle={onLoginGoogle!}
            onLoginEmailPassword={onLoginEmailPassword!}
          />
        )}
      </div>
    </div>
  );
}

/** Selector de cuentas demo (comportamiento actual, sin cambios). */
function LoginDemo() {
  const { setSesion } = useSesion();
  const { toast } = useToast();
  const [entrando, setEntrando] = useState(false);
  const cuentas = sesionDemo.cuentas();

  const entrar = async (id: number) => {
    if (entrando) return;
    setEntrando(true);
    try {
      sesionDemo.login(id);
      const s = await api.getMe();
      setSesion(s);
    } catch (e) {
      toast(mensajeError(e), 'error');
      setEntrando(false);
    }
  };

  return (
    <>
      {cuentas.map((u) => (
        <button key={u.id} type="button" className="login-user" onClick={() => void entrar(u.id)} disabled={entrando}>
          <Avatar nombre={u.nombre} size="md" />
          <span style={{ flex: 1, minWidth: 0 }}>
            <span style={{ display: 'block', fontSize: 13.5, fontWeight: 600 }}>{u.nombre}</span>
            <span style={{ display: 'block', fontSize: 11.5, color: 'var(--text-muted)' }}>{u.email}</span>
          </span>
          <span className={`rol-chip rol-chip--${u.rol}`}>{ROL_LABEL[u.rol]}</span>
        </button>
      ))}
      <p style={{ fontSize: 11.5, color: 'var(--text-muted)', textAlign: 'center', margin: '16px 0 0' }}>
        Demo: selecciona una cuenta (en producción será Firebase).
      </p>
    </>
  );
}

/** Login real con Firebase Auth: Google (equipo del dominio) + email/password (invitados). */
function LoginReal({
  errorAcceso,
  onLoginGoogle,
  onLoginEmailPassword,
}: {
  errorAcceso: string | null;
  onLoginGoogle: () => Promise<void>;
  onLoginEmailPassword: (email: string, password: string) => Promise<void>;
}) {
  const [entrando, setEntrando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  const conGoogle = async () => {
    if (entrando) return;
    setEntrando(true);
    setError(null);
    try {
      await onLoginGoogle();
    } catch (e) {
      setError(mensajeFirebase(e));
    } finally {
      setEntrando(false);
    }
  };

  const conEmail = async (e: FormEvent) => {
    e.preventDefault();
    if (entrando) return;
    setEntrando(true);
    setError(null);
    try {
      await onLoginEmailPassword(email.trim(), password);
    } catch (err) {
      setError(mensajeFirebase(err));
    } finally {
      setEntrando(false);
    }
  };

  return (
    <>
      {(errorAcceso ?? error) && (
        <div className="alert alert--error" style={{ marginBottom: 16 }}>
          <div className="alert__body">{errorAcceso ?? error}</div>
        </div>
      )}

      <button
        type="button"
        className="btn btn--accent btn--full btn--md"
        onClick={() => void conGoogle()}
        disabled={entrando}
        style={{ marginBottom: 18 }}
      >
        Entrar con Google
      </button>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10, margin: '0 0 18px' }}>
        <span style={{ flex: 1, height: 1, background: 'var(--border-default)' }} />
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>o con correo y contraseña</span>
        <span style={{ flex: 1, height: 1, background: 'var(--border-default)' }} />
      </div>

      <form onSubmit={(e) => void conEmail(e)}>
        <div className="field" style={{ marginBottom: 12 }}>
          <label className="field__label" htmlFor="login-email">
            Correo
          </label>
          <input
            id="login-email"
            type="email"
            className="input"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            autoComplete="email"
            required
            disabled={entrando}
          />
        </div>
        <div className="field" style={{ marginBottom: 18 }}>
          <label className="field__label" htmlFor="login-password">
            Contraseña
          </label>
          <input
            id="login-password"
            type="password"
            className="input"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
            required
            disabled={entrando}
          />
        </div>
        <button type="submit" className="btn btn--ghost btn--full btn--md" disabled={entrando}>
          {entrando ? 'Entrando…' : 'Entrar con correo'}
        </button>
      </form>
    </>
  );
}

/** Traduce los códigos de error del SDK de Firebase a mensajes claros. */
function mensajeFirebase(e: unknown): string {
  const codigo = typeof e === 'object' && e !== null && 'code' in e ? String((e as { code: unknown }).code) : '';
  switch (codigo) {
    case 'auth/popup-closed-by-user':
    case 'auth/cancelled-popup-request':
      return 'Cerraste la ventana de Google antes de terminar. Intenta de nuevo.';
    case 'auth/invalid-credential':
    case 'auth/invalid-email':
    case 'auth/wrong-password':
    case 'auth/user-not-found':
      return 'Correo o contraseña incorrectos.';
    case 'auth/too-many-requests':
      return 'Demasiados intentos. Espera un momento e intenta de nuevo.';
    case 'auth/network-request-failed':
      return 'No hay conexión con el servicio de autenticación. Revisa tu red.';
    default:
      return mensajeError(e);
  }
}
