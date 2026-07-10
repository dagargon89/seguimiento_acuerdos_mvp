/**
 * Contexto de sesión propio (App lo provee). La sesión se resuelve con
 * Firebase Auth (onAuthStateChanged) + api.getMe() (ADR-002).
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
