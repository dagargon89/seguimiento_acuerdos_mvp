/**
 * Shell de la aplicación: sesión demo, topbar 1:1 con el demo vanilla,
 * rutas react-router y toast global.
 */
import { useMemo, useState } from 'react';
import { Navigate, NavLink, Route, Routes } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { sesionDemo } from './lib';
import type { Sesion } from './lib';
import { fmtL, hoyISO } from './lib/fechas';
import { ROL_LABEL } from './components/EstadoHelpers';
import { Avatar } from './components/Avatar';
import { SessionContext } from './components/SessionContext';
import { ToastProvider } from './components/Toast';
import { Login } from './pages/Login';
import { Panel } from './pages/Panel';
import { Captura } from './pages/Captura';
import { Recordatorios } from './pages/Recordatorios';
import { Checklist } from './pages/Checklist';
import { Usuarios } from './pages/Usuarios';

export default function App() {
  const [sesion, setSesion] = useState<Sesion | null>(null);
  const queryClient = useQueryClient();

  const contexto = useMemo(
    () => ({
      sesion,
      setSesion,
      logout: () => {
        sesionDemo.logout();
        queryClient.clear();
        setSesion(null);
      },
    }),
    [sesion, queryClient],
  );

  return (
    <SessionContext.Provider value={contexto}>
      <ToastProvider>{sesion ? <Shell sesion={sesion} onLogout={contexto.logout} /> : <Login />}</ToastProvider>
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
