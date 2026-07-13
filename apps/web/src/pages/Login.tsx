/**
 * Login real con Firebase Auth (Google + email/password), ADR-002. El resto
 * del flujo (resolver la sesión con GET /me) lo maneja App vía
 * onAuthStateChanged. Layout split (introducción + acceso) solicitado por el
 * stakeholder (bitácora doc 09 §9, 2026-07-10); identidad Participa Juárez.
 */
import { useState } from 'react';
import type { FormEvent } from 'react';
import { api } from '../lib';
import { crearCuentaEmailPassword } from '../lib/firebase';
import { validarRegistro } from '../lib/registro';
import { mensajeError, statusError } from '../components/EstadoHelpers';

interface LoginProps {
  errorAcceso?: string | null;
  onLoginGoogle?: () => Promise<void>;
  onLoginEmailPassword?: (email: string, password: string) => Promise<void>;
}

/** El ciclo de vida real del acuerdo — el motivo de marca del panel de intro. */
const CICLO_DE_VIDA = [
  {
    clase: 'login-lifecycle__punto--proceso',
    estado: 'En proceso',
    desc: 'Cada acuerdo nace en la reunión, con responsable y fecha compromiso.',
  },
  {
    clase: 'login-lifecycle__punto--vencido',
    estado: 'Vencido',
    desc: 'Si la fecha pasa, el sistema lo marca y envía recordatorios por correo.',
  },
  {
    clase: 'login-lifecycle__punto--concluido',
    estado: 'Concluido',
    desc: 'Dirección revisa la evidencia y lo da por concluido.',
  },
] as const;

export function Login({ errorAcceso = null, onLoginGoogle, onLoginEmailPassword }: LoginProps) {
  const [modo, setModo] = useState<'entrar' | 'registro'>('entrar');

  return (
    <div className="login-split">
      <section className="login-brand" aria-label="Acerca del panel">
        <img className="login-brand__logo" src="/assets/logo-horizontal-white.png" alt="Participa Juárez" />
        <h1 className="login-brand__titulo">Los acuerdos no se quedan en la minuta.</h1>
        <p className="login-brand__sub">
          Captura los compromisos de cada reunión, da seguimiento por rol y recibe recordatorios automáticos
          antes de que venzan.
        </p>
        <ol className="login-lifecycle">
          {CICLO_DE_VIDA.map((paso) => (
            <li key={paso.estado} className="login-lifecycle__paso">
              <span className={`login-lifecycle__punto ${paso.clase}`} aria-hidden="true" />
              <div>
                <span className="login-lifecycle__estado">{paso.estado}</span>
                <p className="login-lifecycle__desc">{paso.desc}</p>
              </div>
            </li>
          ))}
        </ol>
      </section>

      <section className="login-panel" aria-label={modo === 'entrar' ? 'Iniciar sesión' : 'Crear cuenta'}>
        <div className="login-card">
          {modo === 'entrar' ? (
            <>
              <h2 className="login-card__titulo">Entrar al panel</h2>
              <p className="login-card__hint">
                Usa tu cuenta de Google del equipo o el correo y contraseña con los que te dieron de alta.
              </p>
              <LoginReal
                errorAcceso={errorAcceso}
                onLoginGoogle={onLoginGoogle!}
                onLoginEmailPassword={onLoginEmailPassword!}
              />
              <p style={{ textAlign: 'center', marginTop: 18 }}>
                <button
                  type="button"
                  className="login-card__link"
                  onClick={() => setModo('registro')}
                >
                  ¿No tienes cuenta? Crear cuenta
                </button>
              </p>
            </>
          ) : (
            <>
              <h2 className="login-card__titulo">Crear cuenta</h2>
              <p className="login-card__hint">
                Tu cuenta quedará pendiente hasta que un administrador te asigne acceso.
              </p>
              <RegistroForm />
              <p style={{ textAlign: 'center', marginTop: 18 }}>
                <button
                  type="button"
                  className="login-card__link"
                  onClick={() => setModo('entrar')}
                >
                  ¿Ya tienes cuenta? Inicia sesión
                </button>
              </p>
            </>
          )}
        </div>
      </section>
    </div>
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
        <button type="submit" className="btn btn--ghost-teal btn--full btn--md" disabled={entrando}>
          {entrando ? 'Entrando…' : 'Entrar con correo'}
        </button>
      </form>
    </>
  );
}

/**
 * Formulario de autorregistro (ADR-006): crea la cuenta en Firebase y luego la
 * fila en el backend (`POST /registro`). Si la cuenta Firebase ya quedó creada
 * pero `registrarme` falla (p. ej. red), se ofrece reintentar solo ese paso —
 * la sesión Firebase sigue activa. Un 409 `cuenta_ya_existe` se trata como
 * éxito silencioso: la fila ya existe y `onAuthStateChanged` (App) resolverá
 * `getMe` para entrar como pendiente.
 */
function RegistroForm() {
  const [nombre, setNombre] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmar, setConfirmar] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [cuentaCreada, setCuentaCreada] = useState(false);

  const registrarme = async () => {
    try {
      await api.registrarme({ nombre: nombre.trim() });
      setError(null);
    } catch (err) {
      if (statusError(err) === 409) {
        // La fila ya existe (cuenta_ya_existe): éxito silencioso, getMe fluye.
        setError(null);
        return;
      }
      setCuentaCreada(true);
      setError(mensajeError(err));
    }
  };

  const enviar = async (e: FormEvent) => {
    e.preventDefault();
    if (enviando) return;
    const invalido = validarRegistro(nombre, email, password, confirmar);
    if (invalido) {
      setError(invalido);
      return;
    }
    setEnviando(true);
    setError(null);
    try {
      await crearCuentaEmailPassword(email.trim(), password);
      await registrarme();
    } catch (err) {
      setError(mensajeError(err));
    } finally {
      setEnviando(false);
    }
  };

  const reintentar = async () => {
    if (enviando) return;
    setEnviando(true);
    try {
      await registrarme();
    } finally {
      setEnviando(false);
    }
  };

  return (
    <>
      {error && (
        <div className="alert alert--error" style={{ marginBottom: 16 }}>
          <div className="alert__body">{error}</div>
        </div>
      )}

      {cuentaCreada ? (
        <button
          type="button"
          className="btn btn--accent btn--full btn--md"
          onClick={() => void reintentar()}
          disabled={enviando}
        >
          {enviando ? 'Reintentando…' : 'Reintentar'}
        </button>
      ) : (
        <form onSubmit={(e) => void enviar(e)}>
          <div className="field" style={{ marginBottom: 12 }}>
            <label className="field__label" htmlFor="registro-nombre">
              Nombre completo
            </label>
            <input
              id="registro-nombre"
              type="text"
              className="input"
              value={nombre}
              onChange={(e) => setNombre(e.target.value)}
              autoComplete="name"
              required
              disabled={enviando}
            />
          </div>
          <div className="field" style={{ marginBottom: 12 }}>
            <label className="field__label" htmlFor="registro-email">
              Correo
            </label>
            <input
              id="registro-email"
              type="email"
              className="input"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              autoComplete="email"
              required
              disabled={enviando}
            />
          </div>
          <div className="field" style={{ marginBottom: 12 }}>
            <label className="field__label" htmlFor="registro-password">
              Contraseña
            </label>
            <input
              id="registro-password"
              type="password"
              className="input"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="new-password"
              required
              disabled={enviando}
            />
          </div>
          <div className="field" style={{ marginBottom: 18 }}>
            <label className="field__label" htmlFor="registro-confirmar">
              Confirmar contraseña
            </label>
            <input
              id="registro-confirmar"
              type="password"
              className="input"
              value={confirmar}
              onChange={(e) => setConfirmar(e.target.value)}
              autoComplete="new-password"
              required
              disabled={enviando}
            />
          </div>
          <button type="submit" className="btn btn--accent btn--full btn--md" disabled={enviando}>
            {enviando ? 'Creando cuenta…' : 'Crear cuenta'}
          </button>
        </form>
      )}
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
