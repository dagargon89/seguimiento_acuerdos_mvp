/**
 * Shell de la aplicación: sesión Firebase, topbar 1:1 con el demo vanilla,
 * rutas react-router y toast global.
 */
import { useEffect, useMemo, useState } from 'react';
import { Navigate, NavLink, Route, Routes } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api, setTokenProvider } from './lib';
import type { Sesion } from './lib';
import { auth, loginEmailPassword, loginGoogle, logoutFirebase, onAuthStateChanged } from './lib/firebase';
import { fmtL, hoyISO } from './lib/fechas';
import { mensajeError, ROL_LABEL, statusError } from './components/EstadoHelpers';
import { Avatar } from './components/Avatar';
import { SessionContext } from './components/SessionContext';
import { ToastProvider, useToast } from './components/Toast';
import { Login } from './pages/Login';
import { PendienteAprobacion } from './pages/PendienteAprobacion';
import { Panel } from './pages/Panel';
import { Captura } from './pages/Captura';
import { Recordatorios } from './pages/Recordatorios';
import { Checklist } from './pages/Checklist';
import { Usuarios } from './pages/Usuarios';
import { Perfil } from './pages/Perfil';

export default function App() {
  return (
    <ToastProvider>
      <AppContent />
    </ToastProvider>
  );
}

function AppContent() {
  const [sesion, setSesion] = useState<Sesion | null>(null);
  const [cargandoSesion, setCargandoSesion] = useState(true);
  const [errorAcceso, setErrorAcceso] = useState<string | null>(null);
  const queryClient = useQueryClient();
  const { toast } = useToast();

  const logout = useMemo(
    () => () => {
      queryClient.clear();
      setSesion(null);
      void logoutFirebase();
    },
    [queryClient],
  );

  // Cableado de Firebase Auth (ADR-002).
  useEffect(() => {
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

  if (cargandoSesion) {
    return (
      <SessionContext.Provider value={contexto}>
        <div className="login-splash" />
      </SessionContext.Provider>
    );
  }

  return (
    <SessionContext.Provider value={contexto}>
      {sesion ? (
        sesion.usuario.rol === 'pendiente' ? (
          <PendienteAprobacion onLogout={contexto.logout} />
        ) : (
          <Shell sesion={sesion} onLogout={contexto.logout} />
        )
      ) : (
        <Login errorAcceso={errorAcceso} onLoginGoogle={loginGoogle} onLoginEmailPassword={loginEmailPassword} />
      )}
    </SessionContext.Provider>
  );
}

/** Grupos de navegación del sidebar; "Administración" solo se renderiza para Dirección. */
const NAV_GENERAL = [
  { to: '/panel', label: 'Panel' },
  { to: '/captura', label: 'Capturar acuerdo' },
  { to: '/recordatorios', label: 'Recordatorios' },
];
const NAV_ADMIN = [
  { to: '/checklist', label: 'Checklist' },
  { to: '/usuarios', label: 'Usuarios' },
];

function Shell({ sesion, onLogout }: { sesion: Sesion; onLogout: () => void }) {
  const u = sesion.usuario;
  const esDireccion = u.rol === 'direccion';
  const [menuAbierto, setMenuAbierto] = useState(false);
  const cerrarMenu = () => setMenuAbierto(false);

  // Cierre del menú móvil con Esc.
  useEffect(() => {
    if (!menuAbierto) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setMenuAbierto(false);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [menuAbierto]);

  const linkClase = ({ isActive }: { isActive: boolean }) => `sidebar__link${isActive ? ' is-active' : ''}`;

  return (
    <div className="shell">
      {menuAbierto && <div className="sidebar__backdrop" onClick={cerrarMenu} aria-hidden="true" />}

      <aside className={`sidebar${menuAbierto ? ' is-abierto' : ''}`}>
        <NavLink to="/panel" className="sidebar__logo-link" onClick={cerrarMenu}>
          <img className="sidebar__logo" src="/assets/logo-horizontal-white.png" alt="Participa Juárez" />
        </NavLink>

        <nav className="sidebar__nav" aria-label="Secciones">
          <div className="sidebar__grupo">
            <div className="sidebar__eyebrow">General</div>
            {NAV_GENERAL.map((t) => (
              <NavLink key={t.to} to={t.to} className={linkClase} onClick={cerrarMenu}>
                {t.label}
              </NavLink>
            ))}
          </div>

          {esDireccion && (
            <div className="sidebar__grupo">
              <div className="sidebar__eyebrow">Administración</div>
              {NAV_ADMIN.map((t) => (
                <NavLink key={t.to} to={t.to} className={linkClase} onClick={cerrarMenu}>
                  {t.label}
                </NavLink>
              ))}
            </div>
          )}
        </nav>
      </aside>

      <div className="shell__contenido">
        <header className="topbar">
          <button
            type="button"
            className="topbar__menu-btn"
            aria-label={menuAbierto ? 'Cerrar menú' : 'Abrir menú'}
            aria-expanded={menuAbierto}
            onClick={() => setMenuAbierto((v) => !v)}
          >
            ☰
          </button>
          <div className="topbar__title">Panel de seguimiento de acuerdos</div>
          <div className="topbar__right">
            <div className="topbar__date">Hoy · {fmtL(hoyISO())}</div>
            <div className="topbar__user">
              <NavLink
                to="/perfil"
                className="topbar__user"
                style={{ textDecoration: 'none', padding: 0, margin: 0 }}
              >
                <Avatar nombre={u.nombre} size="sm" />
                <span className="topbar__user-info">
                  <span className="topbar__user-name">{u.nombre}</span>
                  <span className="topbar__user-rol">{ROL_LABEL[u.rol]}</span>
                </span>
              </NavLink>
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
            <Route path="/perfil" element={<Perfil />} />
            <Route path="*" element={<Navigate to="/panel" replace />} />
          </Routes>
        </main>

        <footer className="footer">
          Panel de seguimiento de acuerdos · Participa Juárez
        </footer>
      </div>
    </div>
  );
}
