/**
 * Shell de la aplicación: sesión demo, topbar 1:1 con el demo vanilla,
 * rutas react-router y toast global.
 */
import { useEffect, useMemo, useState } from 'react';
import { Navigate, NavLink, Route, Routes } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api, sesionDemo, setTokenProvider, USA_MOCK } from './lib';
import type { Sesion } from './lib';
import { auth, loginEmailPassword, loginGoogle, logoutFirebase, onAuthStateChanged } from './lib/firebase';
import { fmtL, hoyISO } from './lib/fechas';
import { mensajeError, ROL_LABEL, statusError } from './components/EstadoHelpers';
import { Avatar } from './components/Avatar';
import { SessionContext } from './components/SessionContext';
import { ToastProvider, useToast } from './components/Toast';
import { Login } from './pages/Login';
import { Panel } from './pages/Panel';
import { Captura } from './pages/Captura';
import { Recordatorios } from './pages/Recordatorios';
import { Checklist } from './pages/Checklist';
import { Usuarios } from './pages/Usuarios';

export default function App() {
  return (
    <ToastProvider>
      <AppContent />
    </ToastProvider>
  );
}

function AppContent() {
  const [sesion, setSesion] = useState<Sesion | null>(null);
  const [cargandoSesion, setCargandoSesion] = useState(!USA_MOCK);
  const [errorAcceso, setErrorAcceso] = useState<string | null>(null);
  const queryClient = useQueryClient();
  const { toast } = useToast();

  const logout = useMemo(
    () => () => {
      queryClient.clear();
      setSesion(null);
      if (USA_MOCK) {
        sesionDemo.logout();
      } else {
        void logoutFirebase();
      }
    },
    [queryClient],
  );

  // Cableado de Firebase Auth (ADR-002): solo activo cuando USA_MOCK === false.
  useEffect(() => {
    if (USA_MOCK) return;

    setTokenProvider(async () => (await auth.currentUser?.getIdToken()) ?? '');

    const unsubscribe = onAuthStateChanged(auth, (usuarioFirebase) => {
      if (!usuarioFirebase) {
        setSesion(null);
        setCargandoSesion(false);
        return;
      }
      setCargandoSesion(true);
      api
        .getMe()
        .then((s) => {
          setErrorAcceso(null);
          setSesion(s);
        })
        .catch((e: unknown) => {
          if (statusError(e) === 403) {
            setErrorAcceso('Tu cuenta no tiene acceso al panel. Contacta a Dirección.');
          } else {
            toast(mensajeError(e), 'error');
          }
          void logoutFirebase();
          setSesion(null);
        })
        .finally(() => setCargandoSesion(false));
    });

    return unsubscribe;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const contexto = useMemo(() => ({ sesion, setSesion, logout }), [sesion, logout]);

  if (!USA_MOCK && cargandoSesion) {
    return (
      <SessionContext.Provider value={contexto}>
        <div className="login-wrap" />
      </SessionContext.Provider>
    );
  }

  return (
    <SessionContext.Provider value={contexto}>
      {sesion ? (
        <Shell sesion={sesion} onLogout={contexto.logout} />
      ) : (
        <Login errorAcceso={errorAcceso} onLoginGoogle={loginGoogle} onLoginEmailPassword={loginEmailPassword} />
      )}
    </SessionContext.Provider>
  );
}

function Shell({ sesion, onLogout }: { sesion: Sesion; onLogout: () => void }) {
  const u = sesion.usuario;
  const esDireccion = u.rol === 'direccion';

  const tabs = [
    { to: '/panel', label: 'Panel' },
    { to: '/captura', label: 'Capturar acuerdo' },
    { to: '/recordatorios', label: 'Recordatorios' },
    ...(esDireccion
      ? [
          { to: '/checklist', label: 'Checklist' },
          { to: '/usuarios', label: 'Usuarios' },
        ]
      : []),
  ];

  return (
    <>
      <header className="topbar">
        <img className="topbar__logo" src="/assets/logo-horizontal-white.png" alt="Participa Juárez" />
        <div className="topbar__divider" />
        <div className="topbar__title">Panel de seguimiento de acuerdos</div>
        <div className="topbar__right">
          <div className="topbar__date">Hoy · {fmtL(hoyISO())}</div>
          <nav className="topbar__nav" aria-label="Secciones">
            {tabs.map((t) => (
              <NavLink key={t.to} to={t.to} className={({ isActive }) => `tab-btn${isActive ? ' is-active' : ''}`}>
                {t.label}
              </NavLink>
            ))}
          </nav>
          <div className="topbar__user">
            <Avatar nombre={u.nombre} size="sm" />
            <span className="topbar__user-info">
              <span className="topbar__user-name">{u.nombre}</span>
              <span className="topbar__user-rol">{ROL_LABEL[u.rol]}</span>
            </span>
            <button type="button" className="topbar__salir" onClick={onLogout}>
              Salir
            </button>
          </div>
        </div>
      </header>
      <main className="main">
        <Routes>
          <Route path="/panel" element={<Panel />} />
          <Route path="/captura" element={<Captura />} />
          <Route path="/recordatorios" element={<Recordatorios />} />
          <Route path="/checklist" element={esDireccion ? <Checklist /> : <Navigate to="/panel" replace />} />
          <Route path="/usuarios" element={esDireccion ? <Usuarios /> : <Navigate to="/panel" replace />} />
          <Route path="*" element={<Navigate to="/panel" replace />} />
        </Routes>
      </main>
      <footer className="footer">
        Demo con datos ficticios · Sesión y permisos simulados por el mock del contrato · Panel de seguimiento de
        acuerdos · Participa Juárez
      </footer>
    </>
  );
}
