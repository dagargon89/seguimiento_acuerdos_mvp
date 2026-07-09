/**
 * Login demo 1:1 con renderLogin() del demo: selector de cuentas.
 * En producción esta pantalla se sustituye por Firebase Auth.
 */
import { useState } from 'react';
import { api, sesionDemo } from '../lib';
import { ROL_LABEL, mensajeError } from '../components/EstadoHelpers';
import { Avatar } from '../components/Avatar';
import { useSesion } from '../components/SessionContext';
import { useToast } from '../components/Toast';

export function Login() {
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
      </div>
    </div>
  );
}
