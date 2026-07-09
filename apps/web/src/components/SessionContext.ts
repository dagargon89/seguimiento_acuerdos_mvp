/**
 * Contexto de sesión propio (App lo provee). En mock la sesión se resuelve
 * con sesionDemo.login + api.getMe(); en Fase 2 lo hará Firebase Auth.
 */
import { createContext, useContext } from 'react';
import type { Sesion } from '../lib';

interface SessionContextValue {
  sesion: Sesion | null;
  setSesion: (s: Sesion | null) => void;
  logout: () => void;
}

export const SessionContext = createContext<SessionContextValue>({
  sesion: null,
  setSesion: () => undefined,
  logout: () => undefined,
});

export function useSesion(): SessionContextValue {
  return useContext(SessionContext);
}
