/**
 * Shell de la aplicación: sesión Firebase, topbar 1:1 con el demo vanilla,
 * rutas react-router y toast global.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { Navigate, NavLink, Route, Routes, useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { api, setTokenProvider } from './lib';
import type { Sesion } from './lib';
import { auth, loginEmailPassword, loginGoogle, logoutFirebase, onAuthStateChanged } from './lib/firebase';
import { fmtL, hoyISO } from './lib/fechas';
import { codigoError, mensajeError, ROL_LABEL, statusError } from './components/EstadoHelpers';
import { Avatar } from './components/Avatar';
import {
  IconoAreas,
  IconoCaptura,
  IconoChecklist,
  IconoColapsar,
  IconoLuna,
  IconoPanel,
  IconoRecordatorios,
  IconoSol,
  IconoUsuarios,
} from './components/Iconos';
import { SessionContext } from './components/SessionContext';
import { ToastProvider, useToast } from './components/Toast';
import { ActualizacionSW } from './components/ActualizacionSW';
import { Login } from './pages/Login';
import { PendienteAprobacion } from './pages/PendienteAprobacion';
import { Panel } from './pages/Panel';
import { Captura } from './pages/Captura';
import { Recordatorios } from './pages/Recordatorios';
import { Checklist } from './pages/Checklist';
import { Usuarios } from './pages/Usuarios';
import { Areas } from './pages/Areas';
import { Perfil } from './pages/Perfil';

// Cableado del ID token de Firebase (ADR-002) a nivel de módulo: queda listo
// ANTES de que cualquier query se dispare, y se vuelve a aplicar cuando Vite
// recarga en caliente `api.real.ts` (el HMR resetea el estado del módulo; si
// esto viviera en un useEffect, no se re-ejecutaría y toda petición fallaría
// con "Configura el proveedor de token…").
setTokenProvider(async () => (await auth.currentUser?.getIdToken()) ?? '');

export default function App() {
  return (
    <ToastProvider>
      <ActualizacionSW />
      <AppContent />
    </ToastProvider>
  );
}

/** Tema Cívica Nocturna: oscuro por defecto, variante clara persistida (1:1 con el prototipo). */
const TEMA_KEY = 'pj-tema';
type Tema = 'dark' | 'light';

function useTema(): { tema: Tema; alternarTema: () => void } {
  const [tema, setTema] = useState<Tema>(() => (localStorage.getItem(TEMA_KEY) === 'light' ? 'light' : 'dark'));

  useEffect(() => {
    document.body.classList.toggle('light', tema === 'light');
    localStorage.setItem(TEMA_KEY, tema);
  }, [tema]);

  const alternarTema = useMemo(() => () => setTema((t) => (t === 'dark' ? 'light' : 'dark')), []);
  return { tema, alternarTema };
}

function AppContent() {
  const [sesion, setSesion] = useState<Sesion | null>(null);
  const [cargandoSesion, setCargandoSesion] = useState(true);
  const [errorAcceso, setErrorAcceso] = useState<string | null>(null);
  const { tema, alternarTema } = useTema();
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

  // Resolución de la sesión con el backend al cambiar el estado de Firebase Auth (ADR-002).
  useEffect(() => {
    const unsubscribe = onAuthStateChanged(auth, (usuarioFirebase) => {
      if (!usuarioFirebase) {
        setSesion(null);
        setCargandoSesion(false);
        return;
      }
      setCargandoSesion(true);

      // Autorregistro con Google (ADR-006): quien entra con Google y todavía no
      // tiene fila en `usuarios` se da de alta solo como `pendiente` (nombre =
      // displayName de Google) para que Dirección lo apruebe desde Usuarios. El
      // alta por email/password la maneja RegistroForm; aquí cubrimos el botón
      // de Google, que antes solo hacía login y dejaba la cuenta sin registrar.
      const esGoogle = usuarioFirebase.providerData.some((p) => p.providerId === 'google.com');

      const fallarAcceso = (e: unknown) => {
        if (statusError(e) === 403) {
          setErrorAcceso('Tu cuenta no tiene acceso al panel. Contacta a Dirección.');
        } else {
          toast(mensajeError(e), 'error');
        }
        void logoutFirebase();
        setSesion(null);
      };

      const cargar = async () => {
        try {
          setErrorAcceso(null);
          setSesion(await api.getMe());
          return;
        } catch (e: unknown) {
          // Solo auto-registramos a cuentas Google sin fila; el resto falla igual que antes.
          if (!esGoogle || codigoError(e) !== 'usuario_no_registrado') {
            fallarAcceso(e);
            return;
          }
        }

        try {
          await api.registrarme({
            nombre: usuarioFirebase.displayName?.trim() || usuarioFirebase.email || 'Usuario',
          });
        } catch (e: unknown) {
          // 409 = la fila ya existe (reintento): seguimos a getMe. Otro error sí es fallo.
          if (statusError(e) !== 409) {
            fallarAcceso(e);
            return;
          }
        }

        try {
          setErrorAcceso(null);
          setSesion(await api.getMe());
        } catch (e: unknown) {
          fallarAcceso(e);
        }
      };

      void cargar().finally(() => setCargandoSesion(false));
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
          <Shell sesion={sesion} tema={tema} onAlternarTema={alternarTema} onLogout={contexto.logout} />
        )
      ) : (
        <Login errorAcceso={errorAcceso} onLoginGoogle={loginGoogle} onLoginEmailPassword={loginEmailPassword} />
      )}
    </SessionContext.Provider>
  );
}

/**
 * Botón de usuario en la topbar: despliega un menú con "Mi perfil" y "Salir".
 * Cierra con clic fuera, Esc o al navegar.
 */
function MenuUsuario({ nombre, rolLabel, onLogout }: { nombre: string; rolLabel: string; onLogout: () => void }) {
  const [abierto, setAbierto] = useState(false);
  const contenedorRef = useRef<HTMLDivElement>(null);
  const navigate = useNavigate();

  useEffect(() => {
    if (!abierto) return;
    const onClickFuera = (e: MouseEvent) => {
      if (contenedorRef.current && !contenedorRef.current.contains(e.target as Node)) setAbierto(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setAbierto(false);
    };
    document.addEventListener('mousedown', onClickFuera);
    window.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClickFuera);
      window.removeEventListener('keydown', onKey);
    };
  }, [abierto]);

  return (
    <div className="user-menu" ref={contenedorRef}>
      <button
        type="button"
        className="topbar__user user-menu__btn"
        aria-haspopup="menu"
        aria-expanded={abierto}
        onClick={() => setAbierto((v) => !v)}
      >
        <Avatar nombre={nombre} size="sm" />
        <span className="topbar__user-info">
          <span className="topbar__user-name">{nombre}</span>
          <span className="topbar__user-rol">{rolLabel}</span>
        </span>
        <span className={`user-menu__caret${abierto ? ' is-abierto' : ''}`} aria-hidden="true">
          ▾
        </span>
      </button>

      {abierto && (
        <div className="user-menu__panel" role="menu">
          <button
            type="button"
            role="menuitem"
            className="user-menu__item"
            onClick={() => {
              setAbierto(false);
              void navigate('/perfil');
            }}
          >
            Mi perfil
          </button>
          <div className="user-menu__sep" aria-hidden="true" />
          <button type="button" role="menuitem" className="user-menu__item user-menu__item--salir" onClick={onLogout}>
            Salir
          </button>
        </div>
      )}
    </div>
  );
}

/** Grupos de navegación del sidebar; "Administración" solo se renderiza para Dirección. */
const NAV_GENERAL = [
  { to: '/panel', label: 'Panel', Icono: IconoPanel },
  { to: '/captura', label: 'Capturar acuerdo', Icono: IconoCaptura },
  { to: '/recordatorios', label: 'Recordatorios', Icono: IconoRecordatorios },
];
const NAV_ADMIN = [
  { to: '/checklist', label: 'Checklist', Icono: IconoChecklist },
  { to: '/usuarios', label: 'Usuarios', Icono: IconoUsuarios },
  { to: '/areas', label: 'Áreas', Icono: IconoAreas },
];

const COLAPSADO_KEY = 'pj-sidebar-colapsado';

function Shell({
  sesion,
  tema,
  onAlternarTema,
  onLogout,
}: {
  sesion: Sesion;
  tema: Tema;
  onAlternarTema: () => void;
  onLogout: () => void;
}) {
  const u = sesion.usuario;
  const esDireccion = u.rol === 'direccion';
  const esCoordinador = u.rol === 'coordinador';
  const puedeChecklist = esDireccion || esCoordinador; // ADR-012: coordinación valida su área
  // Administración: Dirección ve todo; coordinación solo el Checklist (de su área).
  const navAdmin = esDireccion ? NAV_ADMIN : esCoordinador ? NAV_ADMIN.filter((i) => i.to === '/checklist') : [];
  const [menuAbierto, setMenuAbierto] = useState(false);
  const [colapsado, setColapsado] = useState(() => localStorage.getItem(COLAPSADO_KEY) === '1');
  const cerrarMenu = () => setMenuAbierto(false);

  const alternarColapso = () => {
    setColapsado((v) => {
      localStorage.setItem(COLAPSADO_KEY, v ? '0' : '1');
      return !v;
    });
  };

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

  const renderLinks = (items: typeof NAV_GENERAL) =>
    items.map(({ to, label, Icono }) => (
      <NavLink key={to} to={to} className={linkClase} onClick={cerrarMenu} title={colapsado ? label : undefined}>
        <Icono className="sidebar__icono" />
        <span className="sidebar__texto">{label}</span>
      </NavLink>
    ));

  return (
    <div className={`shell${colapsado ? ' shell--colapsado' : ''}`}>
      {menuAbierto && <div className="sidebar__backdrop" onClick={cerrarMenu} aria-hidden="true" />}

      <aside className={`sidebar${menuAbierto ? ' is-abierto' : ''}`}>
        <NavLink to="/panel" className="sidebar__logo-link" onClick={cerrarMenu} title="Panel">
          <img className="sidebar__logo" src="/assets/logo-horizontal-white.png" alt="Participa Juárez" />
          <span className="sidebar__monograma" aria-hidden="true">
            PJ
          </span>
        </NavLink>

        <nav className="sidebar__nav" aria-label="Secciones">
          <div className="sidebar__grupo">
            <div className="sidebar__eyebrow">
              <span className="sidebar__texto">General</span>
            </div>
            {renderLinks(NAV_GENERAL)}
          </div>

          {navAdmin.length > 0 && (
            <div className="sidebar__grupo">
              <div className="sidebar__eyebrow">
                <span className="sidebar__texto">Administración</span>
              </div>
              {renderLinks(navAdmin)}
            </div>
          )}
        </nav>

        <div className="sidebar__hoy">
          <div className="sidebar__hoy-label">Hoy</div>
          <div className="sidebar__hoy-fecha">{fmtL(hoyISO())}</div>
        </div>

        <button
          type="button"
          className="sidebar__colapsar"
          onClick={alternarColapso}
          aria-label={colapsado ? 'Expandir menú' : 'Contraer menú'}
          title={colapsado ? 'Expandir' : 'Contraer'}
        >
          <IconoColapsar invertido={colapsado} className="sidebar__icono" />
          <span className="sidebar__texto">Contraer</span>
        </button>
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
            <button
              type="button"
              className="tema-btn"
              onClick={onAlternarTema}
              title={tema === 'light' ? 'Cambiar a modo oscuro' : 'Cambiar a modo claro'}
              aria-label={tema === 'light' ? 'Cambiar a modo oscuro' : 'Cambiar a modo claro'}
            >
              {tema === 'light' ? <IconoLuna /> : <IconoSol />}
            </button>
            <MenuUsuario nombre={u.nombre} rolLabel={ROL_LABEL[u.rol]} onLogout={onLogout} />
          </div>
        </header>

        <main className="main">
          <Routes>
            <Route path="/panel" element={<Panel />} />
            <Route path="/captura" element={<Captura />} />
            <Route path="/recordatorios" element={<Recordatorios />} />
            <Route path="/checklist" element={puedeChecklist ? <Checklist /> : <Navigate to="/panel" replace />} />
            <Route path="/usuarios" element={esDireccion ? <Usuarios /> : <Navigate to="/panel" replace />} />
            <Route path="/areas" element={esDireccion ? <Areas /> : <Navigate to="/panel" replace />} />
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
